<?php

namespace App\Http\Resources\GlobalSettingResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GlobalSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'settingKey' => $this->settingKey,
            'settingValue' => $this->settingValue,
            'createdBy' => $this->createdBy,
            'createdAt' => convertToTimezone($this->created_at),
        ];
    }
}
