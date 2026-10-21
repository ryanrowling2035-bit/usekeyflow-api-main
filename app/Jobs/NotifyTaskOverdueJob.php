<?php

namespace App\Jobs;

use App\Constants\Constants;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyTaskOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    // A task is escalated to managers once it is overdue by at least this many days (24h+).
    private const MANAGER_ESCALATION_DAYS = 2;

    public function __construct(public int $taskId) {}

    public function handle(NotificationService $notificationService): void
    {
        $task = Task::with('assignedUser:id,name')->find($this->taskId);

        // Skip if the task is gone, completed, unassigned or has no due date.
        if (! $task
            || ! $task->assignedTo
            || ! $task->dueDate
            || in_array($task->status, Constants::TASK_CLOSED_STATUSES, true)) {
            return;
        }

        $overdueDays = Carbon::parse($task->dueDate)->startOfDay()->diffInDays(Carbon::today(), false);

        // Not overdue yet (due today or in the future).
        if ($overdueDays < 1) {
            return;
        }

        $actorId = $task->createdBy ?: $task->assignedTo;
        $moduleName = $task->module ? ucfirst($task->module) : '';
        $dueDateLabel = Carbon::parse($task->dueDate)->format('F d, Y');

        // Tier 1 — notify the assigned agent (same-day overdue and beyond).
        if (! $task->overdueAgentNotified) {
            $notificationService->notifyUser(
                userId: $task->assignedTo,
                actorId: $actorId,
                type: Constants::NOTIFICATION_TYPES['task_overdue'],
                title: "Overdue task: {$task->subject}",
                message: "Your task \"{$task->subject}\"".($moduleName ? " for {$moduleName}" : '')." is overdue (due {$dueDateLabel}).",
                module: Constants::MODULE_TASK,
                moduleId: $task->id,
                extraData: [
                    'taskId' => $task->id,
                    'taskSubject' => $task->subject,
                    'dueDate' => $task->dueDate,
                    'overdueDays' => $overdueDays,
                    'escalation' => 'agent',
                ]
            );

            $task->overdueAgentNotified = true;
        }

        // Tier 2 — escalate to managers once overdue beyond 24 hours.
        if ($overdueDays >= self::MANAGER_ESCALATION_DAYS && ! $task->overdueManagerNotified) {
            $managerRoleIds = Role::where('name', Constants::MANAGER)->pluck('id');
            $managers = User::whereIn('roleId', $managerRoleIds)
                ->where('status', Constants::USER_STATUS_ACTIVE)
                ->where('id', '!=', $task->assignedTo)
                ->get(['id', 'name']);

            $agentName = $task->assignedUser?->name ?? 'the assigned user';

            foreach ($managers as $manager) {
                $notificationService->notifyUser(
                    userId: $manager->id,
                    actorId: $actorId,
                    type: Constants::NOTIFICATION_TYPES['task_overdue'],
                    title: "Overdue task escalation: {$task->subject}",
                    message: "{$agentName}'s task \"{$task->subject}\"".($moduleName ? " for {$moduleName}" : '')." is overdue by {$overdueDays} days (due {$dueDateLabel}).",
                    module: Constants::MODULE_TASK,
                    moduleId: $task->id,
                    extraData: [
                        'taskId' => $task->id,
                        'taskSubject' => $task->subject,
                        'dueDate' => $task->dueDate,
                        'overdueDays' => $overdueDays,
                        'assignedUser' => $agentName,
                        'escalation' => 'manager',
                    ]
                );
            }

            $task->overdueManagerNotified = true;
        }

        // Persist the dedup flags only if something changed.
        if ($task->isDirty(['overdueAgentNotified', 'overdueManagerNotified'])) {
            $task->save();
        }
    }
}
