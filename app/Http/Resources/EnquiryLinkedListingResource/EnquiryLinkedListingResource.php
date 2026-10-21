<?php

namespace App\Http\Resources\EnquiryLinkedListingResource;

use Illuminate\Http\Resources\Json\JsonResource;

class EnquiryLinkedListingResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'enquiryId' => $this->enquiryId,
            'propertyId' => $this->propertyId,
            'createdBy' => $this->createdBy,
            'enquiry' => $this->whenLoaded('enquiry'),
            'property' => $this->whenLoaded('property'),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                    'email' => $this->creator->email,
                ];
            }),
            'createdAt' => convertToTimezone($this->created_at),
            'updatedAt' => convertToTimezone($this->updated_at),
        ];
    }
}
