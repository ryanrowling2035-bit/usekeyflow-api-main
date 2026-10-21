<?php

namespace App\Http\Resources\LeadSourceResource;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'createdAt' => convertToTimezone($this->created_at),
            'updatedAt' => convertToTimezone($this->updated_at),
            'deletedAt' => convertToTimezone($this->deleted_at),
        ];
    }
}
