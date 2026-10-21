<?php

namespace App\Http\Resources\ContactResources;

use App\Constants\Constants;
use Illuminate\Http\Resources\Json\JsonResource;

class GetListingByContactResource extends JsonResource
{
    public function toArray($request)
    {
        $firstThumbnail = null;
        if ($this->relationLoaded('mediaThumbnails') && $this->mediaThumbnails->isNotEmpty()) {
            $firstThumbnail = getImageUrl($this->mediaThumbnails->first()->thumbnailPath);
        }

        return [
            'id' => $this->id,
            'referenceNumber' => $this->referenceNumber,
            'title' => $this->title,
            'community' => $this->community,
            'subCommunity' => $this->subCommunity,
            'bedrooms' => $this->bedrooms,
            'bedroomsLabel' => $this->bedrooms == 0 ? 'Studio' : $this->bedrooms,
            'price' => $this->price,
            'status' => (in_array($this->listingType, Constants::PROPERTY_INT_AND_OFF_LISTING_TYPES) ? Constants::PROPERTY_INT_AND_OFF_STATUS : Constants::PROPERTY_STATUS)[$this->status] ?? null,
            'listingType' => Constants::PROPERTY_PURPOSE_TYPE[$this->listingType] ?? null,
            'createdBy' => $this->createdBy,
            'created_at' => convertToTimezone($this->created_at),
            'creator' => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null,
            'agent' => $this->agent ? [
                'id' => $this->agent->id,
                'name' => $this->agent->name,
                'agentCode' => $this->agent->agentCode,
            ] : null,
            'thumbnailPath' => $firstThumbnail,
        ];
    }
}
