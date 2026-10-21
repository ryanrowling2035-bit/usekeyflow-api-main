<?php

namespace App\Http\Resources\NotificationResources;

use App\Constants\Constants;
use App\Models\Boost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'module' => $this->module,
            'moduleId' => $this->moduleId,
            'related' => $this->resolveRelated(),
            // 'actor' => $this->whenLoaded('actor', function () {
            //     return [
            //         'id' => $this->actor->id,
            //         'name' => $this->actor->name,
            //         'profilePhoto' => $this->actor->profilePhoto,
            //     ];
            // }),
            'data' => $this->data,
            'isRead' => $this->isRead,
            'readAt' => $this->readAt?->toISOString(),
            'createdAt' => convertToTimezone($this->created_at),
        ];
    }

    private function resolveRelated(): ?array
    {
        if ($this->module === Constants::MODULE_BOOST && $this->moduleId) {
            $boost = Boost::find($this->moduleId);

            if ($boost && $boost->propertyId) {
                return [
                    'module' => Constants::MODULE_PROPERTY,
                    'moduleId' => $boost->propertyId,
                ];
            }
        }

        return null;
    }
}
