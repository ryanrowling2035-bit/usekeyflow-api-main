<?php

namespace App\Services\Salesforce\Importers;

/**
 * Re-syncs only the `description` column of already-imported listings from a Salesforce export.
 *
 * It reuses {@see ListingImporter}'s full field mapping, so the description — and its
 * project-description fallback — resolve exactly as on a normal import, but narrows the write to
 * the description column via updateOnlyColumns(); no other listing field, meta key or note is
 * touched (metaKeysAllowed() = [] makes afterSave a no-op). It never creates new properties — a
 * row with no existing CRM mapping is skipped — and, in changed-only mode, leaves a listing
 * untouched when its stored description already matches.
 *
 * Unlike {@see ListingUpdateImporter} it intentionally does NOT apply the 12-month age window, so
 * the backfill reaches the full imported inventory. Built to land the em-dash corruption fix
 * (Salesforce exports mangle "—" into a literal "?") after the source CSV has been repaired.
 */
class ListingDescriptionImporter extends ListingImporter
{
    /** The only properties-table column this importer overwrites on a re-sync. */
    protected function updateOnlyColumns(): array
    {
        return ['description'];
    }

    /** Write no property_meta keys or internal notes — afterSave becomes a no-op. */
    protected function metaKeysAllowed(): ?array
    {
        return [];
    }

    /** Update-only: never create new properties; a row with no existing mapping is skipped. */
    protected function insertNewRecords(): bool
    {
        return false;
    }

    /** Skip the write when the stored description already matches. */
    protected function updateChangedColumnsOnly(): bool
    {
        return true;
    }
}
