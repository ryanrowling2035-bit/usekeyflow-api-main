<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'eventType',
        'startDateTime',
        'endDateTime',
        'allDay',
        'location',
        'module',
        'moduleId',
        'taskId',
        'assignedTo',
        'createdBy',
        'status',
        'priority',
        'color',
        'reminderSet',
        'reminderDate',
        'reminderTime',
        'outlookEventId',
        'isFromOutlook',
        'isSyncedToOutlook',
        'isPrivate',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'startDateTime' => 'datetime',
            'endDateTime' => 'datetime',
            'allDay' => 'boolean',
            'reminderSet' => 'boolean',
            'reminderDate' => 'date',
            'isFromOutlook' => 'boolean',
            'isSyncedToOutlook' => 'boolean',
            'isPrivate' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assignedTo');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }

    public function task()
    {
        return $this->belongsTo(Task::class, 'taskId');
    }

    /**
     * Check if the event is overdue.
     */
    public function getIsOverdueAttribute(): bool
    {
        if (in_array($this->status, ['completed', 'cancelled'])) {
            return false;
        }

        $compareDate = $this->endDateTime ?? $this->startDateTime;

        return $compareDate && $compareDate->isPast();
    }

    /**
     * Dynamically resolve the linked record based on module type.
     */
    public function getLinkedRecordAttribute()
    {
        if (! $this->module || ! $this->moduleId) {
            return null;
        }

        return match ($this->module) {
            'contact' => Contact::select('id', 'name', 'email', 'phone')->find($this->moduleId),
            'enquiry' => Enquiry::select('id', 'referenceNo', 'status', 'enquiryType')->find($this->moduleId),
            'listing' => Property::select('id', 'title', 'referenceNo', 'status')->find($this->moduleId),
            'offer' => Offer::select('id', 'name', 'status', 'offeredAmount')->find($this->moduleId),
            'closing' => Closing::select('id', 'status', 'targetCloseDate', 'closingDate')->find($this->moduleId),
            default => null,
        };
    }
}
