<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Jobs\RerateEnquiryLeadScoresJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Manual entry point for the lead-score re-rate — the one-off reconciliation
 * after deploy, and the ops escape hatch. Routine re-rates happen automatically
 * when an admin saves the Settings → lead score bands.
 */
class RerateEnquiryLeadScores extends Command
{
    protected $signature = 'app:rerate-enquiry-lead-scores
                            {--dry-run : Report what would change without writing anything}
                            {--status=* : Limit to these enquiry statuses (default: all)}
                            {--sync : Run the batches inline instead of handing them to the queue}';

    protected $description = 'Re-rate enquiries (leadRating) against the configured lead-score bands. Manually locked scores are never touched.';

    public function handle(): int
    {
        $only = $this->option('status');

        $statusBands = [];
        foreach (array_keys(Constants::ENQUIRY_ALL_STATUS) as $status) {
            if ($only && ! in_array($status, $only, true)) {
                continue;
            }
            $statusBands[$status] = leadScoreForStatus($status);
        }

        if ($statusBands === []) {
            $this->error('No enquiry status matched --status.');

            return self::FAILURE;
        }

        $total = 0;
        $rows = [];
        foreach ($statusBands as $status => $band) {
            $count = DB::table('enquiries')
                ->where('leadScoreLocked', false)
                ->where('status', $status)
                ->where(function ($q) use ($band) {
                    // != excludes NULL in MySQL, hence the explicit null arm.
                    $band === null
                        ? $q->whereNotNull('leadRating')
                        : $q->where('leadRating', '!=', $band)->orWhereNull('leadRating');
                })
                ->count();

            $total += $count;
            $rows[] = [$status, $band ?? '(no score)', $count];
        }

        $this->table(['Status', 'Lead score', 'To re-rate'], $rows);

        if ($total === 0) {
            $this->info('Every unlocked enquiry already matches the configured bands.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("Dry run — {$total} enquiries would be re-rated. Nothing was written.");

            return self::SUCCESS;
        }

        $locked = DB::table('enquiries')->where('leadScoreLocked', true)->count();
        $this->info("Re-rating {$total} enquiries; {$locked} manually locked score(s) left untouched.");

        if ($this->option('sync')) {
            RerateEnquiryLeadScoresJob::dispatchSync($statusBands);
            $this->info('Done.');

            return self::SUCCESS;
        }

        RerateEnquiryLeadScoresJob::dispatch($statusBands);
        $this->info('Queued — watch the log for "RerateEnquiryLeadScoresJob: finished".');

        return self::SUCCESS;
    }
}
