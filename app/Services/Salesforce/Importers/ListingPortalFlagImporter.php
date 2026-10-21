<?php

namespace App\Services\Salesforce\Importers;

/**
 * Re-syncs only the "allowed for portals" flag of already-imported listings from a Salesforce
 * export. It writes nothing on the properties table and no meta key other than
 * systemAllowedForPortals — every other field keeps its current CRM value — and it never creates
 * new properties (a row with no existing CRM mapping is skipped).
 *
 * It reuses {@see ListingUpdateImporter}'s update-only / changed-only behaviour and 12-month age
 * window; transform() discards the full column set so only afterSave()'s narrowed meta write runs.
 */
class ListingPortalFlagImporter extends ListingUpdateImporter
{
    /**
     * Re-sync the portal flag and bathroom count on every matched listing regardless of age — the
     * parent's 12-month window is intentionally bypassed so no record is skipped. Only the
     * bathrooms properties-table column is mapped here (narrowed by updateOnlyColumns()); the
     * portal flag lives in the systemAllowedForPortals meta key, which afterSave() writes from the
     * raw row. Rows with no existing CRM mapping are still skipped, as this importer never creates
     * new properties.
     */
    protected function transform(array $row): ?array
    {
        return [
            'bathrooms' => $this->intval($this->first($row, ['Num_Baths__c', 'pba__FullBathrooms_pb__c'])),
        ];
    }

    /** The only properties-table column this importer overwrites on a re-sync. */
    protected function updateOnlyColumns(): array
    {
        return ['bathrooms'];
    }

    /** The only meta key this importer writes; all other meta and notes are left untouched. */
    protected function metaKeysAllowed(): array
    {
        return ['systemAllowedForPortals'];
    }
}
