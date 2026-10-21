<?php

namespace App\Http\Resources\UserResource;

use App\Constants\Constants;
use Illuminate\Http\Resources\Json\JsonResource;

class GetUserResource extends JsonResource
{
    public function toArray($request)
    {
        $photoUrl = getImageUrl($this->profilePhoto);

        if (! empty($this->outlookConnection)) {
            $outlookConnection = true;
        } else {
            $outlookConnection = false;
        }

        $userFormattedMeta = [];

        foreach ($this->meta as $meta) {

            $metaValue = $meta->metaValue;

            $decoded = json_decode($metaValue, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $metaValue = $decoded;
            }

            if ($meta->metaKey === '_idCard' && is_array($metaValue)) {
                foreach ($metaValue as &$file) {
                    if (! empty($file['filePath'])) {
                        $file['filePath'] = idCardUrl($file['filePath']);
                    }
                }
                unset($file);
            }

            $userFormattedMeta[$meta->metaKey] = $metaValue;
        }

        return [
            'id' => $this->id,
            'roleId' => $this->roleId ?? null,
            'roleData' => $this->role ? [
                'id' => $this->role->id,
                'name' => $this->role->name,
            ] : null,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => normalizePhoneE164($this->phone),
            'assignedLeads' => $this->assignedLeads ?? null,
            'activeProperties' => $this->activePropertiesUrl ?? null,
            'lastLogin' => convertToTimezone($this->lastLogin) ?? null,
            'createdAt' => convertToTimezone($this->created_at),
            'createdBy' => $this->createdBy,
            'userName' => $this->userName,
            'agentType' => $this->agentType,
            'agentTypeLabel' => $this->agentType ? (Constants::AGENT_TYPE[$this->agentType] ?? $this->agentType) : null,
            'creatorName' => $this->creator ? $this->creator->name : null,
            'profilePhoto' => $photoUrl,
            'thumbnailPath' => getImageUrl($this->thumbnailPath),
            'status' => $this->status,
            'statusLabel' => $this->status ? (Constants::USER_STATUS[$this->status] ?? $this->status) : null,
            'agentCode' => $this->agentCode,
            'residencyStatus' => $this->residencyStatus,
            'residencyStatusLabel' => $this->residencyStatus ? (Constants::USER_RESIDENCY_STATUS[$this->residencyStatus] ?? $this->residencyStatus) : null,
            'website' => $this->website,
            'isAvailable' => (bool) $this->isAvailable,
            'brokerId' => $userFormattedMeta['_brokerId'] ?? null,
            'userMetaDetails' => $userFormattedMeta,
            // Intentionally empty: a user can own 20,000+ contacts, so loading the
            // full relation is costly. Kept as [] so the frontend shape holds.
            'contacts' => [],
            'teamId' => $this->teams ?? [],
            'teams' => $this->teams ?? [],
            'outlookConnection' => $outlookConnection,
            'propertiesListingCount' => $this->propertiesListingCount ?? 0,
            'enquiryCount' => $this->enquiryCount ?? 0,
        ];
    }
}
