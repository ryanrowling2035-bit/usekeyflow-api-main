<?php

namespace App\Http\Resources\ContactResources;

use Illuminate\Http\Resources\Json\JsonResource;

class GetClosingByContactResource extends JsonResource
{
    public function toArray($request)
    {
        // A merged-away contact shows as the contact it was merged into.
        $buyer = $this->buyer?->masterOrSelf();

        return [
            'id' => $this->id,
            'listingId' => $this->listingId,
            'enquiryId' => $this->enquiryId,
            'offerId' => $this->offerId,
            'buyerId' => $this->buyerId,
            'status' => $this->status,
            'listingType' => $this->listingType,
            'targetCloseDate' => $this->targetCloseDate,
            'closingDate' => $this->closingDate,
            'assignedAgentId' => $this->assignedAgentId,
            'created_at' => convertToTimezone($this->created_at),
            'buyer' => $buyer ? [
                'id' => $buyer->id,
                'name' => $buyer->name,
            ] : null,
        ];
    }
}
