<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Jobs\NotifyHotEnquiryNoActivityJob;
use App\Models\Enquiry;
use Illuminate\Console\Command;

class SendHotEnquiryNoActivity extends Command
{
    protected $signature = 'app:send-hot-enquiry-no-activity';

    protected $description = 'Notify the assigned agent and managers (CRM) when a hot enquiry has had no activity for the agreed period.';

    public function handle(): int
    {
        $cutoff = now()->subHours(Constants::HOT_ENQUIRY_NO_ACTIVITY_HOURS);

        // "Hot" = the effective lead score (leadRating), so manual overrides count
        // and this stays consistent with the rest of the system.
        // No activity = enquiry not updated since cutoff AND no enquiry task touched since cutoff.
        $enquiryIds = Enquiry::whereNotNull('agentId')
            ->where('leadRating', Constants::LEAD_SCORE_HOT)
            ->where('updated_at', '<', $cutoff)
            ->whereDoesntHave('tasks', fn ($q) => $q->where('updated_at', '>=', $cutoff))
            ->whereDoesntHave('history', fn ($q) => $q->where('field', NotifyHotEnquiryNoActivityJob::HISTORY_FIELD))
            ->pluck('id');

        if ($enquiryIds->isEmpty()) {
            $this->info('No hot enquiries pending a no-activity alert.');

            return self::SUCCESS;
        }

        foreach ($enquiryIds as $enquiryId) {
            NotifyHotEnquiryNoActivityJob::dispatch((int) $enquiryId);
        }

        $this->info("Dispatched {$enquiryIds->count()} hot enquiry no-activity job(s).");

        return self::SUCCESS;
    }
}
