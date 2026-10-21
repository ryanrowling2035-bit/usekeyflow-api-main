<?php

namespace App\Models;

use App\Constants\Constants;
use Illuminate\Database\Eloquent\Model;

class Offer extends Model
{
    protected $fillable = [
        'name',
        'listingId',
        'enquiryId',
        'buyerId',
        'offeredAmount',
        'targetDate',
        'status',
        'offerDate',
        'offerExpirationDate',
        'sellerLandlord',
        'listingType',
        'listingPrice',
        'leaseTerms',

        'potentialCommission',
        'deposits',
        'terms',
        'referenceNo',
        'createdBy',
        'assignedAgentId',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class, 'listingId');
    }

    public function enquiry()
    {
        return $this->belongsTo(Enquiry::class, 'enquiryId');
    }

    public function buyer()
    {
        return $this->belongsTo(Contact::class, 'buyerId');
    }

    public function sellerLandlordContact()
    {
        return $this->belongsTo(Contact::class, 'sellerLandlord');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }

    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assignedAgentId');
    }

    public function tasks()
    {
        return $this->hasMany(Task::class, 'moduleId')->where('module', Constants::MODULE_OFFER);
    }

    public function internalNotes()
    {
        return $this->hasMany(InternalNote::class, 'moduleId', 'id')
            ->where('module', Constants::MODULE_OFFER);
    }
}
