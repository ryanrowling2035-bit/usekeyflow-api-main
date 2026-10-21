<?php

namespace App\Http\Resources\ContactResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GetContactByUserId extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => normalizePhoneE164($this->phone),
            'email' => $this->email,
            'created_at' => convertToTimezone($this->created_at),
        ];
    }
}
