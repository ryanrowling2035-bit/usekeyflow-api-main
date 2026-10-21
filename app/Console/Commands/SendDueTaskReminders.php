<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Models\Task;
use App\Models\User;
use App\Services\MailService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendDueTaskReminders extends Command
{
    protected $signature = 'app:send-due-task-reminders';

    protected $description = 'Send reminder notifications and emails for tasks where reminderDate + reminderTime has passed';

    public function handle(): int
    {
        $notificationService = app(NotificationService::class);
        $mailService = app(MailService::class);

        // Find tasks where reminder is set, not yet sent, not completed, and due time has passed
        $tasks = Task::where('reminderSet', 1)
            ->where('reminderSent', 0)
            ->whereNotIn('status', Constants::TASK_CLOSED_STATUSES)
            ->whereNotNull('reminderDate')
            ->whereNotNull('reminderTime')
            ->whereNotNull('assignedTo')
            ->get();

        $sentCount = 0;

        foreach ($tasks as $task) {
            // Reminder values are stored UTC; now() is UTC (app.timezone = UTC)
            $reminderAt = Carbon::parse($task->reminderDate.' '.$task->reminderTime, 'UTC');

            // Skip if reminder time hasn't arrived yet
            if ($reminderAt->isAfter(now())) {
                continue;
            }

            // Skip if assigned to self
            if ($task->assignedTo == $task->createdBy) {
                continue;
            }

            $assignedUser = User::find($task->assignedTo);

            if (! $assignedUser || empty($assignedUser->email)) {
                continue;
            }

            $creator = User::find($task->createdBy);
            $creatorName = $creator->name ?? 'System';
            $moduleName = $task->module ? ucfirst($task->module) : '';

            // Send notification
            $notificationService->notifyUser(
                userId: $task->assignedTo,
                actorId: $task->createdBy,
                type: Constants::NOTIFICATION_TYPES['task_assigned'],
                title: "Task reminder: {$task->subject}",
                message: $creatorName." set a reminder for task: {$task->subject}".($moduleName ? " for {$moduleName}" : ''),
                module: $task->module,
                moduleId: $task->moduleId,
                extraData: [
                    'taskId' => $task->id,
                    'taskSubject' => $task->subject,
                    'reminderDate' => $task->reminderDate,
                    'reminderTime' => $task->reminderTime,
                ]
            );

            // Send email (format times in the user's display timezone, not UTC)
            try {
                $reminderLocal = $reminderAt->copy()->setTimezone(appTimezone());
                $formattedDate = $reminderLocal->format('d/m/Y');
                $formattedTime = $reminderLocal->format('h:i A');
                $mailService->sendEmail(
                    Constants::EMAIL_SLUGS['task']['task_reminder'],
                    $assignedUser->email,
                    [
                        'name' => ucwords($assignedUser->name),
                        'email' => $assignedUser->email,
                        'task_title' => $task->subject,
                        'type' => $task->type,
                        'reminderDate' => $formattedDate,
                        'reminderTime' => $formattedTime,
                        'comments' => $task->comments,
                        'company_name' => getCompanyName(),
                    ]
                );
            } catch (\Exception $e) {
                Log::error('SendDueTaskReminders : sendEmail : '.$e->getMessage());
            }

            // Mark as sent
            $task->update(['reminderSent' => 1]);
            $sentCount++;
        }

        $this->info("Sent {$sentCount} task reminders.");

        return self::SUCCESS;
    }
}
