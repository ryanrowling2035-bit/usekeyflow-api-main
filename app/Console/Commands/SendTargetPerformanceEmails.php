<?php

namespace App\Console\Commands;

use App\Services\TargetPerformanceService;
use Illuminate\Console\Command;

class SendTargetPerformanceEmails extends Command
{
    protected $signature = 'app:send-target-performance-emails
        {--as-of= : Treat this date (Y-m-d) as today, to test a period boundary off-cycle}
        {--dry-run : Report who would be alerted without sending email or writing notifications}
        {--test-email= : Route every email to this address instead of the real recipients; skips in-app notifications}
        {--limit= : Only evaluate this many agents (and notify this many managers), to keep a test send small}';

    protected $description = 'On the first day of each target period (weekly/monthly/quarterly/biAnnual per _targetKeys), email agents their previous-period performance vs targets, push alerts for red metrics, and send managers a consolidated digest of agents below target.';

    public function handle(TargetPerformanceService $service): int
    {
        $asOf = $this->option('as-of') ?: null;
        $dryRun = (bool) $this->option('dry-run');
        $testEmail = $this->option('test-email') ?: null;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $result = $service->sendForDuePeriod($asOf, $dryRun, $testEmail, $limit);

        if (! empty($result['skipped'])) {
            $this->info('Target performance emails skipped: '.$result['skipped'].'.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s period: %s (%s)%s',
            $result['periodType'],
            $result['period'],
            $result['range'],
            $dryRun ? '  [dry run - nothing sent]' : ($testEmail ? "  [all mail routed to {$testEmail}]" : ''),
        ));

        // Dry runs are for eyeballing who is red and why, so print the breakdown.
        if ($dryRun && ! empty($result['digest'])) {
            $rows = [];

            foreach ($result['digest'] as $entry) {
                foreach ($entry['rows'] as $row) {
                    $rows[] = [
                        $entry['name'],
                        $row['metric'],
                        $row['target_display'],
                        $row['actual_display'],
                        $row['pct'].'%',
                        $row['emailAlertWhenRed'] ? 'yes' : 'no',
                        $row['pushNotificationWhenRed'] ? 'yes' : 'no',
                    ];
                }
            }

            $this->table(['Agent', 'Metric', 'Target', 'Achieved', '%', 'Email', 'Push'], $rows);
        }

        $this->info(sprintf(
            'Target performance (%s): %s %d agents, %s %d push alerts, %s %d managers.',
            $result['period'] ?? '-',
            $dryRun ? 'would email' : 'emailed',
            $result['emailed'] ?? 0,
            // Push alerts are skipped for both --dry-run and --test-email.
            empty($result['notificationsSuppressed']) ? 'sent' : 'would send',
            $result['pushed'] ?? 0,
            $dryRun ? 'would notify' : 'emailed',
            $result['managersNotified'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
