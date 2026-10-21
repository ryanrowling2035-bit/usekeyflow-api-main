<?php

namespace App\Http\Resources\ContactResources;

use App\Constants\Constants;
use Illuminate\Http\Resources\Json\JsonResource;

class GetEnquiryByContactResource extends JsonResource
{
    public function toArray($request)
    {
        // A merged-away contact shows as the contact it was merged into.
        $contact = $this->contact?->masterOrSelf();

        return [
            'id' => $this->id,
            'referenceNo' => $this->referenceNo,
            'agentId' => $this->agentId,
            'agent' => $this->agent ? [
                'id' => $this->agent->id,
                'name' => $this->agent->name,
            ] : null,
            'contactId' => $this->contactId,
            'listingId' => $this->listingId,
            'listing' => $this->listing ? [
                'id' => $this->listing->id,
                'title' => $this->listing->title,
            ] : null,
            'enquiryType' => $this->enquiryType ? (Constants::ENQUIRY_TYPE[strtolower($this->enquiryType)] ?? $this->enquiryType) : null,
            'status' => $this->status ? (Constants::ENQUIRY_STATUS[$this->status] ?? $this->status) : null,
            'phone' => normalizePhoneE164($this->phone),
            'priceMin' => $this->priceMin,
            'priceMax' => $this->priceMax,
            'listingType' => $this->listingType ? (Constants::PROPERTY_PURPOSE_TYPE[$this->listingType] ?? $this->listingType) : null,
            'created_at' => convertToTimezone($this->created_at),
            'contact' => $contact ? [
                'id' => $contact->id,
                'name' => $contact->name,
            ] : null,
        ];
    }
}
