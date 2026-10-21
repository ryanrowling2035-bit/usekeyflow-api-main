<?php

namespace App\Models;

use App\Constants\Constants;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    /**
     * Memoized parent-module record (Property/Enquiry/Contact/Offer/Closing) with
     * its client contact eager-loaded. Shared by the connectedModule and client
     * accessors so serializing a task hits the database at most once for its parent.
     */
    private bool $connectedRecordResolved = false;

    private $connectedRecordCache = null;

    protected $fillable = [
        'subject',
        'assignedTo',
        'moduleId',
        'module',
        'type',
        'dueDate',
        'endDate',
        'startTime',
        'endTime',
        'reminderSet',
        'reminderDate',
        'reminderTime',
        'reminderSent',
        'overdueAgentNotified',
        'overdueManagerNotified',
        'comments',
        'status',
        'priority',
        'createdBy',
    ];

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assignedTo');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }

    public function calendarEvent()
    {
        return $this->hasOne(CalendarEvent::class, 'taskId');
    }

    /**
     * Resolve (and memoize) the connected module record based on module type, with
     * the task's client contact eager-loaded. Shared by getConnectedModuleAttribute()
     * and getClientAttribute() so both accessors reuse a single lookup.
     */
    private function resolveConnectedRecord()
    {
        if ($this->connectedRecordResolved) {
            return $this->connectedRecordCache;
        }

        $this->connectedRecordResolved = true;

        if (! $this->module || ! $this->moduleId) {
            return $this->connectedRecordCache = null;
        }

        return $this->connectedRecordCache = match ($this->module) {
            Constants::MODULE_PROPERTY => Property::select('id', 'title', 'referenceNumber', 'physicalOwnerId', 'created_at', 'updated_at')
                ->with('physicalOwner:id,name,phone,email')->find($this->moduleId),
            Constants::MODULE_ENQUIRY => Enquiry::select('id', 'referenceNo', 'enquiryType', 'phone', 'contactId', 'created_at', 'updated_at')
                ->with('contact:id,name,phone,email')->find($this->moduleId),
            Constants::MODULE_CONTACT => Contact::select('id', 'name', 'phone', 'email', 'created_at', 'updated_at')->find($this->moduleId),
            Constants::MODULE_OFFER => Offer::select('id', 'name', 'referenceNo', 'buyerId', 'enquiryId', 'created_at', 'updated_at')
                ->with(['buyer:id,name,phone,email', 'enquiry:id,phone,contactId', 'enquiry.contact:id,name,phone,email'])->find($this->moduleId),
            Constants::MODULE_CLOSING => Closing::select('id', 'name', 'referenceNo', 'buyerId', 'enquiryId', 'created_at', 'updated_at')
                ->with(['buyer:id,name,phone,email', 'enquiry:id,phone,contactId', 'enquiry.contact:id,name,phone,email'])->find($this->moduleId),
            default => null,
        };
    }

    /**
     * The "client" is the physical person a task ultimately concerns, resolved per
     * source module: property → physical owner, enquiry → contact, contact → itself,
     * offer/closing → buyer (falling back to the linked enquiry's contact). Always a
     * Contact record.
     */
    private function resolveClientContact(): ?Contact
    {
        $record = $this->resolveConnectedRecord();

        if (! $record) {
            return null;
        }

        return match ($this->module) {
            Constants::MODULE_PROPERTY => $record->physicalOwner,
            Constants::MODULE_ENQUIRY => $record->contact,
            Constants::MODULE_CONTACT => $record,
            Constants::MODULE_OFFER, Constants::MODULE_CLOSING => $record->buyer ?: $record->enquiry?->contact,
            default => null,
        };
    }

    /**
     * Dynamically resolve the connected module record based on module type.
     * Returns id, reference number, title/name and timestamps in a unified shape.
     */
    public function getConnectedModuleAttribute(): ?array
    {
        $record = $this->resolveConnectedRecord();

        if (! $record) {
            return null;
        }

        $phone = match ($this->module) {
            Constants::MODULE_PROPERTY => $record->physicalOwner?->phone,
            Constants::MODULE_ENQUIRY => $record->phone ?: $record->contact?->phone,
            Constants::MODULE_CONTACT => $record->phone,
            Constants::MODULE_OFFER, Constants::MODULE_CLOSING => $record->buyer?->phone ?: ($record->enquiry?->phone ?: $record->enquiry?->contact?->phone),
            default => null,
        };
        $contactName = match ($this->module) {
            Constants::MODULE_PROPERTY => $record->physicalOwner?->name,
            Constants::MODULE_ENQUIRY => $record->contact?->name,
            Constants::MODULE_CONTACT => $record->name,
            Constants::MODULE_OFFER, Constants::MODULE_CLOSING => $record->buyer?->name ?: $record->enquiry?->contact?->name,
            default => null,
        };

        return [
            'id' => $record->id,
            'module' => $this->module,
            'referenceNumber' => $record->referenceNumber ?? $record->referenceNo ?? null,
            'title' => $record->title ?? $record->name ?? $record->enquiryType ?? null,
            'phone' => $phone ?: null,
            'contactName' => $contactName ?: null,
            'createdAt' => convertToTimezone($record->created_at),
            'updatedAt' => convertToTimezone($record->updated_at),
        ];
    }

    /**
     * The client contact linked to this task, in a unified {id, name, phone, email}
     * shape. Null when the task is unlinked or the client cannot be resolved.
     */
    public function getClientAttribute(): ?array
    {
        $client = $this->resolveClientContact();

        if (! $client) {
            return null;
        }

        return [
            'id' => $client->id,
            'name' => $client->name,
            'phone' => $client->phone ?: null,
            'email' => $client->email ?: null,
        ];
    }
}
