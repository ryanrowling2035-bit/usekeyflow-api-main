<?php

namespace App\Http\Resources\NotificationResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnreadNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'createdAt' => convertToTimezone($this->created_at),
        ];
    }
}
