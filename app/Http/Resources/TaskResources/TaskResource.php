<?php

namespace App\Http\Resources\TaskResources;

use App\Constants\Constants;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $reminderLocal = utcDateTimeToLocal($this->reminderDate, $this->reminderTime);

        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'moduleId' => $this->moduleId,
            'module' => $this->module,
            'type' => $this->type,
            'typeLabel' => $this->type ? (Constants::TASK_TYPE[$this->type] ?? $this->type) : null,
            'dueDate' => $this->dueDate,
            // Only Meetings and Viewings run across a date range; every other task
            // type is a single-day follow-up, so the key is omitted for those.
            'endDate' => $this->when(
                in_array($this->type, ['meeting', 'viewing'], true),
                fn () => $this->endDate,
            ),
            'startTime' => $this->startTime,
            'endTime' => $this->endTime,
            'reminderSet' => $this->reminderSet,
            'reminderDate' => $reminderLocal['date'] ?? null,
            'reminderTime' => $reminderLocal['time'] ?? null,
            'comments' => $this->comments,
            'priority' => $this->priority,
            'priorityLabel' => $this->priority ? (Constants::TASK_PRIORITY[$this->priority] ?? $this->priority) : null,
            'status' => $this->status,
            'statusLabel' => $this->status ? (Constants::TASK_STATUS[$this->status] ?? $this->status) : null,
            'createdBy' => $this->createdBy,
            'createdAt' => convertToTimezone($this->created_at),
            'assignedTo' => $this->assignedUser ? [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
                'role' => $this->assignedUser->role?->name,
            ] : null,
            'creator' => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
                'role' => $this->creator->role?->name,
            ] : null,
            'connectedModule' => $this->connectedModule,
            'client' => $this->client,
        ];
    }
}
