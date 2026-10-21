<?php

namespace App\Console\Commands;

use App\Jobs\NotifyOverdueFollowupsSummaryJob;
use Illuminate\Console\Command;

class SendOverdueFollowupsSummary extends Command
{
    protected $signature = 'app:send-overdue-followups-summary';

    protected $description = 'Send managers a CRM + email summary of agents whose overdue follow-ups exceed the agreed threshold.';

    public function handle(): int
    {
        NotifyOverdueFollowupsSummaryJob::dispatch();

        $this->info('Dispatched overdue follow-ups summary job.');

        return self::SUCCESS;
    }
}
