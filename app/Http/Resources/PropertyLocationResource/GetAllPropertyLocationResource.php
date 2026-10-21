<?php

namespace App\Http\Resources\PropertyLocationResource;

use Illuminate\Http\Resources\Json\JsonResource;

class GetAllPropertyLocationResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'locationId' => $this->locationId,
            'name' => $this->name,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
