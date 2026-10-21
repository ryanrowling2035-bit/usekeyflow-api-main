<?php

namespace App\Http\Resources\PropertyResource;

class GetOthersPropertyResource extends GetPropertyResource
{
    public function toArray($request)
    {
        $data = parent::toArray($request);

        unset($data['physicalOwnerId'], $data['physicalOwner']);

        $data['isOwnedByCurrentAgent'] = false;

        return $data;
    }
}
