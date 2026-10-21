<?php

namespace App\Http\Resources\FollowResources;

use App\Constants\Constants;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowResource extends JsonResource
{
    public function toArray($request)
    {
        $moduleData = null;

        if (strcasecmp((string) $this->module, Constants::MODULE_PROPERTY) === 0) {
            $moduleData = $this->whenLoaded(Constants::MODULE_PROPERTY);
        } elseif (strcasecmp((string) $this->module, Constants::MODULE_ENQUIRY) === 0) {
            $moduleData = $this->whenLoaded(Constants::MODULE_ENQUIRY);
        }

        return [
            'id' => $this->id,
            'module' => $this->module,
            'moduleId' => $this->moduleId,
            'moduleData' => $moduleData,
            'createdAt' => convertToTimezone($this->created_at),
        ];
    }
}
