<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Jobs\NotifyTaskOverdueJob;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendOverdueTaskNotifications extends Command
{
    protected $signature = 'app:send-overdue-task-notifications';

    protected $description = 'Notify the assigned user (CRM) when a task is overdue, escalating to managers once it is overdue by more than 24 hours.';

    // Escalate to managers once the task is overdue by at least this many days (24h+).
    private const MANAGER_ESCALATION_DAYS = 2;

    public function handle(): int
    {
        $today = Carbon::today();
        $managerCutoff = $today->copy()->subDays(self::MANAGER_ESCALATION_DAYS)->toDateString();

        $taskIds = Task::whereNotNull('dueDate')
            ->whereNotNull('assignedTo')
            ->whereNotIn('status', Constants::TASK_CLOSED_STATUSES)
            ->whereDate('dueDate', '<', $today->toDateString())
            ->where(function ($q) use ($managerCutoff) {
                // Agent not yet notified, OR manager escalation still pending and now due.
                $q->where('overdueAgentNotified', 0)
                    ->orWhere(function ($q2) use ($managerCutoff) {
                        $q2->where('overdueManagerNotified', 0)
                            ->whereDate('dueDate', '<=', $managerCutoff);
                    });
            })
            ->pluck('id');

        if ($taskIds->isEmpty()) {
            $this->info('No overdue tasks pending notification.');

            return self::SUCCESS;
        }

        foreach ($taskIds as $taskId) {
            NotifyTaskOverdueJob::dispatch((int) $taskId);
        }

        $this->info("Dispatched {$taskIds->count()} overdue task notification job(s).");

        return self::SUCCESS;
    }
}
