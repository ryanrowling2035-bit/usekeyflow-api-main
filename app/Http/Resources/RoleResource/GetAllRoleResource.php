<?php

namespace App\Http\Resources\RoleResource;

use App\Constants\Constants;
use Illuminate\Http\Resources\Json\JsonResource;

class GetAllRoleResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status ? (Constants::ROLE_STATUS[$this->status] ?? $this->status) : null,
            'description' => $this->description,
            'createdAt' => convertToTimezone($this->created_at),
            'updatedAt' => convertToTimezone($this->updated_at),
            'createdBy' => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null,

        ];
    }
}
