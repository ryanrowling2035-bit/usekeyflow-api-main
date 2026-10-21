<?php

namespace App\Http\Resources\TeamResources;

use App\Constants\Constants;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GetAllTeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'managers' => $this->whenLoaded('members', function () {
                return $this->members->filter(fn ($member) => $member->user && $member->user->role?->name !== Constants::AGENT)
                    ->values()
                    ->map(function ($member) {
                        return [
                            'id' => $member->id,
                            'userId' => $member->userId,
                            'user' => $member->user ? [
                                'id' => $member->user->id,
                                'name' => $member->user->name,
                            ] : null,
                        ];
                    });
            }),
            'agents' => $this->whenLoaded('members', function () {
                return $this->members->filter(fn ($member) => $member->user?->role?->name === Constants::AGENT)
                    ->values()
                    ->map(function ($member) {
                        return [
                            'id' => $member->id,
                            'userId' => $member->userId,
                            'user' => $member->user ? [
                                'id' => $member->user->id,
                                'name' => $member->user->name,
                            ] : null,
                        ];
                    });
            }),
            'managersCount' => $this->whenLoaded('members', function () {
                return $this->members->filter(fn ($member) => $member->user && $member->user->role?->name !== Constants::AGENT)->count();
            }),
            'agentCount' => $this->whenLoaded('members', function () {
                return $this->members->filter(fn ($member) => $member->user?->role?->name === Constants::AGENT)->count();
            }),
            'created_at' => convertToTimezone($this->created_at),
        ];
    }
}
