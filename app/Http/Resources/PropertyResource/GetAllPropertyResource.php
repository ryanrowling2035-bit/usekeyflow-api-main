<?php

namespace App\Http\Resources\PropertyResource;

use App\Constants\Constants;
use App\Services\PropertyService;
use Illuminate\Http\Resources\Json\JsonResource;

class GetAllPropertyResource extends JsonResource
{
    public function toArray($request)
    {
        // A physical owner merged into another contact renders as the surviving
        // contact; the listing still stores the alias's physicalOwnerId.
        $physicalOwner = $this->physicalOwner?->masterOrSelf();

        $isFollowing = userIsFollowing(Constants::MODULE_PROPERTY, $this->id);
        $isFavorite = userIsFavorite(Constants::MODULE_PROPERTY, $this->id);

        // Listing cards need only one representative photo: primaryMediaThumbnail is the
        // single lowest-ordering image (loaded by the api/properties list and favorites).
        $media = $this->primaryMediaThumbnail;
        $mediaThumbnails = [];
        if ($media) {
            $previewPath = in_array($media->mediaType, ['uploadPhotos', 'uploadVideos'])
                ? $media->compressedPath
                : null;

            $mediaThumbnails[] = [
                'id' => $media->id,
                'fileName' => $media->fileName,
                'filePath' => getImageUrl($media->originalPath ?? $media->filePath),
                'compressedPath' => getImageUrl($previewPath),
                'thumbnailPath' => getImageUrl($media->thumbnailPath),
                'mediaType' => $media->mediaType,
                'ordering' => $media->ordering,
                'category' => $media->category,
                'isWebsite' => $media->isWebsite,
                'isPortals' => $media->isPortals,
                'isFlyer' => $media->isFlyer,
                'created_at' => convertToTimezone($media->created_at),
                'updated_at' => convertToTimezone($media->updated_at),
            ];
        }

        // Off-plan / International projects carry no single `price`: their pricing lives in the
        // six unitTypePriceN meta slots, which left the listings table's Price column blank.
        // Fall back to the span across those slots ("min - max", or the bare figure when every
        // slot agrees) so the same `price` key stays the one thing the column has to read.
        $unitPrices = $this->unitTypePrices();
        $unitPriceRange = null;
        if ($unitPrices) {
            $priceMin = $this->asPrice(min($unitPrices));
            $priceMax = $this->asPrice(max($unitPrices));
            $unitPriceRange = $priceMin === $priceMax ? $priceMin : "{$priceMin} - {$priceMax}";
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'referenceNumber' => $this->referenceNumber,
            'category' => Constants::PROPERTY_CLASSIFICATION_TYPE[$this->category] ?? $this->category,
            'listingType' => Constants::PROPERTY_PURPOSE_TYPE[$this->listingType] ?? $this->listingType,
            'sizeArea' => $this->sizeArea,
            'plotSize' => $this->plotSize,
            'bedrooms' => $this->bedrooms,
            'bedroomsLabel' => $this->bedrooms == 0 ? 'Studio' : $this->bedrooms,
            'bathrooms' => formatDecimal($this->bathrooms),
            'completionStatus' => Constants::COMPLETION_STATUS[$this->completionStatus] ?? $this->completionStatus,
            'price' => $this->price ?: $unitPriceRange,
            'reraPermitNo' => $this->reraPermitNo,
            // The reraPermitNo field doubles as a DTCM Code for Vacation Homes
            // (listingType === 'vacationHomes') and a RERA Permit for every other
            // listing type. Expose it under two dedicated keys so the listings
            // table can offer a "RERA Permit #" and a "DTCM #" column, each shown
            // only for its own listing type. reraPermitNo is kept as-is for other
            // consumers (e.g. the PDF export).
            'reraPermitNumber' => strtolower($this->listingType ?? '') === 'vacationhomes'
                ? null
                : $this->reraPermitNo,
            'dtcmNumber' => strtolower($this->listingType ?? '') === 'vacationhomes'
                ? $this->reraPermitNo
                : null,
            'govtQR' => $this->govtQR ? getImageUrl($this->govtQR) : null,
            'agentId' => $this->agentId,
            'agentDetails' => $this->agent ? [
                'id' => $this->agent->id,
                'name' => $this->agent->name,
                'agentCode' => $this->agent->agentCode,
            ] : null,
            'listingOwnerId' => $this->listingOwnerId,
            'listingOwner' => $this->listingOwner ? [
                'id' => $this->listingOwner->id,
                'name' => $this->listingOwner->name,
                'phone' => normalizePhoneE164($this->listingOwner->phone),
            ] : null,
            'physicalOwnerId' => $physicalOwner?->id,
            'physicalOwner' => $physicalOwner ? [
                'id' => $physicalOwner->id,
                'name' => $physicalOwner->name,
            ] : null,
            'propertyType' => Constants::canonicalPropertyType($this->pfPropertyType, $this->bayutPropertyType),
            'propertyTypeLabel' => app(PropertyService::class)->getPropertyTypeLabel($this->pfPropertyType, $this->bayutPropertyType),
            'pfCity' => $this->pfCity,
            'city' => $this->city,
            'pfCommunity' => $this->pfCommunity,
            'community' => $this->community,
            'pfSubCommunity' => $this->pfSubCommunity,
            'subCommunity' => $this->subCommunity,
            'pfBuilding' => $this->pfBuilding,
            'buildingTower' => $this->buildingTower,
            'pfLatitude' => $this->pfLatitude,
            'pfLongitude' => $this->pfLongitude,
            'pfAmenities' => $this->pfAmenities ? collect($this->pfAmenities)->map(fn ($key) => Constants::PF_AMENITIES['all'][$key] ?? $key)->values()->toArray() : null,
            'bayutLocation' => $this->bayutLocation,
            'bayutLocationId' => $this->bayutLocationId,
            'bayutAmenities' => $this->bayutAmenities ? collect($this->bayutAmenities)->map(fn ($key) => Constants::PROPERTY_BAYUT_AMENITIES_KEYS[$key] ?? $key)->values()->toArray() : null,
            'pfPriceFrequency' => $this->pfPriceFrequency ? (Constants::PROPERTY_PRICE_FREQUENCIES[$this->pfPriceFrequency] ?? $this->pfPriceFrequency) : null,
            'pfCheques' => $this->pfCheques ? (Constants::PF_NUMBER_OF_CHEQUES[$this->pfCheques] ?? $this->pfCheques) : null,
            'bayutOccupancyStatus' => $this->bayutOccupancyStatus ? (Constants::BAYUT_OCCUPANCY_STATUS[$this->bayutOccupancyStatus] ?? $this->bayutOccupancyStatus) : null,
            'bayutMinimumContractPeriod' => $this->bayutMinimumContractPeriod,
            'bayutVacatingNoticePeriod' => $this->bayutVacatingNoticePeriod,
            'bayutMaintenanceFee' => $this->bayutMaintenanceFee,
            'bayutMaintenanceFeePaidBy' => $this->bayutMaintenanceFeePaidBy ? (Constants::BAYUT_MAINTENANCE_PAID_BY[$this->bayutMaintenanceFeePaidBy] ?? $this->bayutMaintenanceFeePaidBy) : null,
            'managedType' => $this->managedType,
            'managedTypeLabel' => $this->managedType
                ? implode(', ', array_map(
                    fn ($key) => Constants::PROPERTY_MANAGED_TYPE[$key] ?? $key,
                    array_filter(array_map('trim', explode(',', $this->managedType)))
                ))
                : null,
            'pocketListingDate' => $this->metaValue(Constants::PROPERTY_META_KEYS['pocketListingDate']),
            'upcomingDate' => $this->metaValue(Constants::PROPERTY_META_KEYS['upcomingDate']),
            'annualRent' => $this->annualRent,
            'salePrice' => $this->salePrice,
            'titleDeedNumber' => $this->titleDeedNumber,
            'access' => $this->access,
            'listingLeadSource2' => $this->listingLeadSource2,
            'view' => $this->view,
            'developmentText' => $this->developmentText,
            'projectText' => $this->projectText,
            'deposit' => $this->deposit,
            'sold' => (bool) $this->sold,
            'agencyFee' => $this->agencyFee,
            'agencyFeePercent' => $this->agencyFeePercent,
            'ownershipTerm' => $this->ownershipTerm,
            'yearBuilt' => $this->yearBuilt,
            'parkingSpaces' => $this->parkingSpaces,
            'pubDate' => $this->pubDate,
            'createdBy' => $this->createdBy,
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                    'email' => $this->creator->email,
                ];
            }),
            'status' => $this->status ? ((in_array($this->listingType, Constants::PROPERTY_INT_AND_OFF_LISTING_TYPES) ? Constants::PROPERTY_INT_AND_OFF_STATUS : Constants::PROPERTY_STATUS)[$this->status] ?? $this->status) : null,
            'approvalStatus' => $this->approvalStatus ? (Constants::PROPERTY_APPROVAL_STATUS[$this->approvalStatus] ?? $this->approvalStatus) : null,
            'media' => $mediaThumbnails,
            'isFollowing' => $isFollowing,
            'isFavorite' => $isFavorite,
            'acceptedOffersCount' => $this->accepted_offers_count ?? 0,
            'adminComments' => $this->whenLoaded('internalNotes', function () {
                return $this->internalNotes->where('type', 'adminComments')->where('module', Constants::MODULE_PROPERTY)->where('moduleId', $this->id)->sortByDesc('created_at')->values();
            }),
            'portalNotes' => $this->whenLoaded('internalNotes', function () {
                return $this->internalNotes->where('type', 'portalNotes')->where('module', Constants::MODULE_PROPERTY)->where('moduleId', $this->id)->sortByDesc('created_at')->values();
            }),
            'propertyMetaDetails' => $this->whenLoaded('meta', function () {
                $formatted = [];
                foreach ($this->meta as $meta) {
                    $value = $meta->metaValue;
                    $decoded = json_decode($value, true);
                    $formatted[$meta->metaKey] = is_array($decoded) ? $decoded : $value;
                }

                return $formatted;
            }),
            'publishedAt' => convertToTimezone($this->publishedAt) ?? null,
            'created_at' => convertToTimezone($this->created_at),
            'updated_at' => convertToTimezone($this->updated_at),
        ];
    }

    /** Numeric unitTypePriceN meta values — only off-plan / international projects have these. */
    private function unitTypePrices(): array
    {
        $prices = [];
        foreach (range(1, 6) as $n) {
            $value = $this->metaValue(Constants::PROPERTY_META_KEYS["unitTypePrice{$n}"]);
            if (is_numeric($value)) {
                $prices[] = (float) $value;
            }
        }

        return $prices;
    }

    /** Same decimal(15,2) string shape the `price` column returns, so both read alike. */
    private function asPrice(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /** Single meta key off the eager-loaded meta relation, null when absent. */
    private function metaValue(string $metaKey): ?string
    {
        if (! $this->relationLoaded('meta')) {
            return null;
        }

        return optional($this->meta->firstWhere('metaKey', $metaKey))->metaValue;
    }
}
