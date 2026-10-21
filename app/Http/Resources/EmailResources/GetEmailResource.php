<?php

namespace App\Http\Resources\EmailResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GetEmailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'subject' => $this->subject,
            'emailContent' => $this->emailContent,
            'macro' => $this->macro,
            'status' => $this->status,
            'createdBy' => $this->createdBy,
            'created_at' => convertToTimezone($this->created_at),
        ];
    }
}
