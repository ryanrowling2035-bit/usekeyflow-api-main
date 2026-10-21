<?php

namespace App\Http\Resources\V1;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentResource extends JsonResource
{
    /**
     * Transform the agent resource into a snake_case array with ISO 8601 UTC dates.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $avatar = $this->thumbnailPath ?? $this->profilePhoto;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'agent_code' => $this->agentCode,
            'agent_type' => $this->agentType,
            'designation' => $this->designation,
            'department' => $this->department,
            'status' => $this->status ? strtolower($this->status) : null,
            'is_available' => (bool) $this->isAvailable,
            'avatar_url' => $avatar ? getImageUrl($avatar) : null,
            'active_properties_count' => $this->activeProperties !== null ? (int) $this->activeProperties : null,
            'assigned_leads_count' => $this->assignedLeads !== null ? (int) $this->assignedLeads : null,
            'created_at' => $this->toIsoUtc($this->created_at),
            'updated_at' => $this->toIsoUtc($this->updated_at),
        ];
    }

    protected function toIsoUtc($date): ?string
    {
        if (! $date) {
            return null;
        }

        return Carbon::parse($date)->utc()->toIso8601ZuluString();
    }
}
