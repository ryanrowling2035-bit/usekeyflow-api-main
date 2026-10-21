<?php

namespace App\Models;

use App\Constants\Constants;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Closing extends Model
{
    use HasFactory;

    protected $fillable = [
        'referenceNo',
        'name',
        'listingId',
        'enquiryId',
        'offerId',
        'buyerId',
        'status',
        'listingType',
        'targetCloseDate',
        'closingDate',
        'assignedAgentId',
        'clientLeadSource',
        'clientNationality',
        'sellerLandlord',
        'saleRentalPrice',
        'buyerCommission',
        'sellerCommission',
        'totalCommission',
        'representing',
        'closingProbability',
        'agentCommission',
        'companyCommission',
        'sellerLandlordSideSplitAmount',
        'buyerTenantSideSplitAmount',
        'sellerAgentSplitPercentage',
        'companySellerSplitPercentage',
        'sellerAgentSplitAmount',
        'companySellerSplitAmount',
        'buyerAgentSplitPercentage',
        'companyBuyerSplitPercentage',
        'buyerAgentSplitAmount',
        'companyBuyerSplitAmount',
        'escrowTitleContact',
        'buyerTenantAgencyContact',
        'mortgageBrokerContact',
        'sellerLandlordSource',
        'closingUrlRecord',
        'owner',
        'buyerTenant',
        'listingAgencyContact',
        'closingChecklist',
        'createdBy',
    ];

    protected $casts = [
        'closingChecklist' => 'array',
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

    public function offer()
    {
        return $this->belongsTo(Offer::class, 'offerId');
    }

    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assignedAgentId');
    }

    public function sellerLandlordContact()
    {
        return $this->belongsTo(Contact::class, 'sellerLandlord');
    }

    public function buyerTenantContact()
    {
        return $this->belongsTo(Contact::class, 'buyerTenant');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }

    public function tasks()
    {
        return $this->hasMany(Task::class, 'moduleId')->where('module', Constants::MODULE_CLOSING);
    }
}
