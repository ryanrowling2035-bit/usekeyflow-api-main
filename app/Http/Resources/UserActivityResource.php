<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserActivityResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'field' => $this->action,
            'module' => $this->module,
            'moduleId' => $this->moduleId,
            'userId' => $this->userId,
            'originalValue' => $this->oldValues,
            'newValue' => $this->newValues,
            'comments' => $this->description,
            'ipAddress' => $this->ipAddress,
            'created_at' => convertToTimezone($this->created_at),
            'updated_at' => convertToTimezone($this->updated_at),
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
            ],
        ];
    }
}
