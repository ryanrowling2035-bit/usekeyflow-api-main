<?php

namespace App\Http\Resources\V1;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ListingResource extends JsonResource
{
    /**
     * Transform the listing resource into a snake_case array with ISO 8601 UTC dates.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $primaryMedia = $this->relationLoaded('mediaThumbnails')
            ? $this->mediaThumbnails->first()
            : null;

        $primaryImageUrl = $primaryMedia
            ? getImageUrl($primaryMedia->compressedPath ?? $primaryMedia->originalPath ?? $primaryMedia->filePath)
            : null;

        $images = [];
        if ($this->relationLoaded('mediaThumbnails') && $this->mediaThumbnails) {
            $images = $this->mediaThumbnails->map(function ($media) {
                return [
                    'id' => $media->id,
                    'url' => getImageUrl($media->originalPath ?? $media->filePath ?? $media->compressedPath),
                    'thumbnail_url' => getImageUrl($media->compressedPath ?? $media->originalPath ?? $media->filePath),
                    'order' => $media->ordering,
                ];
            })->values()->all();
        }

        return [
            'id' => $this->id,
            'reference_number' => $this->referenceNumber,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'listing_type' => $this->listingType,
            'status' => $this->status,
            'completion_status' => $this->completionStatus,
            'pricing' => [
                'price' => $this->price !== null ? (float) $this->price : null,
                'annual_rent' => $this->annualRent !== null ? (float) $this->annualRent : null,
                'sale_price' => $this->salePrice !== null ? (float) $this->salePrice : null,
                'deposit' => $this->deposit !== null ? (float) $this->deposit : null,
                'price_frequency' => $this->pfPriceFrequency,
                'cheques' => $this->pfCheques,
            ],
            'specs' => [
                'bedrooms' => $this->bedrooms,
                'bathrooms' => $this->bathrooms,
                'size_area' => $this->sizeArea !== null ? (float) $this->sizeArea : null,
                'plot_size' => $this->plotSize !== null ? (float) $this->plotSize : null,
                'parking_spaces' => $this->parkingSpaces !== null ? (int) $this->parkingSpaces : null,
                'year_built' => $this->yearBuilt,
                'view' => $this->view,
            ],
            'regulatory' => [
                'rera_permit_no' => $this->reraPermitNo,
                'govt_qr' => $this->govtQR,
                'title_deed_number' => $this->titleDeedNumber,
            ],
            'location' => [
                'city' => $this->city?->name ?? $this->pfCity,
                'community' => $this->community?->name ?? $this->pfCommunity,
                'sub_community' => $this->subCommunity?->name ?? $this->pfSubCommunity,
                'building' => $this->buildingTower?->name ?? $this->pfBuilding,
                'latitude' => $this->pfLatitude,
                'longitude' => $this->pfLongitude,
            ],
            'amenities' => $this->pfAmenities ?: ($this->bayutAmenities ?: []),
            'primary_image' => $primaryImageUrl,
            'images' => $images,
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
            'published_at' => $this->toIsoUtc($this->pubDate ?? $this->publishedAt),
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
