<?php

namespace App\Services\Concerns;

trait FiltersPortalPayload
{
    /**
     * Reduce a full portal payload down to only the top-level keys affected by
     * the given changed CRM fields, so an update PATCHes just what changed
     * instead of re-pushing the entire listing.
     *
     * Each consuming service exposes an UPDATE_FIELD_MAP constant that maps a
     * changed CRM field/meta key (or the 'media' sentinel) to the portal payload
     * top-level keys it feeds.
     *
     * Returns the payload untouched when no changed fields are supplied (the
     * full-sync / manual publish path), and an empty array when the change set
     * touches nothing portal-relevant (caller should then skip the API call).
     */
    protected function partialPayload(array $payload, array $changedFields): array
    {
        if (empty($changedFields)) {
            return $payload;
        }

        $allowed = [];
        foreach ($changedFields as $field) {
            foreach (static::UPDATE_FIELD_MAP[$field] ?? [] as $payloadKey) {
                $allowed[$payloadKey] = true;
            }
        }

        if (empty($allowed)) {
            return [];
        }

        return array_intersect_key($payload, $allowed);
    }
}
