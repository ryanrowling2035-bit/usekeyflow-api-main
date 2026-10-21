<?php

namespace App\Services\Salesforce\Importers;

use App\Constants\Constants;

/**
 * Salesforce Events (calendar activities) imported into the CRM tasks table.
 * Salesforce models Activities as Task + Event; the CRM keeps both as tasks,
 * so Events land here with an activity-style type (meeting/viewing/appointment…).
 */
class ActivityImporter extends AbstractObjectImporter
{
    /** Salesforce Event Type => Constants::TASK_TYPE key. */
    private const TYPE_MAP = [
        'meeting' => 'meeting',
        'call' => 'call',
        'email' => 'email',
        'viewing' => 'viewing',
    ];

    public function objectType(): string
    {
        return 'Event';
    }

    public function module(): string
    {
        return Constants::MODULE_TASK;
    }

    public function file(): string
    {
        return 'Event.csv';
    }

    protected function table(): string
    {
        return 'tasks';
    }

    protected function hasSoftDeletes(): bool
    {
        return false;
    }

    protected function beforeImport(): void
    {
        $this->loadMap('User');
        $this->loadMap('Contact');
        $this->loadMap('Account');
        $this->loadMap('pba__Listing__c');
        $this->loadMap('pba__Request__c');
        $this->loadMap('pba__Offer__c');
        $this->loadMap('pba__Closing__c');
    }

    protected function transform(array $row): ?array
    {
        // tasks.assignedTo is required; skip rows whose owner can't be resolved to a CRM user.
        $assignedTo = $this->resolve('User', $row['OwnerId'] ?? null);
        if ($assignedTo === null) {
            return null;
        }

        $start = $this->parseDateTime($this->first($row, ['ActivityDateTime', 'ActivityDate', 'CreatedDate']));
        $dueDate = $start?->toDateString() ?? now()->toDateString();
        $reminder = $this->parseDateTime($row['ReminderDateTime'] ?? null);
        [$module, $moduleId] = $this->connection($row);

        return [
            'subject' => $this->str($row['Subject'] ?? null, 255) ?? 'Event',
            'assignedTo' => $assignedTo,
            'module' => $module,
            'moduleId' => $moduleId,
            'type' => $this->lookup($row['Type'] ?? null, self::TYPE_MAP, 'meeting'),
            'dueDate' => $dueDate,
            'reminderSet' => $this->isTrue($row['IsReminderSet'] ?? null),
            'reminderDate' => $reminder?->toDateString() ?? $dueDate,
            'reminderTime' => $reminder?->format('H:i:s') ?? ($start?->format('H:i:s') ?? '09:00:00'),
            'reminderSent' => false,
            'comments' => $this->str($row['Description'] ?? null) ?? '',
            'status' => $start && $start->isPast() ? Constants::TASK_STATUS_DONE : Constants::TASK_STATUS_PENDING,
            'priority' => 'medium',
            'createdBy' => $this->resolve('User', $this->first($row, ['CreatedById', 'OwnerId'])),
        ];
    }

    /**
     * Resolve the CRM record an event is linked to via the polymorphic WhatId,
     * falling back to the WhoId contact.
     *
     * @return array{0:?string,1:?int}
     */
    private function connection(array $row): array
    {
        [$module, $moduleId] = $this->resolveModule($row['WhatId'] ?? null);
        if ($moduleId !== null) {
            return [$module, $moduleId];
        }

        $contactId = $this->resolveContact($row['WhoId'] ?? null, $row['AccountId'] ?? null);

        return $contactId !== null ? [Constants::MODULE_CONTACT, $contactId] : [null, null];
    }
}
