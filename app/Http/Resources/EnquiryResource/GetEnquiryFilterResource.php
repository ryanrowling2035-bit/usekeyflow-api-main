<?php

namespace App\Http\Resources\EnquiryResource;

use Illuminate\Http\Resources\Json\JsonResource;

class GetEnquiryFilterResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'module' => $this->module,
            'filters' => $this->filters,
            'fields' => $this->fields,
            'created_at' => convertToTimezone($this->created_at),
            'updated_at' => convertToTimezone($this->updated_at),
        ];
    }
}
