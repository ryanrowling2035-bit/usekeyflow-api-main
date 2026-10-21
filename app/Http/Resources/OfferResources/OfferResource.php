<?php

namespace App\Http\Resources\OfferResources;

use App\Constants\Constants;
use Illuminate\Http\Resources\Json\JsonResource;

class OfferResource extends JsonResource
{
    public function toArray($request)
    {
        $now = now();

        // Contacts merged into another render as the surviving contact — the offer
        // still stores the alias's buyerId, since merging moves nothing.
        $buyer = $this->buyer?->masterOrSelf();
        $physicalOwner = $this->property?->physicalOwner?->masterOrSelf();
        $sellerLandlordContact = $this->sellerLandlordContact?->masterOrSelf();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'referenceNo' => $this->referenceNo,
            'status' => $this->status,
            'statusLabel' => $this->status ? (Constants::OFFER_STATUS[$this->status] ?? $this->status) : null,
            'listingType' => $this->listingType,
            'listingTypeLabel' => $this->listingType ? (Constants::PROPERTY_PURPOSE_TYPE[$this->listingType] ?? $this->listingType) : null,
            'sellerLandlord' => $this->sellerLandlord,
            'sellerLandlordData' => $sellerLandlordContact ? [
                'id' => $sellerLandlordContact->id,
                'name' => $sellerLandlordContact->name,
            ] : null,
            'buyer' => $buyer ? [
                'id' => $buyer->id,
                'name' => $buyer->name,
                'email' => $buyer->email,
                'phone' => normalizePhoneE164($buyer->phone),
                'nationality' => $buyer->nationality,
                'residencyStatus' => $buyer->residencyStatus,
                'residencyStatusLabel' => $buyer->residencyStatus ? (Constants::USER_RESIDENCY_STATUS[$buyer->residencyStatus] ?? $buyer->residencyStatus) : null,
            ] : null,
            'leaseTerms' => $this->leaseTerms,
            'nationality' => $buyer?->nationality,
            'offerDate' => $this->offerDate,
            'offerExpirationDate' => $this->offerExpirationDate,
            'created_at' => convertToTimezone($this->created_at),
            'updated_at' => convertToTimezone($this->updated_at),
            'targetDate' => $this->targetDate,
            'offeredAmount' => $this->offeredAmount,
            'listingPrice' => $this->listingPrice,
            'potentialCommission' => $this->potentialCommission,
            'deposits' => $this->deposits,
            'terms' => $this->terms,
            'internalNotes' => $this->whenLoaded('internalNotes', function () {
                return optional(
                    $this->internalNotes
                        ->where('type', 'internalNotes')
                        ->where('parent', 0)
                        ->sortByDesc('created_at')
                        ->first()
                )->content;
            }),
            'enquiryId' => $this->enquiryId,
            'enquiry' => $this->enquiry ? [
                'id' => $this->enquiry->id,
                'referenceNo' => $this->enquiry->referenceNo,
                'status' => $this->enquiry->status,
                'listingType' => $this->enquiry->listingType,
                'leadSource' => $this->enquiry->leadSource,
            ] : null,
            'physicalOwner' => $physicalOwner ? [
                'id' => $physicalOwner->id,
                'name' => $physicalOwner->name,
            ] : null,
            'createdBy' => $this->createdBy,
            'assignedAgent' => $this->assignedAgent ? [
                'id' => $this->assignedAgent->id,
                'name' => $this->assignedAgent->name,
                'agentCode' => $this->assignedAgent->agentCode,
            ] : null,
            'listing' => $this->property ? [
                'id' => $this->property->id,
                'title' => $this->property->title,
                'referenceNumber' => $this->property?->referenceNumber,
                'price' => $this->property?->price,
            ] : null,
            'renter' => $buyer?->name,
            'offeredPrice' => $this->offeredAmount,
            'upcomingActivities' => $this->tasks
                ? $this->tasks->filter(fn ($task) => $task->dueDate >= $now && ! in_array($task->status, Constants::TASK_CLOSED_STATUSES))->values()
                : [],
            'pastActivities' => $this->tasks
                ? $this->tasks->filter(fn ($task) => $task->dueDate < $now || in_array($task->status, Constants::TASK_CLOSED_STATUSES))->values()
                : [],
        ];
    }
}
