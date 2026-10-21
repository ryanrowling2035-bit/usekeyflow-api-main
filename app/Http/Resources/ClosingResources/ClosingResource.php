<?php

namespace App\Http\Resources\ClosingResources;

use App\Constants\Constants;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClosingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $now = now();

        // Contacts merged into another render as the surviving contact — the closing
        // still stores the alias's id, since merging moves nothing.
        $buyer = $this->buyer?->masterOrSelf();
        $sellerLandlordContact = $this->sellerLandlordContact?->masterOrSelf();
        $buyerTenantContact = $this->buyerTenantContact?->masterOrSelf();

        return [
            'id' => $this->id,
            'referenceNo' => $this->referenceNo,
            'name' => $this->name,
            'status' => $this->status,
            'statusLabel' => $this->status ? (Constants::CLOSE_STATUS[$this->status] ?? $this->status) : null,
            'listingType' => $this->listingType,
            'listingTypeLabel' => $this->listingType ? (Constants::CLOSE_LISTING_TYPE[$this->listingType] ?? $this->listingType) : null,
            'listingName' => $this->property?->title,
            'listingReference' => $this->property?->referenceNumber,
            'listing' => $this->property ? [
                'id' => $this->property->id,
                'title' => $this->property->title,
                'creator' => $this->property->creator ? [
                    'id' => $this->property->creator->id,
                    'name' => $this->property->creator->name,
                ] : null,
            ] : null,
            'offer' => $this->offer ? [
                'id' => $this->offer->id,
                'name' => $this->offer->name,
                'referenceNo' => $this->offer->referenceNo,
                'status' => $this->offer->status,
                'offeredAmount' => $this->offer->offeredAmount,
                'listingPrice' => $this->offer->listingPrice,
                'offerDate' => $this->offer->offerDate,
                'offerExpirationDate' => $this->offer->offerExpirationDate,
            ] : null,
            'enquiry' => $this->enquiry ? [
                'id' => $this->enquiry->id,
                'referenceNo' => $this->enquiry->referenceNo,
                'status' => $this->enquiry->status,
                'listingType' => $this->enquiry->listingType,
                'leadSource' => $this->enquiry->leadSource,
            ] : null,
            'buyer' => $buyer ? [
                'id' => $buyer->id,
                'name' => $buyer->name,
                'email' => $buyer->email,
                'phone' => $buyer->phone,
                'dob' => $buyer->dob,
                'nationality' => $buyer->nationality,
                'residencyStatus' => $buyer->residencyStatus,
                'residencyStatusLabel' => $buyer->residencyStatus ? (Constants::USER_RESIDENCY_STATUS[$buyer->residencyStatus] ?? $buyer->residencyStatus) : null,
            ] : null,
            'assignedAgent' => $this->assignedAgent ? [
                'id' => $this->assignedAgent->id,
                'name' => $this->assignedAgent->name,
                'agentCode' => $this->assignedAgent->agentCode,
            ] : null,
            'clientLeadSource' => Constants::leadSourceGroupLabel($this->clientLeadSource),
            'clientNationality' => $this->clientNationality,
            'dob' => $buyer?->dob,
            'nationality' => $buyer?->nationality,
            'residencyStatus' => $buyer?->residencyStatus,
            'residencyStatusLabel' => $buyer?->residencyStatus ? (Constants::USER_RESIDENCY_STATUS[$buyer->residencyStatus] ?? $buyer->residencyStatus) : null,
            'sellerLandlord' => $this->sellerLandlord,
            'ownerFirstName' => $this->property?->creator?->name,
            'sellerLandlordData' => $sellerLandlordContact ? [
                'id' => $sellerLandlordContact->id,
                'name' => $sellerLandlordContact->name,
                'type' => collect(Contact::normalizeContactTypes($sellerLandlordContact->contactType))
                    ->map(fn ($t) => Constants::CONTACT_TYPE[$t] ?? $t)
                    ->implode(', '),
            ] : null,
            'saleRentalPrice' => $this->saleRentalPrice,
            'buyerCommission' => $this->buyerCommission,
            'sellerCommission' => $this->sellerCommission,
            'totalCommission' => $this->totalCommission,
            'representing' => $this->representing,
            'closingProbability' => $this->closingProbability,
            'agentCommission' => $this->agentCommission,
            'companyCommission' => $this->companyCommission,
            'sellerLandlordSideSplitAmount' => $this->sellerLandlordSideSplitAmount,
            'buyerTenantSideSplitAmount' => $this->buyerTenantSideSplitAmount,
            'sellerAgentSplitPercentage' => $this->sellerAgentSplitPercentage,
            'companySellerSplitPercentage' => $this->companySellerSplitPercentage,
            'sellerAgentSplitAmount' => $this->sellerAgentSplitAmount,
            'companySellerSplitAmount' => $this->companySellerSplitAmount,
            'buyerAgentSplitPercentage' => $this->buyerAgentSplitPercentage,
            'companyBuyerSplitPercentage' => $this->companyBuyerSplitPercentage,
            'buyerAgentSplitAmount' => $this->buyerAgentSplitAmount,
            'companyBuyerSplitAmount' => $this->companyBuyerSplitAmount,
            'escrowTitleContact' => $this->escrowTitleContact,
            'buyerTenantAgencyContact' => $this->buyerTenantAgencyContact,
            'mortgageBrokerContact' => $this->mortgageBrokerContact,
            'sellerLandlordSource' => $this->sellerLandlordSource,
            'closingUrlRecord' => $this->closingUrlRecord,
            'owner' => $this->owner,
            'buyerTenant' => $this->buyerTenant,
            'buyerTenantData' => $buyerTenantContact ? [
                'id' => $buyerTenantContact->id,
                'name' => $buyerTenantContact->name,
            ] : null,
            'listingAgencyContact' => $this->listingAgencyContact,
            'targetCloseDate' => $this->targetCloseDate,
            'closingDate' => $this->closingDate,
            'closingChecklist' => $this->closingChecklist,
            'enquiryId' => $this->enquiryId,
            'createdBy' => $this->createdBy,
            'creator' => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null,
            'closingStatus' => collect(Constants::CLOSE_STATUS)->map(function ($label, $key) {
                return ['value' => $label, 'label' => $label];
            })->values(),
            'upcomingActivities' => $this->tasks
                ? $this->tasks->filter(fn ($task) => $task->dueDate >= $now && ! in_array($task->status, Constants::TASK_CLOSED_STATUSES))->values()
                : [],
            'pastActivities' => $this->tasks
                ? $this->tasks->filter(fn ($task) => $task->dueDate < $now || in_array($task->status, Constants::TASK_CLOSED_STATUSES))->values()
                : [],
            'created_at' => convertToTimezone($this->created_at),
            'updated_at' => convertToTimezone($this->updated_at),
        ];
    }
}
