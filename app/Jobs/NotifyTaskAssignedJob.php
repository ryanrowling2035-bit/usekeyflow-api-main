<?php

namespace App\Jobs;

use App\Constants\Constants;
use App\Models\Property;
use App\Models\Task;
use App\Models\User;
use App\Services\MailService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyTaskAssignedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(public array $taskIds, public int $actorId) {}

    public function handle(NotificationService $notificationService, MailService $mailService): void
    {
        $actor = User::find($this->actorId);
        if (! $actor) {
            return;
        }

        $tasks = Task::whereIn('id', $this->taskIds)->get();

        foreach ($tasks as $task) {
            if (! $task->assignedTo || $task->assignedTo == $this->actorId) {
                continue;
            }

            $assignedUser = User::find($task->assignedTo);
            if (! $assignedUser) {
                continue;
            }

            $moduleName = $this->getModuleName($task->module ?? null, $task->moduleId ?? null);

            $notificationService->notifyUser(
                userId: $task->assignedTo,
                actorId: $this->actorId,
                type: Constants::NOTIFICATION_TYPES['task_assigned'],
                title: "New task assigned to you: {$task->subject}",
                message: $actor->name." assigned you a new task: {$task->subject}".($moduleName ? " for {$moduleName}" : ''),
                module: $task->module,
                moduleId: $task->moduleId,
                extraData: [
                    'taskId' => $task->id,
                    'taskSubject' => $task->subject,
                    'dueDate' => $task->dueDate,
                    'priority' => $task->priority,
                ]
            );

            if (! $assignedUser->email) {
                continue;
            }

            try {
                $dueDate = $task->dueDate
                    ? Carbon::parse($task->dueDate)->format('d/m/Y h:i A')
                    : 'N/A';

                $mailService->sendEmail(
                    Constants::EMAIL_SLUGS['task']['task_assigned'],
                    $assignedUser->email,
                    [
                        'name' => ucwords($assignedUser->name),
                        'assigned_by' => ucwords($actor->name),
                        'task_title' => $task->subject,
                        'type' => $task->type ?? 'N/A',
                        'priority' => $task->priority ?? 'N/A',
                        'due_date' => $dueDate,
                        'comments' => $task->comments ?? 'N/A',
                        'company_name' => getCompanyName(),
                    ]
                );
            } catch (\Exception $e) {
                Log::error('NotifyTaskAssignedJob : sendEmail : '.$e->getMessage());
            }
        }
    }

    protected function getModuleName(?string $module, ?int $moduleId): ?string
    {
        if (! $module || ! $moduleId) {
            return null;
        }

        return match ($module) {
            Constants::MODULE_PROPERTY => Property::where('id', $moduleId)->value('title') ?? "Property #{$moduleId}",
            Constants::MODULE_ENQUIRY => "Enquiry #{$moduleId}",
            default => "{$module} #{$moduleId}",
        };
    }
}
