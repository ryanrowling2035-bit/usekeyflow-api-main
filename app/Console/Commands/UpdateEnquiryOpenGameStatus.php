<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Models\Enquiry;
use App\Models\GlobalSetting;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class UpdateEnquiryOpenGameStatus extends Command
{
    protected $signature = 'app:update-enquiry-open-game-status';

    protected $description = 'Update isOpenGame based on per-stage activity windows. New Lead / Contacting / Property Search / Thinking About It windows are read from the Agent Performance settings (split by Rent/Sale), with a fallback to defaults when not configured.';

    /**
     * Enquiry types treated as rentals; everything else (including null) is a
     * sale. Mirrors the core rent/sale classification used across the app
     * (e.g. Constants::enquiryStatusMap and the enquiry kanban board).
     */
    protected const RENT_ENQUIRY_TYPES = ['landlord', 'tenant', 'rent'];

    /**
     * Enquiry types that never follow the Open Game rules. We only run Open Game
     * on agent/buyer/tenant/investor leads — sellers and landlords (and listings)
     * are excluded, so these enquiries are never flagged as Open Game.
     */
    public const OPEN_GAME_EXCLUDED_TYPES = ['seller', 'landlord'];

    /**
     * Fallback activity windows when nothing is configured in settings. These
     * match the defaults shown on the Agent Performance settings page. New Lead
     * and Contacting are expressed in hours; Property Search and Thinking About
     * It in days.
     */
    protected const DEFAULT_THRESHOLDS = [
        'rent' => ['newLead' => 24, 'contacting' => 24, 'propertySearch' => 7,  'thinkingAboutIt' => 14],
        'sale' => ['newLead' => 48, 'contacting' => 24, 'propertySearch' => 14, 'thinkingAboutIt' => 30],
    ];

    public function handle(): int
    {
        $notificationService = app(NotificationService::class);

        $thresholds = $this->resolveThresholds();

        $rentScope = fn ($query) => $query->whereIn('enquiryType', self::RENT_ENQUIRY_TYPES);
        $saleScope = fn ($query) => $query->where(function ($inner) {
            $inner->whereNotIn('enquiryType', self::RENT_ENQUIRY_TYPES)
                ->orWhereNull('enquiryType');
        });

        $counts = [];

        // 1. New Lead: no task within the configured window (hours), split by Rent/Sale.
        $counts['newLeadRent'] = $this->moveStageToOpenGame(
            Constants::ENQUIRY_NEW_LEAD,
            now()->subHours($thresholds['rent']['newLead']),
            $rentScope,
            "New Lead (Rent): no activity logged within {$thresholds['rent']['newLead']} hours",
            $notificationService,
        );
        $counts['newLeadSale'] = $this->moveStageToOpenGame(
            Constants::ENQUIRY_NEW_LEAD,
            now()->subHours($thresholds['sale']['newLead']),
            $saleScope,
            "New Lead (Sale): no activity logged within {$thresholds['sale']['newLead']} hours",
            $notificationService,
        );

        // 2. Contacting: no task within the configured window (hours), split by Rent/Sale.
        $counts['contactingRent'] = $this->moveStageToOpenGame(
            Constants::ENQUIRY_CONTACTING,
            now()->subHours($thresholds['rent']['contacting']),
            $rentScope,
            "Contacting (Rent): no activity logged within {$thresholds['rent']['contacting']} hours",
            $notificationService,
        );
        $counts['contactingSale'] = $this->moveStageToOpenGame(
            Constants::ENQUIRY_CONTACTING,
            now()->subHours($thresholds['sale']['contacting']),
            $saleScope,
            "Contacting (Sale): no activity logged within {$thresholds['sale']['contacting']} hours",
            $notificationService,
        );

        // 3. Property Search: no task within the configured window (days), split by Rent/Sale.
        $counts['propertySearchRent'] = $this->moveStageToOpenGame(
            Constants::ENQUIRY_PROPERTY_SEARCH,
            now()->subDays($thresholds['rent']['propertySearch']),
            $rentScope,
            "Property Search (Rent): no activity logged within {$thresholds['rent']['propertySearch']} days",
            $notificationService,
        );
        $counts['propertySearchSale'] = $this->moveStageToOpenGame(
            Constants::ENQUIRY_PROPERTY_SEARCH,
            now()->subDays($thresholds['sale']['propertySearch']),
            $saleScope,
            "Property Search (Sale): no activity logged within {$thresholds['sale']['propertySearch']} days",
            $notificationService,
        );

        // 4. Thinking About It: no task within the configured window (days), split by Rent/Sale.
        $counts['thinkingRent'] = $this->moveStageToOpenGame(
            Constants::ENQUIRY_THINKING_ABOUT_IT,
            now()->subDays($thresholds['rent']['thinkingAboutIt']),
            $rentScope,
            "Thinking About It (Rent): no activity logged within {$thresholds['rent']['thinkingAboutIt']} days",
            $notificationService,
        );
        $counts['thinkingSale'] = $this->moveStageToOpenGame(
            Constants::ENQUIRY_THINKING_ABOUT_IT,
            now()->subDays($thresholds['sale']['thinkingAboutIt']),
            $saleScope,
            "Thinking About It (Sale): no activity logged within {$thresholds['sale']['thinkingAboutIt']} days",
            $notificationService,
        );

        $total = array_sum($counts);

        $this->info("Updated {$total} enquiries (New Lead rent: {$counts['newLeadRent']}, sale: {$counts['newLeadSale']}; Contacting rent: {$counts['contactingRent']}, sale: {$counts['contactingSale']}; Property Search rent: {$counts['propertySearchRent']}, sale: {$counts['propertySearchSale']}; Thinking About It rent: {$counts['thinkingRent']}, sale: {$counts['thinkingSale']}).");

        return Command::SUCCESS;
    }

    /**
     * Resolve the per-stage activity windows for Rent and Sale enquiries from the
     * Agent Performance settings (_targetKeys.enquiryResponseTime), falling back
     * to self::DEFAULT_THRESHOLDS for any value that is missing or invalid.
     *
     * @return array{rent: array<string, int>, sale: array<string, int>}
     */
    protected function resolveThresholds(): array
    {
        $raw = GlobalSetting::where('settingKey', Constants::GLOBAL_SETTINGS['targetKeys'])->value('settingValue');
        $config = $raw ? json_decode($raw, true) : null;
        $responseTime = is_array($config) ? ($config['enquiryResponseTime'] ?? []) : [];

        // Settings store buckets as `renting` / `sales`; map them onto the
        // rent / sale enquiry groups this command works with.
        return [
            'rent' => $this->mergeStageWindows(self::DEFAULT_THRESHOLDS['rent'], $responseTime['renting'] ?? null),
            'sale' => $this->mergeStageWindows(self::DEFAULT_THRESHOLDS['sale'], $responseTime['sales'] ?? null),
        ];
    }

    /**
     * Overlay configured stage windows on top of the defaults, keeping a default
     * whenever the configured value is absent or not a positive number.
     *
     * @param  array<string, int>  $defaults
     * @return array<string, int>
     */
    protected function mergeStageWindows(array $defaults, $configured): array
    {
        if (! is_array($configured)) {
            return $defaults;
        }

        foreach ($defaults as $stage => $default) {
            $value = $configured[$stage] ?? null;

            if (is_numeric($value) && (int) $value > 0) {
                $defaults[$stage] = (int) $value;
            }
        }

        return $defaults;
    }

    /**
     * Flag enquiries in the given status as Open Game when they have had no
     * activity since $cutoff, then notify their agents. Returns the count moved.
     *
     * "Activity" is any of: the enquiry itself being touched, a task being
     * created on it, or an existing task being updated (completed, rescheduled,
     * commented). Task creation alone is not enough — agents commonly book a
     * batch of follow-ups up front and then work through them over days, which
     * used to read as total silence and dropped worked leads into Open Game.
     */
    protected function moveStageToOpenGame(string $status, $cutoff, ?callable $typeScope, string $reason, NotificationService $notificationService): int
    {
        $ids = Enquiry::where('isOpenGame', 0)
            ->where('status', $status)
            // Sellers and landlords never enter Open Game. Keep null-type enquiries
            // eligible (they are treated as sale/buyer elsewhere) — only the two
            // excluded types are filtered out.
            ->where(function ($typeQuery) {
                $typeQuery->whereNull('enquiryType')
                    ->orWhereNotIn('enquiryType', self::OPEN_GAME_EXCLUDED_TYPES);
            })
            ->where('created_at', '<=', $cutoff)
            // The enquiry itself was edited within the window (status change,
            // requirement updates, agent reassignment, ...).
            ->where('updated_at', '<=', $cutoff)
            ->whereDoesntHave('tasks', function ($taskQuery) use ($cutoff) {
                $taskQuery->where(function ($activity) use ($cutoff) {
                    $activity->where('created_at', '>', $cutoff)
                        ->orWhere('updated_at', '>', $cutoff);
                });
            })
            ->whereDoesntHave('agent', function ($agentQuery) {
                $agentQuery->where('isAvailable', 0);
            })
            ->when($typeScope, $typeScope)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        // Capture the ones currently qualified — moving back to Open Game resets
        // qualification, so an agent must genuinely re-qualify to claim the lead.
        $qualifiedIds = Enquiry::whereIn('id', $ids)->where('isQualified', 1)->pluck('id')->all();

        Enquiry::whereIn('id', $ids)->update([
            'isOpenGame' => 1,
            'isQualified' => 0,
        ]);

        $this->notifyAgentsOpenGame($ids, $notificationService, $reason, $qualifiedIds);

        return $ids->count();
    }

    /**
     * Notify assigned agents that their leads have been moved to Open Game.
     */
    protected function notifyAgentsOpenGame($enquiryIds, NotificationService $notificationService, string $reason, array $unqualifiedIds = []): void
    {
        $enquiries = Enquiry::with('contact:id,name')->whereIn('id', $enquiryIds)->get();

        foreach ($enquiries as $enquiry) {
            if (! $enquiry->agentId) {
                continue;
            }

            $contactName = $enquiry->contact?->masterOrSelf()->name ?? "Enquiry #{$enquiry->id}";

            // Notify the assigned agent
            $notificationService->notifyUser(
                userId: $enquiry->agentId,
                actorId: $enquiry->agentId,
                type: Constants::NOTIFICATION_TYPES['enquiry_open_game'],
                title: "Lead moved to Open Game: {$contactName}",
                message: "Your lead '{$contactName}' has been moved to Open Game. Reason: {$reason}",
                module: Constants::MODULE_ENQUIRY,
                moduleId: $enquiry->id,
                extraData: [
                    'reason' => $reason,
                    'previousStatus' => $enquiry->status,
                ]
            );

            // Log history. No userId: this is the scheduler's own action, not the
            // agent's. Crediting the assigned agent made the timeline read as though
            // they had flagged their own lead Open Game, while the description on the
            // same row already said "System". EnquiryHistoryService falls back to the
            // "System" actor when no user is resolved.
            logEnquiryHistory([
                'enquiryId' => $enquiry->id,
                'field' => 'isOpenGame',
                'originalValue' => json_encode(['message' => '0']),
                'newValue' => json_encode(['message' => "1 - {$reason}"]),
            ]);

            // Only leads that were qualified had their qualification reset.
            if (in_array($enquiry->id, $unqualifiedIds, true)) {
                logEnquiryHistory([
                    'enquiryId' => $enquiry->id,
                    'field' => 'isQualified',
                    'originalValue' => json_encode(['message' => '1']),
                    'newValue' => json_encode(['message' => '0']),
                ]);
            }
        }
    }
}
