<?php

namespace App\Models;

use App\Constants\Constants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enquiry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'agentId',
        'contactId',
        'listingId',
        'referenceNo',
        'phone',
        'enquiryType',
        'status',
        'referredBy',
        'actualPrice',
        'priceMin',
        'priceMax',
        'sizeMin',
        'sizeMax',
        'bedroomsMin',
        'bedroomsMax',
        'bathroomsMin',
        'bathroomsMax',
        'listingType',
        'lostReason',
        'enquiryPropertyType',
        'enquiryPropertySubType',
        'leadSource',
        'technicalSource',
        'comments',
        'isOpenGame',
        'isQualified',
        'isActive',
        'flyerSent',
        'createdBy',
        'city',
        'community',
        'community_2',
        'community_3',
        'leadQualification',
        'projectName',
        'contactMethod',
        'webUrl',
        'propertyPropertyFinder',
        'recordType',
        'extras',
        'leadRating',
        'leadScoreLocked',
        'leadOutcome',
        'outcome',
        'enquiryRecordUrl',
        'filterNumber',
        'smsOptOut',
        'unitNumber',
        'unitType',
        'yearBuiltMin',
        'yearBuiltMax',
        'floorMin',
        'floorMax',
        'parkingSpaceMin',
        'parkingSpaceMax',
        'privateAmenities',
        'view',
        'furnished',
        'elPartners',
        'partnersEmail',
        'partnerEmailCc',
        'investmentMotivation',
        'budget',
        'dubaiExperience',
        'timeline',
        'apartmentType',
    ];

    protected $casts = [
        'privateAmenities' => 'array',
        'smsOptOut' => 'boolean',
        'flyerSent' => 'boolean',
        'leadScoreLocked' => 'boolean',
    ];

    /**
     * Lead score (leadRating) is the single source of truth for hot/warm/cold,
     * consumed everywhere (dashboard, reports, filters, lists). It stays in sync
     * with the enquiry status automatically UNLESS an agent has manually locked
     * it (leadScoreLocked). This observer keeps that materialised value correct
     * across every status change — service, portal imports, WhatsApp webhook,
     * task/offer transitions — without each caller having to remember to do it.
     *
     * Manual override (highest priority): set leadRating + leadScoreLocked=true.
     * Automated override (second priority): when not locked, the latest status wins.
     */
    protected static function booted(): void
    {
        static::saving(function (self $enquiry) {
            if ($enquiry->leadScoreLocked) {
                return; // manual lock — leave leadRating untouched.
            }

            // Auto mode: leadRating tracks the current status (recompute on a
            // status change or when it has never been set).
            if ($enquiry->isDirty('status') || $enquiry->leadRating === null) {
                $enquiry->leadRating = leadScoreForStatus($enquiry->status);
            }
        });
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agentId');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contactId');
    }

    public function listing()
    {
        return $this->belongsTo(Property::class, 'listingId');
    }

    public function closings()
    {
        return $this->hasMany(Closing::class, 'enquiryId');
    }

    public function offers()
    {
        return $this->hasMany(Offer::class, 'enquiryId');
    }

    // Viewing tasks logged directly against this enquiry (module = enquiry).
    // Mirrors how the Enquiry Conversion report detects a "viewing" event.
    public function viewingTasks()
    {
        return $this->hasMany(Task::class, 'moduleId')
            ->where('tasks.module', Constants::MODULE_ENQUIRY)
            ->where('tasks.type', 'viewing');
    }

    public function propertyCategory()
    {
        return $this->belongsTo(PropertyCategory::class, 'propertyCategoryId');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }

    public function history()
    {
        return $this->hasMany(EnquiryHistory::class, 'enquiryId');
    }

    public function tasks()
    {
        return $this->hasMany(Task::class, 'moduleId')->where('module', Constants::MODULE_ENQUIRY);
    }

    public function getLatestActivityAttribute(): ?Task
    {
        if (! $this->relationLoaded('tasks')) {
            return null;
        }

        return $this->tasks
            ->sort(fn ($a, $b) => [$b->dueDate ?? '', $b->id] <=> [$a->dueDate ?? '', $a->id])
            ->first();
    }

    public function internalNotes()
    {
        return $this->hasMany(InternalNote::class, 'moduleId', 'id')
            ->where('module', Constants::MODULE_ENQUIRY)
            ->with('creator');
    }

    public function follows()
    {
        return $this->hasMany(Follow::class, 'moduleId')->where('module', Constants::MODULE_ENQUIRY);
    }

    public function linkedListings()
    {
        return $this->hasMany(EnquiryLinkedListing::class, 'enquiryId');
    }

    public function properties()
    {
        return $this->belongsToMany(Property::class, 'enquiry_linked_listings', 'enquiryId', 'propertyId')->withTimestamps();
    }

    public function cityInfo()
    {
        return $this->belongsTo(PropertyLocation::class, 'city', 'locationId');
    }

    public function communityInfo()
    {
        return $this->belongsTo(PropertyCommunity::class, 'community', 'communityId');
    }

    public function community2Info()
    {
        return $this->belongsTo(PropertyCommunity::class, 'community_2', 'communityId');
    }

    public function community3Info()
    {
        return $this->belongsTo(PropertyCommunity::class, 'community_3', 'communityId');
    }

    public function getLastActivityAtAttribute()
    {
        $taskLatest = $this->tasks?->max('updated_at');

        return $taskLatest && $taskLatest->greaterThan($this->updated_at)
            ? $taskLatest
            : $this->updated_at;
    }
}
