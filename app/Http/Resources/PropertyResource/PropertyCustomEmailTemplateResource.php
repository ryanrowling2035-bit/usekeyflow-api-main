<?php

namespace App\Http\Resources\PropertyResource;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyCustomEmailTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ownerEmail' => $this->ownerEmail,
            'subject' => $this->subject,
            'emailEontent' => $this->emailContent,
            'fileName' => $this->fileName,
            'filePath' => $this->filePath,
            'fileUrl' => getImageUrl($this->filePath),
            'created_at' => convertToTimezone($this->created_at),

            'property' => [
                'id' => $this->property->id,
                'title' => $this->property->title,
            ],
        ];
    }
}
