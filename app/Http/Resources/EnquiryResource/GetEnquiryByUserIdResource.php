<?php

namespace App\Http\Resources\EnquiryResource;

use Illuminate\Http\Resources\Json\JsonResource;

class GetEnquiryByUserIdResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'referenceNo' => $this->referenceNo,
            'name' => $this->agent?->name,
            'status' => $this->status,
            'created_at' => convertToTimezone($this->created_at),
            'leadSource' => $this->leadSource,
            'isOwnedByCurrentAgent' => isAgent() ? ($this->agentId === user()->id) : true,
        ];
    }
}
