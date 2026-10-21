<?php

namespace App\Services\Salesforce\Importers;

use App\Constants\Constants;

/**
 * Re-syncs only the four listing fields that came through null/mismatched in the new (Keyflow)
 * feed, for listings already imported into the CRM. It reuses {@see ListingImporter}'s full
 * mapping (developer-name resolution, amenity/date parsing) but narrows the write to the fields
 * below; every other column, meta key and note is left untouched.
 *
 * Fields synced:
 *  1. Developer        -> pfDeveloper meta            (from the project's Account-lookup developer)
 *  2. Completion date  -> completionDate meta         (pba__CompletionDate_pb__c)
 *  3. Key features     -> keyFeaturesForWebsite meta  + pfAmenities / bayutAmenities columns
 *  4. Property address  -> propertyAddress meta        (pba__Address_pb__c)
 *  5. Bedrooms         -> bedrooms column             (incl. Studio -> 0, via resolveBedrooms())
 *
 * It never creates new properties — a row with no existing CRM mapping is skipped — and, in
 * changed-only mode, leaves a listing untouched when the stored values already match. Scope: it
 * always re-syncs listings whose status is Published, Pocket Listing or Live on Website (no age
 * limit), plus any other listing created within {@see self::MAX_AGE_MONTHS} months; everything else
 * is skipped. Point it at an export whose CSVs have been repaired upstream (the missing values must
 * actually be present in the feed for the re-sync to populate them).
 */
class ListingFieldSyncImporter extends ListingImporter
{
    /** Non-evergreen listings are only re-synced when created within this many months. */
    private const MAX_AGE_MONTHS = 36;

    /**
     * Statuses re-synced regardless of age — the live-facing inventory. Any listing in one of
     * these is always processed; every other status is subject to the {@see self::MAX_AGE_MONTHS}
     * created-date window.
     */
    private const EVERGREEN_STATUSES = [
        Constants::PROPERTY_STATUS_PUBLISHED,
        Constants::PROPERTY_STATUS_POCKET_LISTING,
        Constants::PROPERTY_STATUS_LIVE_ON_WEBSITE,
    ];

    /**
     * Restrict the re-sync: keep listings whose status is Published / Pocket Listing / Live on
     * Website (no age limit), plus any other listing created within {@see self::MAX_AGE_MONTHS}
     * months. Everything else is skipped before any mapping/DB work.
     */
    protected function transform(array $row): ?array
    {
        $status = $this->lookup($row['pba__Status__c'] ?? null, self::STATUS_MAP, Constants::PROPERTY_STATUS_DRAFT);
        if (! in_array($status, self::EVERGREEN_STATUSES, true)) {
            $created = $this->parseDateTime($row['CreatedDate'] ?? null);
            if ($created === null || $created->lessThan(now()->subMonths(self::MAX_AGE_MONTHS))) {
                return null;
            }
        }

        return parent::transform($row);
    }

    /** The only properties-table columns this importer overwrites — bedrooms + "Key features" amenities. */
    protected function updateOnlyColumns(): array
    {
        return ['bedrooms', 'pfAmenities', 'bayutAmenities'];
    }

    /** The only property_meta keys this importer writes; all other meta and notes are untouched. */
    protected function metaKeysAllowed(): array
    {
        return [
            'pfDeveloper',
            'completionDate',
            'keyFeaturesForWebsite',
            'propertyAddress',
        ];
    }

    /** Update-only: never create new properties; a row with no existing mapping is skipped. */
    protected function insertNewRecords(): bool
    {
        return false;
    }

    /** Skip the write when the stored value already matches. */
    protected function updateChangedColumnsOnly(): bool
    {
        return true;
    }
}
