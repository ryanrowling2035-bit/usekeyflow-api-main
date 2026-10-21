<?php

namespace App\Services\Salesforce\Importers;

use App\Constants\Constants;

class TaskImporter extends AbstractObjectImporter
{
    private const PRIORITY_MAP = [
        'high' => 'high',
        'normal' => 'medium',
        'medium' => 'medium',
        'low' => 'low',
    ];

    /** Salesforce Task Type => Constants::TASK_TYPE key. */
    private const TYPE_MAP = [
        'call' => 'call',
        'email' => 'email',
        'e-mail' => 'email',
        'meeting' => 'meeting',
    ];

    public function objectType(): string
    {
        return 'Task';
    }

    public function module(): string
    {
        return Constants::MODULE_TASK;
    }

    public function file(): string
    {
        return 'Task.csv';
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

        [$module, $moduleId] = $this->connection($row);
        $dueDate = $this->parseDate($this->first($row, ['ActivityDate', 'CreatedDate'])) ?? now()->toDateString();
        $reminder = $this->parseDateTime($row['ReminderDateTime'] ?? null);

        return [
            'subject' => $this->str($row['Subject'] ?? null, 255) ?? '(no subject)',
            'assignedTo' => $assignedTo,
            'module' => $module,
            'moduleId' => $moduleId,
            'type' => $this->lookup($row['Type'] ?? null, self::TYPE_MAP),
            'dueDate' => $dueDate,
            'reminderSet' => $this->isTrue($row['IsReminderSet'] ?? null),
            'reminderDate' => $reminder?->toDateString() ?? $dueDate,
            'reminderTime' => $reminder?->format('H:i:s') ?? '09:00:00',
            'reminderSent' => false,
            'comments' => $this->str($this->first($row, ['Description', 'Summary__c'])) ?? '',
            'status' => $this->resolveStatus($row),
            'priority' => $this->lookup($row['Priority'] ?? null, self::PRIORITY_MAP, 'medium'),
            'createdBy' => $this->resolve('User', $this->first($row, ['CreatedById', 'OwnerId'])),
        ];
    }

    private function resolveStatus(array $row): string
    {
        if ($this->isTrue($row['IsClosed'] ?? null)
            || strtolower(trim((string) ($row['Status'] ?? ''))) === 'completed') {
            return Constants::TASK_STATUS_DONE;
        }

        return Constants::TASK_STATUS_PENDING;
    }

    /**
     * Resolve the CRM record a task is linked to, preferring the explicit Propertybase
     * lookups and falling back to the polymorphic WhatId, then the WhoId contact.
     *
     * @return array{0:?string,1:?int}
     */
    private function connection(array $row): array
    {
        foreach ([
            ['pba__Closing__c', $row['Closing__c'] ?? null, Constants::MODULE_CLOSING],
            ['pba__Offer__c', $row['Offer__c'] ?? null, Constants::MODULE_OFFER],
            ['pba__Request__c', $row['Enquiry_pb__c'] ?? null, Constants::MODULE_ENQUIRY],
            ['pba__Listing__c', $row['Listing__c'] ?? null, Constants::MODULE_PROPERTY],
        ] as [$objectType, $sfId, $module]) {
            $id = $this->resolve($objectType, $sfId);
            if ($id !== null) {
                return [$module, $id];
            }
        }

        [$module, $moduleId] = $this->resolveModule($row['WhatId'] ?? null);
        if ($moduleId !== null) {
            return [$module, $moduleId];
        }

        $contactId = $this->resolveContact($row['WhoId'] ?? null, $row['AccountId'] ?? null);

        return $contactId !== null ? [Constants::MODULE_CONTACT, $contactId] : [null, null];
    }
}
