<?php

namespace App\Http\Resources\PortalSettingResource;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortalSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'portal' => $this->portal,
            'branchName' => $this->branchName,
            'slug' => $this->slug,
            'status' => $this->status,
            'bayutSecretTokenPublic' => $this->mask($this->bayutSecretTokenPublic),
            'pfApiKeyPublic' => $this->mask($this->pfApiKeyPublic),
            'pfApiSecretPublic' => $this->mask($this->pfApiSecretPublic),
            'createdAt' => convertToTimezone($this->createdAt),
            'updatedAt' => convertToTimezone($this->updatedAt),
            'webhookSubscriptions' => $this->whenLoaded('webhookSubscriptions', fn () => $this->webhookSubscriptions->map(fn ($s) => [
                'portal' => $s->portal,
                'eventId' => $s->eventId,
                'webhookId' => $s->webhookId,
                'callbackUrl' => $s->callbackUrl,
                'status' => $s->status,
                'statusCode' => $s->statusCode,
                'error' => $s->error,
                'updatedAt' => convertToTimezone($s->updated_at),
            ])),
        ];
    }

    protected function mask(?string $hint): ?string
    {
        return $hint ? '********'.$hint : null;
    }
}
