<?php

namespace App\Services\Salesforce\Importers;

/**
 * Re-syncs only the bedrooms and bathrooms columns on listings already imported into the CRM.
 * It reuses {@see ListingImporter}'s full mapping (bedrooms incl. Studio -> 0 via resolveBedrooms(),
 * bathrooms via Num_Baths__c / pba__FullBathrooms_pb__c) but narrows the write to those two
 * columns; every other column, meta key and note is left untouched.
 *
 * It never creates new properties — a row with no existing CRM mapping is skipped — and, in
 * changed-only mode, leaves a listing untouched when the stored bed/bath already match. Point it
 * at an export whose CSVs carry the correct counts; a source that still says 0 baths re-imports 0.
 */
class ListingBathBedSyncImporter extends ListingImporter
{
    /** The only properties-table columns this importer overwrites. */
    protected function updateOnlyColumns(): array
    {
        return ['bedrooms', 'bathrooms'];
    }

    /** Write no meta keys or internal notes — an empty allow-list makes afterSave a no-op. */
    protected function metaKeysAllowed(): ?array
    {
        return [];
    }

    /** Update-only: never create new properties; a row with no existing mapping is skipped. */
    protected function insertNewRecords(): bool
    {
        return false;
    }

    /** Skip the write when the stored bed/bath already match. */
    protected function updateChangedColumnsOnly(): bool
    {
        return true;
    }
}
