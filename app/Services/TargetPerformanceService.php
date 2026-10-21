<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\GlobalSetting;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TargetPerformanceService
{
    /**
     * Metrics shown in the email, in display order. Keys match _targetKeys.metrics
     * (revenue is handled separately because its target is tier-specific).
     */
    private const METRIC_LABELS = [
        'calls' => 'Calls',
        'viewingsMeetings' => 'Viewings / Meetings',
        'inquiriesReceived' => 'Inquiries Received',
        'tasksCompleted' => 'Tasks Completed',
        'dealsSales' => 'Deals (Sales)',
        'dealsLeasing' => 'Deals (Leasing)',
        'offPlan' => 'Off-Plan',
        'listingsForSale60d' => 'Listings for Sale',
        'listingsForLease30d' => 'Listings for Lease',
        'revenue' => 'Revenue',
    ];

    /** agentType => the revenue target key inside _targetKeys.metrics */
    private const REVENUE_TARGET_KEY = [
        'agent' => 'revenueAgent',
        'seniorAgent' => 'revenueSeniorAgent',
        'leadAgent' => 'revenueLeadAgent',
    ];

    private const STATUS_LABELS = [
        'green' => 'Strong',
        'amber' => 'Average',
        'red' => 'Weak',
    ];

    /** _targetKeys.targetType => label used in the email subject/body. */
    private const PERIOD_TYPE_LABELS = [
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'biAnnual' => 'Bi-Annual',
    ];

    private const BAND_COLORS = [
        'green' => '#16a34a',
        'amber' => '#d97706',
        'red' => '#dc2626',
    ];

    public function __construct(
        protected DashboardService $dashboardService,
        protected MailService $mailService,
        protected NotificationService $notificationService,
    ) {}

    /**
     * If today is the first day of the configured target period, email each agent
     * their previous-period performance (when an emailAlertWhenRed metric is red)
     * and push in-app alerts for red metrics flagged pushNotificationWhenRed.
     *
     * @param  string|null  $asOf  treat this date as "today" when resolving the period (testing)
     * @param  bool  $dryRun  evaluate and report only — send no email and write no notification
     * @param  string|null  $testEmail  route every email here instead of the real recipients;
     *                                  in-app notifications are skipped, since this is a test send
     * @param  int|null  $limit  cap how many agents are evaluated, to keep a test send small
     */
    public function sendForDuePeriod(
        ?string $asOf = null,
        bool $dryRun = false,
        ?string $testEmail = null,
        ?int $limit = null,
    ): array {
        $raw = GlobalSetting::where('settingKey', Constants::GLOBAL_SETTINGS['targetKeys'])->value('settingValue');
        $config = $raw ? json_decode($raw, true) : null;

        if (! is_array($config) || empty($config['metrics'])) {
            return ['skipped' => 'no target configuration'];
        }

        // A test send must never write in-app notifications or broadcast to real users.
        $suppressNotifications = $dryRun || $testEmail !== null;

        $targetType = $config['targetType'] ?? 'weekly';
        $period = $this->resolvePeriod($targetType, $asOf);

        if (! $period) {
            $when = $asOf !== null ? $asOf : 'today';

            return ['skipped' => "{$when} is not the first day of the {$targetType} period"];
        }

        $periodType = self::PERIOD_TYPE_LABELS[$targetType] ?? ucfirst($targetType);

        $metrics = $config['metrics'];
        $trafficLight = $config['trafficLight'] ?? [];

        $agents = User::whereIn('agentType', array_keys(Constants::AGENT_TYPE))
            ->where('status', Constants::USER_STATUS['active'])
            ->whereNotNull('email')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get(['id', 'name', 'email', 'agentType']);

        $emailed = 0;
        $pushed = 0;

        // Agents alerted this run, kept so managers receive one consolidated digest.
        $managerDigest = [];

        foreach ($agents as $agent) {
            $actuals = $this->dashboardService->agentTargetActuals($agent->id, $period['start'], $period['end']);
            $rows = $this->buildMetricRows($agent, $metrics, $actuals, $targetType, $trafficLight);

            if (empty($rows)) {
                continue;
            }

            $shouldEmail = false;
            // Red metrics this agent was actually alerted about (flagged for email or push).
            $alertedRedRows = [];

            foreach ($rows as $row) {
                if ($row['band'] !== 'red') {
                    continue;
                }

                if ($row['emailAlertWhenRed'] || $row['pushNotificationWhenRed']) {
                    $alertedRedRows[] = $row;
                }

                if ($row['emailAlertWhenRed']) {
                    $shouldEmail = true;
                }

                if ($row['pushNotificationWhenRed']) {
                    if ($suppressNotifications) {
                        $pushed++;

                        continue;
                    }

                    $this->notificationService->notifyUser(
                        userId: $agent->id,
                        actorId: $agent->id,
                        type: Constants::NOTIFICATION_TYPES['target_alert'],
                        title: "Performance alert: {$row['metric']} ({$period['label']})",
                        message: sprintf(
                            '%s for %s: target %s, achieved %s (%d%%).',
                            $row['metric'],
                            $period['label'],
                            $row['target_display'],
                            $row['actual_display'],
                            $row['pct'],
                        ),
                        extraData: [
                            'metric' => $row['key'],
                            'period' => $period['label'],
                            'target' => $row['target'],
                            'actual' => $row['actual'],
                            'pct' => $row['pct'],
                        ],
                    );
                    $pushed++;
                }
            }

            // Any agent with at least one alerted red metric is rolled into the manager digest.
            if (! empty($alertedRedRows)) {
                $managerDigest[] = [
                    'name' => ucwords($agent->name),
                    'rows' => $alertedRedRows,
                ];
            }

            if (! $shouldEmail) {
                continue;
            }

            if ($dryRun) {
                $emailed++;

                continue;
            }

            try {
                $this->mailService->sendEmail(
                    Constants::EMAIL_SLUGS['performance']['target_summary'],
                    $testEmail ?? $agent->email,
                    [
                        'agent_name' => ucwords($agent->name),
                        'period_type' => $periodType,
                        'period_label' => $period['label'],
                        'period_range' => $period['range'],
                        'performance_table' => $this->buildTable($rows),
                        'company_name' => getCompanyName(),
                    ],
                );
                $emailed++;
            } catch (\Throwable $e) {
                Log::error('TargetPerformanceService : sendEmail : '.$e->getMessage());
            }
        }

        $managersNotified = $this->notifyManagers($managerDigest, $period, $periodType, $dryRun, $testEmail, $suppressNotifications, $limit);

        return [
            'period' => $period['label'],
            'periodType' => $periodType,
            'range' => $period['range'],
            'emailed' => $emailed,
            'pushed' => $pushed,
            'notificationsSuppressed' => $suppressNotifications,
            'managersNotified' => $managersNotified,
            // Per-agent breakdown, so a dry run can be inspected without sending anything.
            'digest' => $managerDigest,
        ];
    }

    /**
     * Send every active manager a single consolidated digest (one in-CRM
     * notification + one email) listing the agents who missed KPI this period.
     *
     * @param  array<int, array{name: string, rows: array}>  $digest
     * @param  string  $periodType  display label for the target period, e.g. "Bi-Annual"
     * @return int number of managers notified
     */
    private function notifyManagers(
        array $digest,
        array $period,
        string $periodType,
        bool $dryRun = false,
        ?string $testEmail = null,
        bool $suppressNotifications = false,
        ?int $limit = null,
    ): int {
        if (empty($digest)) {
            return 0;
        }

        $managerRoleIds = Role::where('name', Constants::MANAGER)->pluck('id');

        $managers = User::whereIn('roleId', $managerRoleIds)
            ->where('status', Constants::USER_STATUS_ACTIVE)
            ->whereNotNull('email')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get(['id', 'name', 'email']);

        if ($managers->isEmpty()) {
            return 0;
        }

        $agentCount = count($digest);
        $names = array_column($digest, 'name');
        $summary = $this->summariseNames($names);
        $table = $this->buildManagerTable($digest);
        $notified = 0;

        foreach ($managers as $manager) {
            if (! $suppressNotifications) {
                $this->notificationService->notifyUser(
                    userId: $manager->id,
                    actorId: $manager->id,
                    type: Constants::NOTIFICATION_TYPES['target_alert_manager'],
                    title: sprintf('KPI alert: %d agent(s) below target (%s)', $agentCount, $period['label']),
                    message: sprintf('%s missed one or more KPIs for %s.', $summary, $period['label']),
                    extraData: [
                        'period' => $period['label'],
                        'agentCount' => $agentCount,
                        'agents' => $names,
                    ],
                );
            }

            if ($dryRun) {
                $notified++;

                continue;
            }

            try {
                $this->mailService->sendEmail(
                    Constants::EMAIL_SLUGS['performance']['manager_summary'],
                    $testEmail ?? $manager->email,
                    [
                        'manager_name' => ucwords($manager->name),
                        'period_type' => $periodType,
                        'period_label' => $period['label'],
                        'period_range' => $period['range'],
                        'agent_count' => (string) $agentCount,
                        'performance_table' => $table,
                        'company_name' => getCompanyName(),
                    ],
                );
                $notified++;
            } catch (\Throwable $e) {
                Log::error('TargetPerformanceService : notifyManagers : '.$e->getMessage());
            }
        }

        return $notified;
    }

    /**
     * Human-readable list of agent names, e.g. "A, B and C" or "A, B and 3 others".
     *
     * @param  array<int, string>  $names
     */
    private function summariseNames(array $names): string
    {
        $count = count($names);

        if ($count <= 3) {
            if ($count === 1) {
                return $names[0];
            }

            return implode(', ', array_slice($names, 0, -1)).' and '.end($names);
        }

        return implode(', ', array_slice($names, 0, 2)).' and '.($count - 2).' others';
    }

    /**
     * Build one row per monitored metric with a target for $targetType, evaluating its status.
     *
     * A metric with no target for the active period is left out by design: biAnnual
     * targets are only configured on the revenue tiers, so a bi-annual run yields a
     * revenue-only summary rather than rows with a zero target.
     */
    private function buildMetricRows(User $agent, array $metrics, array $actuals, string $targetType, array $trafficLight): array
    {
        $rows = [];

        foreach (self::METRIC_LABELS as $key => $label) {
            $configKey = $key === 'revenue'
                ? (self::REVENUE_TARGET_KEY[$agent->agentType] ?? 'revenueAgent')
                : $key;

            $cfg = $metrics[$configKey] ?? null;

            if (! is_array($cfg) || empty($cfg['enableMonitoring'])) {
                continue;
            }

            $target = (float) ($cfg[$targetType] ?? 0);

            if ($target <= 0) {
                continue;
            }

            $actual = (float) ($actuals[$key] ?? 0);
            $status = $this->evaluate($actual, $target, $trafficLight);
            $isRevenue = $key === 'revenue';

            $rows[] = [
                'key' => $key,
                'metric' => $label,
                'target' => $target,
                'actual' => $actual,
                'target_display' => $isRevenue ? 'AED '.number_format($target) : number_format($target),
                'actual_display' => $isRevenue ? 'AED '.number_format($actual) : number_format($actual),
                'pct' => $status['pct'],
                'band' => $status['band'],
                'label' => $status['label'],
                'emailAlertWhenRed' => (bool) ($cfg['emailAlertWhenRed'] ?? false),
                'pushNotificationWhenRed' => (bool) ($cfg['pushNotificationWhenRed'] ?? false),
            ];
        }

        return $rows;
    }

    private function evaluate(float $actual, float $target, array $trafficLight): array
    {
        $pct = $target > 0 ? (int) round($actual / $target * 100) : 0;

        $greenMin = (int) ($trafficLight['green']['min'] ?? 100);
        $amberMin = (int) ($trafficLight['amber']['min'] ?? 50);

        $band = match (true) {
            $pct >= $greenMin => 'green',
            $pct >= $amberMin => 'amber',
            default => 'red',
        };

        return [
            'pct' => $pct,
            'band' => $band,
            'label' => self::STATUS_LABELS[$band],
        ];
    }

    /**
     * Resolve the previous period to measure, only when today is its first day.
     *
     * @param  string|null  $asOf  stand-in for today, so a period boundary can be tested off-cycle
     */
    private function resolvePeriod(string $targetType, ?string $asOf = null): ?array
    {
        $today = ($asOf !== null ? Carbon::parse($asOf, appTimezone()) : Carbon::now(appTimezone()))->startOfDay();

        return match ($targetType) {
            'weekly' => $today->isMonday()
                ? $this->range(
                    $today->copy()->subWeek()->startOfWeek(),
                    $today->copy()->subWeek()->endOfWeek(),
                    'last week',
                )
                : null,
            'monthly' => $today->day === 1
                ? $this->range(
                    $today->copy()->subMonthNoOverflow()->startOfMonth(),
                    $today->copy()->subMonthNoOverflow()->endOfMonth(),
                    $today->copy()->subMonthNoOverflow()->format('F Y'),
                )
                : null,
            'quarterly' => ($today->day === 1 && in_array($today->month, [1, 4, 7, 10], true))
                ? $this->range(
                    $today->copy()->subQuarter()->startOfQuarter(),
                    $today->copy()->subQuarter()->endOfQuarter(),
                    'Q'.$today->copy()->subQuarter()->quarter.' '.$today->copy()->subQuarter()->year,
                )
                : null,
            // Halves run Jan-Jun (H1) and Jul-Dec (H2), so the previous half is
            // always the six months ending yesterday when today is 1 Jan / 1 Jul.
            'biAnnual' => ($today->day === 1 && in_array($today->month, [1, 7], true))
                ? $this->range(
                    $today->copy()->subMonthsNoOverflow(6),
                    $today->copy()->subDay(),
                    $today->month === 1
                        ? 'H2 '.($today->year - 1)
                        : 'H1 '.$today->year,
                )
                : null,
            default => null,
        };
    }

    private function range(Carbon $start, Carbon $end, string $label): array
    {
        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'label' => $label,
            // Spelled-out window, so a long period like "H1 2026" is unambiguous in the email.
            'range' => $start->format('d M Y').' - '.$end->format('d M Y'),
        ];
    }

    private function buildTable(array $rows): string
    {
        $body = '';

        foreach ($rows as $row) {
            $color = self::BAND_COLORS[$row['band']] ?? '#374151';
            $body .= sprintf(
                '<tr>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb; text-align:right;">%s</td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb; text-align:right;">%s</td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb; text-align:right;">%d%%</td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb; font-weight:600; color:%s;">%s</td>'
                .'</tr>',
                e($row['metric']),
                e($row['target_display']),
                e($row['actual_display']),
                $row['pct'],
                $color,
                e($row['label']),
            );
        }

        return '<table style="width:100%; border-collapse:collapse; margin:20px 0; font-size:14px;">'
            .'<thead><tr style="background-color:#00002a; color:#ffffff;">'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Metric</th>'
            .'<th style="padding:10px 12px; text-align:right; border:1px solid #e5e7eb;">Target</th>'
            .'<th style="padding:10px 12px; text-align:right; border:1px solid #e5e7eb;">Achieved</th>'
            .'<th style="padding:10px 12px; text-align:right; border:1px solid #e5e7eb;">%</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Status</th>'
            .'</tr></thead>'
            .'<tbody>'.$body.'</tbody></table>';
    }

    /**
     * Digest table for managers: same metric breakdown as the agent email, with a
     * leading Agent column. Each agent's name spans its red metric rows.
     *
     * @param  array<int, array{name: string, rows: array}>  $digest
     */
    private function buildManagerTable(array $digest): string
    {
        $body = '';

        foreach ($digest as $entry) {
            $rows = $entry['rows'];
            $rowSpan = count($rows);
            $first = true;

            foreach ($rows as $row) {
                $color = self::BAND_COLORS[$row['band']] ?? '#374151';

                if ($first) {
                    $body .= sprintf(
                        '<tr><td rowspan="%d" style="padding:10px 12px; border:1px solid #e5e7eb; font-weight:600; vertical-align:top;">%s</td>',
                        $rowSpan,
                        e($entry['name']),
                    );
                    $first = false;
                } else {
                    $body .= '<tr>';
                }

                $body .= sprintf(
                    '<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                    .'<td style="padding:10px 12px; border:1px solid #e5e7eb; text-align:right;">%s</td>'
                    .'<td style="padding:10px 12px; border:1px solid #e5e7eb; text-align:right;">%s</td>'
                    .'<td style="padding:10px 12px; border:1px solid #e5e7eb; text-align:right;">%d%%</td>'
                    .'<td style="padding:10px 12px; border:1px solid #e5e7eb; font-weight:600; color:%s;">%s</td>'
                    .'</tr>',
                    e($row['metric']),
                    e($row['target_display']),
                    e($row['actual_display']),
                    $row['pct'],
                    $color,
                    e($row['label']),
                );
            }
        }

        return '<table style="width:100%; border-collapse:collapse; margin:20px 0; font-size:14px;">'
            .'<thead><tr style="background-color:#00002a; color:#ffffff;">'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Agent</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Metric</th>'
            .'<th style="padding:10px 12px; text-align:right; border:1px solid #e5e7eb;">Target</th>'
            .'<th style="padding:10px 12px; text-align:right; border:1px solid #e5e7eb;">Achieved</th>'
            .'<th style="padding:10px 12px; text-align:right; border:1px solid #e5e7eb;">%</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Status</th>'
            .'</tr></thead>'
            .'<tbody>'.$body.'</tbody></table>';
    }
}
