<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Jobs\NotifyOpenGameReminderJob;
use App\Models\Enquiry;
use Illuminate\Console\Command;

class SendDailyEnquiryOpenGameReminder extends Command
{
    protected $signature = 'app:send-daily-enquiry-open-game-reminder';

    protected $description = 'Email each agent a daily list of their Property Search / Thinking About It enquiries due to move into Open Game unless activity is logged.';

    public function handle(): int
    {
        // Agents who have at least one at-risk enquiry (Property Search / Thinking About It, not yet Open Game).
        $agentIds = Enquiry::where('isOpenGame', 0)
            ->whereIn('status', [Constants::ENQUIRY_PROPERTY_SEARCH, Constants::ENQUIRY_THINKING_ABOUT_IT])
            ->whereNotNull('agentId')
            ->distinct()
            ->pluck('agentId');

        if ($agentIds->isEmpty()) {
            $this->info('No Property Search / Thinking About It enquiries pending Open Game.');

            return self::SUCCESS;
        }

        foreach ($agentIds as $agentId) {
            NotifyOpenGameReminderJob::dispatch((int) $agentId);
        }

        $this->info("Dispatched Open Game reminder job(s) for {$agentIds->count()} agent(s).");

        return self::SUCCESS;
    }
}
