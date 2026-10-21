<?php

namespace App\Http\Resources\PropertyResource;

use App\Constants\Constants;
use App\Services\PropertyService;
use Illuminate\Http\Resources\Json\JsonResource;

class GetPropertyResource extends JsonResource
{
    public function toArray($request)
    {
        // A physical owner merged into another contact renders as the surviving
        // contact; the listing still stores the alias's physicalOwnerId.
        $physicalOwner = $this->physicalOwner?->masterOrSelf();

        $isFollowing = userIsFollowing(Constants::MODULE_PROPERTY, $this->id);
        $isFavorite = userIsFavorite(Constants::MODULE_PROPERTY, $this->id);

        $pastTasks = [];
        $activeTasks = [];

        if ($this->tasks) {
            foreach ($this->tasks as $task) {
                if (in_array($task->status, Constants::TASK_CLOSED_STATUSES)) {
                    $pastTasks[] = $task;
                } else {
                    $activeTasks[] = $task;
                }
            }
        }

        $mediaCompressed = [];
        if ($this->relationLoaded('mediaCompressed')) {
            foreach ($this->mediaCompressed as $media) {
                $displayPath = $media->originalPath ?? $media->filePath;
                $previewPath = null;
                if (in_array($media->mediaType, ['uploadPhotos', 'uploadVideos'])) {
                    $previewPath = $media->compressedPath ?? $media->compressedPath;
                }
                $thumbnailPath = getImageUrl($media->thumbnailPath);

                $mediaCompressed[] = [
                    'id' => $media->id,
                    'fileName' => $media->fileName,
                    'filePath' => getImageUrl($displayPath),
                    'compressedPath' => getImageUrl($previewPath),
                    'thumbnailPath' => $thumbnailPath,
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
        }

        $metaValues = array_fill_keys(array_keys(Constants::PROPERTY_META_KEYS), null);
        if ($this->relationLoaded('meta')) {
            foreach ($this->meta as $meta) {
                if (! array_key_exists($meta->metaKey, $metaValues)) {
                    continue;
                }
                $decoded = json_decode($meta->metaValue, true);
                $metaValues[$meta->metaKey] = is_array($decoded) ? $decoded : $meta->metaValue;
            }
        }

        return array_merge([
            'id' => $this->id,
            'qrIdentifier' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'referenceNumber' => $this->referenceNumber,
            'category' => $this->category,
            'categoryLabel' => Constants::PROPERTY_CLASSIFICATION_TYPE[$this->category] ?? $this->category,
            'listingType' => $this->listingType,
            'listingTypeLabel' => Constants::PROPERTY_PURPOSE_TYPE[$this->listingType] ?? $this->listingType,
            'sizeArea' => $this->sizeArea,
            'plotSize' => $this->plotSize,
            'bedrooms' => $this->bedrooms,
            'bedroomsLabel' => $this->bedrooms == 0 ? 'Studio' : $this->bedrooms,
            'bathrooms' => formatDecimal($this->bathrooms),
            'completionStatus' => $this->completionStatus,
            'completionStatusLabel' => Constants::COMPLETION_STATUS[$this->completionStatus] ?? $this->completionStatus,
            'price' => $this->price,
            'reraPermitNo' => $this->reraPermitNo,
            'govtQR' => $this->govtQR ? getImageUrl($this->govtQR) : null,
            'agentId' => $this->agentId,
            'agentDetails' => $this->agent ? [
                'id' => $this->agent->id,
                'name' => $this->agent->name,
                'agentProfile' => getImageUrl($this->agent->thumbnailPath) ?? $this->agent->profilePhoto,
                'agentCode' => $this->agent->agentCode,
                'phone' => normalizePhoneE164($this->agent->phone),
                'whatsappNumber' => normalizePhoneE164(optional($this->agent->meta->firstWhere('metaKey', '_whatsappNumber'))->metaValue),
                'email' => $this->agent->email,
                'website' => $this->agent->website,
                'licenseNumber' => optional($this->agent->meta->firstWhere('metaKey', '_licenseNumber'))->metaValue,
            ] : null,
            'listingOwnerId' => $this->listingOwnerId,
            'listingOwner' => $this->listingOwner ? [
                'id' => $this->listingOwner->id,
                'name' => $this->listingOwner->name,
                'email' => $this->listingOwner->email,
                'phone' => normalizePhoneE164($this->listingOwner->phone),
            ] : null,
            'physicalOwnerId' => $physicalOwner?->id,
            'physicalOwner' => $physicalOwner ? [
                'id' => $physicalOwner->id,
                'name' => $physicalOwner->name,
                'phone' => normalizePhoneE164($physicalOwner->phone),
                'email' => $physicalOwner->email,
            ] : null,
            'propertyType' => Constants::canonicalPropertyType($this->pfPropertyType, $this->bayutPropertyType),
            'propertyTypeLabel' => app(PropertyService::class)->getPropertyTypeLabel($this->pfPropertyType, $this->bayutPropertyType),
            'pfCity' => $this->pfCity,
            'pfCommunity' => $this->pfCommunity,
            'community' => $this->community,
            'pfSubCommunity' => $this->pfSubCommunity,
            'subCommunity' => $this->subCommunity,
            'pfBuilding' => $this->pfBuilding,
            'buildingTower' => $this->buildingTower,
            'pfLatitude' => $this->pfLatitude,
            'pfLongitude' => $this->pfLongitude,
            'pfAmenities' => $this->pfAmenities,
            'pfAmenitiesLabels' => $this->pfAmenities ? collect($this->pfAmenities)->map(fn ($key) => Constants::PF_AMENITIES['all'][$key] ?? $key)->values()->toArray() : null,
            'pfLocationBreadcrumb' => implode(' > ', array_filter(array_map(
                fn ($name) => $name ? ucwords(strtolower($name)) : null,
                [
                    $this->city?->name,
                    $this->community?->name,
                    $this->subCommunity?->name,
                    $this->buildingTower?->name,
                ]
            ))) ?: null,
            'bayutLocation' => $this->bayutLocation,
            'bayutLocationId' => $this->bayutLocationId,
            'bayutAmenities' => $this->bayutAmenities,
            'bayutAmenitiesLabels' => $this->bayutAmenities ? collect($this->bayutAmenities)->map(fn ($key) => Constants::PROPERTY_BAYUT_AMENITIES_KEYS[$key] ?? $key)->values()->toArray() : null,
            'pfPriceFrequency' => $this->pfPriceFrequency,
            'pfPriceFrequencyLabel' => $this->pfPriceFrequency ? (Constants::PROPERTY_PRICE_FREQUENCIES[$this->pfPriceFrequency] ?? $this->pfPriceFrequency) : null,
            'pfCheques' => $this->pfCheques,
            'pfChequesLabel' => $this->pfCheques ? (Constants::PF_NUMBER_OF_CHEQUES[$this->pfCheques] ?? $this->pfCheques) : null,
            'bayutOccupancyStatus' => $this->bayutOccupancyStatus,
            'bayutOccupancyStatusLabel' => $this->bayutOccupancyStatus ? (Constants::BAYUT_OCCUPANCY_STATUS[$this->bayutOccupancyStatus] ?? $this->bayutOccupancyStatus) : null,
            'bayutMinimumContractPeriod' => $this->bayutMinimumContractPeriod,
            'bayutVacatingNoticePeriod' => $this->bayutVacatingNoticePeriod,
            'bayutMaintenanceFee' => $this->bayutMaintenanceFee,
            'bayutMaintenanceFeePaidBy' => $this->bayutMaintenanceFeePaidBy,
            'bayutMaintenanceFeePaidByLabel' => $this->bayutMaintenanceFeePaidBy ? (Constants::BAYUT_MAINTENANCE_PAID_BY[$this->bayutMaintenanceFeePaidBy] ?? $this->bayutMaintenanceFeePaidBy) : null,
            'managedType' => $this->managedType,
            'managedTypeLabel' => $this->managedType
                ? implode(', ', array_map(
                    fn ($key) => Constants::PROPERTY_MANAGED_TYPE[$key] ?? $key,
                    array_filter(array_map('trim', explode(',', $this->managedType)))
                ))
                : null,
            // pocketListingDate / upcomingDate come through the $metaValues merge below.
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
            'status' => $this->status,
            'statusLabel' => $this->status ? ((in_array($this->listingType, Constants::PROPERTY_INT_AND_OFF_LISTING_TYPES) ? Constants::PROPERTY_INT_AND_OFF_STATUS : Constants::PROPERTY_STATUS)[$this->status] ?? $this->status) : null,
            'approvalStatus' => $this->approvalStatus,
            'approvalStatusLabel' => $this->approvalStatus ? (Constants::PROPERTY_APPROVAL_STATUS[$this->approvalStatus] ?? $this->approvalStatus) : null,
            'latestApprovalComment' => $this->latestApprovalComment,
            'tasks' => $activeTasks,
            'pastTasks' => $pastTasks,
            'isFollowing' => $isFollowing,
            'isFavorite' => $isFavorite,
            'acceptedOffersCount' => $this->accepted_offers_count ?? 0,
            'media' => $mediaCompressed,
            'propertyVideos' => $this->propertyVideos,
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
            'isOwnedByCurrentAgent' => isAgent() ? ($this->agentId === user()->id || $this->listingOwnerId === user()->id) : true,
        ], $metaValues);
    }
}
