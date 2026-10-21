<?php

namespace App\Http\Resources\TeamResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GetTeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'agents' => $this->whenLoaded('members', function () {
                return $this->members
                    ->filter(fn ($member) => $member->user?->getRoleName() === 'Agent')
                    ->values()
                    ->map(fn ($member) => $this->formatMember($member));
            }),
            'managers' => $this->whenLoaded('members', function () {
                return $this->members
                    ->filter(fn ($member) => $member->user?->getRoleName() !== 'Agent')
                    ->values()
                    ->map(fn ($member) => $this->formatMember($member));
            }),
            'created_at' => convertToTimezone($this->created_at),
            'updated_at' => convertToTimezone($this->updated_at),
        ];
    }

    private function formatMember($member): array
    {
        return [
            'id' => $member->id,
            'userId' => $member->userId,
            'createdBy' => $member->createdBy,
            'created_at' => convertToTimezone($member->created_at),
            'user' => $member->user ? [
                'id' => $member->user->id,
                'name' => $member->user->name,
            ] : null,
            'creator' => $member->creator ? [
                'id' => $member->creator->id,
                'name' => $member->creator->name,
            ] : null,
        ];
    }
}
