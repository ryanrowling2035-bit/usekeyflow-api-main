<?php

namespace App\Services\Salesforce\Importers;

/**
 * Update-only listing importer: re-syncs already-imported listings from a Salesforce export,
 * writing only the fields that actually changed. Unlike {@see ListingImporter} it never creates
 * new properties — a listing with no existing CRM mapping is skipped — and it leaves unchanged
 * columns untouched, so a row whose fields all still match is not written at all.
 *
 * It shares all of ListingImporter's field mapping, status/type/amenity resolution and meta
 * writes (and the same pba__Listing__c id map, so existing listings are matched); only the
 * create-new and changed-only update behaviour differs.
 */
class ListingUpdateImporter extends ListingImporter
{
    /** Only re-sync listings created or modified within this many months; older ones are skipped. */
    private const MAX_AGE_MONTHS = 12;

    /**
     * Skip listings older than {@see self::MAX_AGE_MONTHS}: a row is re-synced only when its
     * CreatedDate OR LastModifiedDate falls within the window (rows missing both timestamps are
     * treated as out of window). Recent rows fall through to the normal listing mapping.
     */
    protected function transform(array $row): ?array
    {
        $cutoff = now()->subMonths(self::MAX_AGE_MONTHS);
        $recent = false;
        foreach (['CreatedDate', 'LastModifiedDate'] as $field) {
            $dt = $this->parseDateTime($row[$field] ?? null);
            if ($dt !== null && $dt->greaterThanOrEqualTo($cutoff)) {
                $recent = true;
                break;
            }
        }
        if (! $recent) {
            return null;
        }

        return parent::transform($row);
    }

    /**
     * The only properties-table columns this importer updates. Everything else on the row is
     * left as-is. (The other configured fields are property_meta keys — see metaKeysAllowed().)
     *
     * @return array<int, string>
     */
    protected function updateOnlyColumns(): array
    {
        return ['pfPriceFrequency'];
    }

    /**
     * The only property_meta keys this importer writes; all other meta keys and internal-note
     * types are left untouched on a re-sync.
     *
     * @return array<int, string>
     */
    protected function metaKeysAllowed(): array
    {
        return [
            'flyerData',
            'annualRentalPrice',
            'pfFurnishingType',
            'bayutFurnishingStatus',
            'pfRegion',
            'propertyAddress',
            'geocodeAccuracy',
            'recordTypeId',
        ];
    }

    protected function insertNewRecords(): bool
    {
        return false;
    }

    protected function updateChangedColumnsOnly(): bool
    {
        return true;
    }
}
