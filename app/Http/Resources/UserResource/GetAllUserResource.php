<?php

namespace App\Http\Resources\UserResource;

use App\Constants\Constants;
use Illuminate\Http\Resources\Json\JsonResource;

class GetAllUserResource extends JsonResource
{
    public function toArray($request)
    {
        $photoUrl = getImageUrl($this->profilePhoto);

        $userFormattedMeta = [];

        foreach ($this->meta as $meta) {

            $value = $meta->metaValue;
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {

                foreach ($decoded as &$file) {
                    if (is_array($file) && ! empty($file['filePath'])) {
                        $file['filePath'] = idCardUrl($file['filePath']);
                    }
                }
                unset($file);

                $userFormattedMeta[$meta->metaKey] = $decoded;
            } else {
                $userFormattedMeta[$meta->metaKey] = $value;
            }
        }

        return [
            'id' => $this->id,
            'roleId' => $this->roleId ?? null,
            'name' => $this->name,
            'email' => $this->email,
            'assignedLeads' => $this->assignedLeads ?? null,
            'activeProperties' => $this->activePropertiesUrl ?? null,
            'lastLogin' => convertToTimezone($this->lastLogin) ?? null,
            'phone' => normalizePhoneE164($this->phone),
            'roleData' => $this->role ? [
                'id' => $this->role->id,
                'name' => $this->role->name,
            ] : null,
            'userName' => $this->userName,
            'agentType' => $this->agentType ? (Constants::AGENT_TYPE[$this->agentType] ?? $this->agentType) : null,
            'createdAt' => convertToTimezone($this->created_at),
            'createdBy' => $this->createdBy,
            'creatorName' => $this->creator?->name,
            'profilePhoto' => $photoUrl,
            'thumbnailPath' => getImageUrl($this->thumbnailPath),
            'status' => $this->status ? (Constants::USER_STATUS[$this->status] ?? $this->status) : null,
            'agentCode' => $this->agentCode,
            'residencyStatus' => $this->residencyStatus,
            'residencyStatusLabel' => $this->residencyStatus ? (Constants::USER_RESIDENCY_STATUS[$this->residencyStatus] ?? $this->residencyStatus) : null,
            'website' => $this->website,
            'isAvailable' => (bool) $this->isAvailable,
            'brokerId' => $userFormattedMeta['_brokerId'] ?? null,
            'userMetaDetails' => $userFormattedMeta,
            // Intentionally empty: loading the full contacts relation (lakhs of rows
            // per admin/manager) exhausted memory. Kept as [] so the frontend shape holds.
            'contacts' => [],
            'teams' => $this->teams ?? [],
            'teamId' => $this->teams ?? [],
            'enquiryCount' => $this->whenNotNull($this->enquiry_count),
            'propertiesCount' => $this->whenNotNull($this->property_count),
        ];
    }
}
