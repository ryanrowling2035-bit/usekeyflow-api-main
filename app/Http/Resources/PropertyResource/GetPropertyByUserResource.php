<?php

namespace App\Http\Resources\PropertyResource;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GetPropertyByUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'community' => $this->community,
            'status' => $this->status,
            'price' => $this->price,
        ];
    }
}
