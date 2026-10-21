<?php

namespace App\Http\Resources\PropertyApprovalHistoryResource;

use Illuminate\Http\Resources\Json\JsonResource;

class PropertyApprovalHistoryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'listingId' => $this->listingId,
            'status' => $this->status,
            'comment' => $this->comment,
            'auditBy' => $this->auditBy,
            'auditDate' => $this->auditDate,
            'createdAt' => convertToTimezone($this->created_at),
            'updatedAt' => convertToTimezone($this->updated_at),
            'auditor' => $this->whenLoaded('auditor', function () {
                return [
                    'id' => $this->auditor->id,
                    'name' => $this->auditor->name,
                    'email' => $this->auditor->email,
                ];
            }),
            'property' => $this->whenLoaded('property', function () {
                return [
                    'id' => $this->property->id,
                    'title' => $this->property->title,
                ];
            }),
        ];
    }
}
