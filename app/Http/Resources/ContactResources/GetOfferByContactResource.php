<?php

namespace App\Http\Resources\ContactResources;

use Illuminate\Http\Resources\Json\JsonResource;

class GetOfferByContactResource extends JsonResource
{
    public function toArray($request)
    {
        // Merged-away contacts show as the contact they were merged into.
        $buyer = $this->buyer?->masterOrSelf();
        $physicalOwner = $this->property?->physicalOwner?->masterOrSelf();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'listingId' => $this->listingId,
            'enquiryId' => $this->enquiryId,
            'offeredAmount' => $this->offeredAmount,
            'targetDate' => $this->targetDate,
            'buyerId' => $this->buyerId,
            'status' => $this->status,
            'physicalOwner' => $physicalOwner ? [
                'id' => $physicalOwner->id,
                'name' => $physicalOwner->name,
            ] : null,
            'created_at' => convertToTimezone($this->created_at),
            'buyer' => $buyer ? [
                'id' => $buyer->id,
                'name' => $buyer->name,
            ] : null,
        ];
    }
}
