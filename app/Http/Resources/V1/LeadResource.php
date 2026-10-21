<?php

namespace App\Http\Resources\V1;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    /**
     * Transform the lead resource into a snake_case array with ISO 8601 UTC dates.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_no' => $this->referenceNo,
            'phone' => $this->phone,
            'status' => $this->status,
            'lead_source' => $this->leadSource,
            'technical_source' => $this->technicalSource,
            'enquiry_type' => $this->enquiryType,
            'listing_type' => $this->listingType,
            'lead_rating' => $this->leadRating,
            'lead_qualification' => $this->leadQualification,
            'investment_motivation' => $this->investmentMotivation,
            'budget' => $this->budget,
            'actual_price' => $this->actualPrice,
            'price_min' => $this->priceMin,
            'price_max' => $this->priceMax,
            'size_min' => $this->sizeMin,
            'size_max' => $this->sizeMax,
            'bedrooms_min' => $this->bedroomsMin,
            'bedrooms_max' => $this->bedroomsMax,
            'bathrooms_min' => $this->bathroomsMin,
            'bathrooms_max' => $this->bathroomsMax,
            'city' => $this->city,
            'community' => $this->community,
            'project_name' => $this->projectName,
            'comments' => $this->comments,
            'contact' => $this->whenLoaded('contact', function () {
                if (! $this->contact) {
                    return null;
                }

                return [
                    'id' => $this->contact->id,
                    'name' => $this->contact->name,
                    'email' => $this->contact->email,
                    'phone' => $this->contact->phone,
                    'nationality' => $this->contact->nationality,
                ];
            }),
            'agent' => $this->whenLoaded('agent', function () {
                if (! $this->agent) {
                    return null;
                }

                return [
                    'id' => $this->agent->id,
                    'name' => $this->agent->name,
                    'email' => $this->agent->email,
                    'phone' => $this->agent->phone,
                    'agent_code' => $this->agent->agentCode,
                ];
            }),
            'listing' => $this->whenLoaded('listing', function () {
                if (! $this->listing) {
                    return null;
                }

                return [
                    'id' => $this->listing->id,
                    'reference_number' => $this->listing->referenceNumber,
                    'title' => $this->listing->title,
                    'price' => $this->listing->price,
                    'listing_type' => $this->listing->listingType,
                ];
            }),
            'created_at' => $this->toIsoUtc($this->created_at),
            'updated_at' => $this->toIsoUtc($this->updated_at),
        ];
    }

    protected function toIsoUtc($date): ?string
    {
        if (! $date) {
            return null;
        }

        return Carbon::parse($date)->utc()->toIso8601ZuluString();
    }
}
