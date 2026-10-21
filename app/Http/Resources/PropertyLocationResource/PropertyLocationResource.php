<?php

namespace App\Http\Resources\PropertyLocationResource;

use App\Models\PropertyCommunity;
use App\Models\PropertySubCommunity;
use App\Models\PropertyTower;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $resource = $this->resource;

        if (is_array($resource)) {
            return $resource;
        }

        if ($resource instanceof PropertyCommunity) {
            return [
                'id' => $resource->id,
                'cityId' => $resource->cityId,
                'communityId' => $resource->communityId,
                'name' => $resource->name,
                'communityCode' => $resource->communityCode,
                'type' => $resource->type,
                'latitude' => $resource->latitude,
                'longitude' => $resource->longitude,
            ];
        }

        if ($resource instanceof PropertySubCommunity) {
            return [
                'id' => $resource->id,
                'communityId' => $resource->communityId,
                'subCommunityId' => $resource->propertySubCommunityId,
                'name' => $resource->name,
                'type' => $resource->type,
                'latitude' => $resource->latitude,
                'longitude' => $resource->longitude,
            ];
        }

        if ($resource instanceof PropertyTower) {
            return [
                'id' => $resource->id,
                'communityId' => $resource->communityId,
                'subCommunityId' => $resource->subCommunityId,
                'towerId' => $resource->propertyTowerId,
                'name' => $resource->name,
                'type' => $resource->type,
                'latitude' => $resource->latitude,
                'longitude' => $resource->longitude,
            ];
        }

        return [];
    }
}
