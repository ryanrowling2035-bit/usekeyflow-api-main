<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Services\Salesforce\SalesforceCsvReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-order (and de-duplicate) imported listing-gallery images to match the Salesforce portal sort.
 *
 * The shape of pba__PropertyMedia__c that makes the naive "read one sort column" approach fail:
 *
 *  - Every photo is exported as TWO media records — a "twin pair" sharing the same Name and
 *    pba__Filename__c but with different Ids. The real gallery position lives on whichever twin
 *    is "active" for a given channel; the other twin carries 9999 ("unsorted / send to end").
 *    Across the three sort columns (Expose / PortalFeed / Website) the active twin alternates, so
 *    pba__SortOnPortalFeed__c is 9999 on one twin and the real 0-based position on the other.
 *  - salesforce:import-remote-images imports BOTH twins (it dedupes on media Id, not on the
 *    photo), so each photo lands in property_media_files TWICE under <mediaId>.<ext>. The
 *    duplicate twin row is removed separately (a SQL dedupe keyed on Name + property); this
 *    command only re-orders, so it picks the lowest-id row per photo as the one to position.
 *
 * So this groups records by Name (the stable per-photo key), and for each photo takes the
 * portal position = the minimum NON-9999 pba__SortOnPortalFeed__c across its twins (blank / all-
 * 9999 => unsorted, falls to the end). Photos are ranked [portal asc, CreatedDate asc, Name asc]
 * and property_media_files.ordering is rewritten 1..N so the CRM gallery (ORDER BY ordering)
 * mirrors the Salesforce portal feed.
 *
 * Off-plan & International listings are website-only (never on Bayut/PF), so they have no portal-feed
 * positions; for those the sort is read from pba__SortOnWebsite__c instead (same twin / 9999 rules).
 * That set is resolved from the sibling pba__Listing__c.csv + salesforce_id_map => properties.listingType;
 * with no listing CSV every property keeps the portal sort, i.e. the prior behaviour.
 *
 * Gallery rows are matched on fileName = <mediaId>.<ext> (the name the importer wrote) across all
 * of a photo's twin Ids; a photo with no imported gallery row is simply skipped. Idempotent —
 * re-running re-applies the same order. DB-only (no downloads); --dry-run reports without writing.
 */
class SalesforceReorderImages extends Command
{
    protected $signature = 'salesforce:reorder-images
        {path : Local export directory (or one containing _merged) with pba__PropertyMedia__c.csv}
        {--listings= : Restrict to properties referenced in this pba__Listing__c CSV (or dir containing one)}
        {--website-only : Only re-order Off-plan/International (website-sorted) properties}
        {--limit= : Max distinct properties to re-order (for testing)}
        {--sql= : Write the UPDATE statements to this file instead of executing them}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Re-order imported listing-gallery images (property_media_files.ordering) to match the Salesforce portal-feed sort.';

    /** The pba__PropertyMedia__c column holding the portal-feed (Bayut/PF) gallery positions. */
    private const SORT_COLUMN_PORTAL = 'pba__SortOnPortalFeed__c';

    /** The own-website gallery positions — used for Off-plan/International (website-only) listings. */
    private const SORT_COLUMN_WEBSITE = 'pba__SortOnWebsite__c';

    /** The 9999 sentinel Salesforce writes on the inactive twin ("unsorted / send to end"). */
    private const UNSORTED_SENTINEL = 9999.0;

    /** pba__Property__c => [ photoKey => ['sort'=>float, 'created'=>string, 'names'=>[<id>.<ext>...]] ]. */
    private array $byProperty = [];

    /** When set, only properties in this set are re-ordered (from --listings). */
    private ?array $propertyAllowList = null;

    /** pba__Property__c ids whose CRM listing is Off-plan/International — ordered by website sort, not portal. */
    private array $websiteSorted = [];

    /**
     * pba__Property__c => [CRM propertyId => true]. One SF Property can back SEVERAL CRM listings
     * (a re-listing under a new reference keeps the same pba__Property__c), each with its own
     * gallery, so the ordering rewrite must be scoped to one CRM property at a time.
     */
    private array $crmPropertyIds = [];

    /** When true, only Off-plan/International (website-sorted) properties are processed. */
    private bool $websiteOnly = false;

    /** Open file handle when --sql exports the UPDATE statements instead of executing them. */
    private $sqlHandle = null;

    private int $reordered = 0;

    private int $unchanged = 0;

    private int $missing = 0;

    public function handle(): int
    {
        // One-time backfill: hold every image's sort key in memory while grouping by property.
        @ini_set('memory_limit', '1024M');

        $base = rtrim((string) $this->argument('path'), '/');
        $path = $this->resolveMediaCsv($base);
        if ($path === null) {
            return self::FAILURE;
        }

        $maxProperties = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $dryRun = (bool) $this->option('dry-run');
        $this->websiteOnly = (bool) $this->option('website-only');

        if ($this->option('listings') !== null) {
            if (! $this->loadPropertyAllowList(rtrim((string) $this->option('listings'), '/'))) {
                return self::FAILURE;
            }
            $this->info(sprintf('Restricting to %d properties from --listings.', count($this->propertyAllowList)));
        }

        $this->buildCrmPropertyIds($this->option('listings') !== null ? rtrim((string) $this->option('listings'), '/') : $base);
        $this->buildWebsiteSortedSet();
        $this->info('Sort source: '.self::SORT_COLUMN_PORTAL.', '.self::SORT_COLUMN_WEBSITE
            .' for Off-plan/International'.($dryRun ? '  (dry run)' : ''));
        if ($this->websiteSorted !== []) {
            $this->info(sprintf('Off-plan/International (website sort): %d properties.', count($this->websiteSorted)));
        }
        if ($this->websiteOnly && $this->websiteSorted === []) {
            $this->warn('--website-only: no Off-plan/International properties resolved (provide pba__Listing__c.csv via the path dir or --listings); nothing to do.');

            return self::SUCCESS;
        }
        if (($sqlPath = $this->option('sql')) !== null) {
            $this->sqlHandle = fopen($sqlPath, 'w');
            if ($this->sqlHandle === false) {
                $this->sqlHandle = null;
                $this->error("Could not open --sql file for writing: {$sqlPath}");

                return self::FAILURE;
            }
            fwrite($this->sqlHandle, "-- salesforce:reorder-images — property_media_files.ordering rewrite\n-- Review before running against production.\n");
        }

        $this->collectSorts($path);
        $this->info(sprintf('Properties with images: %d', count($this->byProperty)));

        $processed = 0;
        foreach ($this->byProperty as $propRef => $photos) {
            if ($maxProperties !== null && $processed >= $maxProperties) {
                break;
            }
            $processed++;
            // Every CRM listing sharing this SF Property gets its own gallery ordered. With no
            // listing CSV the bridge is unavailable, so fall back to the unscoped match.
            $crmIds = array_keys($this->crmPropertyIds[$propRef] ?? []) ?: [null];
            foreach ($crmIds as $crmId) {
                $this->applyOrder($photos, $crmId, $dryRun);
            }
        }

        if ($this->sqlHandle !== null) {
            fclose($this->sqlHandle);
            $this->sqlHandle = null;
            $this->info('UPDATE statements written to '.$this->option('sql').' (not executed).');
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. %s %d photos, unchanged %d, no gallery row %d, across %d properties.',
            $this->option('sql') !== null ? 'Emitted' : 'Re-ordered',
            $this->reordered, $this->unchanged, $this->missing, $processed,
        ));

        return self::SUCCESS;
    }

    /**
     * Pass 1: collapse each property's media rows into one entry per photo (keyed by Name), keeping
     * the best sort position across its twins (portal feed, or website for Off-plan/International) and
     * the set of <mediaId>.<ext> gallery filenames.
     */
    private function collectSorts(string $path): void
    {
        foreach (SalesforceCsvReader::rows($path) as $row) {
            if (trim((string) ($row['pba__Category__c'] ?? '')) !== 'Images') {
                continue;
            }
            if ($this->isTrue($row['IsDeleted'] ?? null)) {
                continue;
            }
            $mediaId = trim((string) ($row['Id'] ?? ''));
            $propRef = trim((string) ($row['pba__Property__c'] ?? ''));
            if ($mediaId === '' || $propRef === '') {
                continue;
            }
            if ($this->propertyAllowList !== null && ! isset($this->propertyAllowList[$propRef])) {
                continue; // property not in the --listings status sheet
            }
            if ($this->websiteOnly && ! isset($this->websiteSorted[$propRef])) {
                continue; // --website-only: skip everything but Off-plan/International
            }

            // Stable per-photo key: Name is shared by both twins; fall back to the filename.
            $fileName = trim((string) ($row['pba__Filename__c'] ?? ''));
            $key = trim((string) ($row['Name'] ?? '')) ?: $fileName;
            if ($key === '') {
                continue;
            }

            // Off-plan/International are website-only, so their gallery position lives on the website
            // sort column; every other listing follows the Bayut/PF portal feed.
            $column = isset($this->websiteSorted[$propRef]) ? self::SORT_COLUMN_WEBSITE : self::SORT_COLUMN_PORTAL;
            $sort = $this->sortValue($row[$column] ?? null);
            $created = trim((string) ($row['CreatedDate'] ?? ''));
            $name = $mediaId.'.'.$this->extension($fileName);

            $photo = &$this->byProperty[$propRef][$key];
            // Best (lowest) NON-9999 position wins; the 9999 twin contributes nothing.
            $candidate = $sort === self::UNSORTED_SENTINEL ? PHP_FLOAT_MAX : $sort;
            $photo['sort'] = isset($photo['sort']) ? min($photo['sort'], $candidate) : $candidate;
            $photo['created'] = isset($photo['created']) && $photo['created'] !== ''
                ? min($photo['created'], $created) : $created;
            $photo['names'][$name] = true; // both twin Ids, deduped
            unset($photo);
        }
    }

    /**
     * Bridge pba__Property__c => CRM propertyId(s): pba__Listing__c.csv (Id => pba__Property__c) ->
     * salesforce_id_map (pba__Listing__c => CRM propertyId). Best-effort — with no listing CSV the
     * map stays empty and the ordering rewrite falls back to matching on fileName alone.
     */
    private function buildCrmPropertyIds(string $path): void
    {
        $listingCsv = $this->resolveListingCsv($path);
        if ($listingCsv === null) {
            return;
        }

        $listingMap = DB::table('salesforce_id_map')
            ->where('objectType', 'pba__Listing__c')
            ->pluck('moduleId', 'salesforceId')
            ->map(fn ($v) => (int) $v)
            ->all();

        foreach (SalesforceCsvReader::rows($listingCsv) as $row) {
            $propertyId = $listingMap[trim((string) ($row['Id'] ?? ''))] ?? null;
            $propRef = trim((string) ($row['pba__Property__c'] ?? ''));
            if ($propertyId === null || $propRef === '') {
                continue;
            }
            $this->crmPropertyIds[$propRef][$propertyId] = true;
        }
    }

    /**
     * Flag each SF property whose CRM listing is Off-plan/International so its gallery is ordered by
     * pba__SortOnWebsite__c instead of the portal feed (these are website-only listings with no portal
     * positions). Reads the bridge built by buildCrmPropertyIds() -> properties.listingType.
     * Best-effort: with no listing CSV (or no such listings) every property keeps the portal sort.
     */
    private function buildWebsiteSortedSet(): void
    {
        $intlOffplanIds = array_flip(array_map(
            'intval',
            DB::table('properties')
                ->whereIn('listingType', Constants::PROPERTY_INT_AND_OFF_LISTING_TYPES)
                ->pluck('id')
                ->all(),
        ));
        if ($intlOffplanIds === []) {
            return;
        }

        foreach ($this->crmPropertyIds as $propRef => $ids) {
            foreach (array_keys($ids) as $propertyId) {
                if (isset($intlOffplanIds[$propertyId])) {
                    $this->websiteSorted[$propRef] = true;
                    break;
                }
            }
        }
    }

    /** Rank one CRM listing's photos by its SF sort column and rewrite ordering 1..N. */
    private function applyOrder(array $photos, ?int $crmPropertyId, bool $dryRun): void
    {
        uasort($photos, function ($a, $b) {
            return [$a['sort'], $a['created']] <=> [$b['sort'], $b['created']];
        });

        // One read for the whole property: every candidate gallery row, keyed by fileName. Scoped to
        // the CRM listing — the same SF media id can exist under a sibling listing of the same SF
        // Property, and an unscoped match would rewrite the sibling's ordering instead of this one's.
        $allNames = [];
        foreach ($photos as $photo) {
            $allNames = array_merge($allNames, array_keys($photo['names']));
        }
        $rows = DB::table('property_media_files')
            ->when($crmPropertyId !== null, fn ($q) => $q->where('propertyId', $crmPropertyId))
            ->whereIn('fileName', $allNames)
            ->where('mediaType', 'uploadPhotos')
            ->get(['id', 'fileName', 'ordering'])
            ->keyBy('fileName');

        $updates = [];   // id => target ordering
        $ordering = 0;
        foreach ($photos as $photo) {
            // The gallery rows that exist for this photo's twin Ids; lowest id is the one we position
            // (the duplicate twin is removed by the separate Name+property SQL dedupe).
            $matched = [];
            foreach (array_keys($photo['names']) as $name) {
                if ($rows->has($name)) {
                    $matched[] = $rows->get($name);
                }
            }
            if ($matched === []) {
                $this->missing++; // photo never imported into the gallery

                continue;
            }
            usort($matched, fn ($a, $b) => $a->id <=> $b->id);

            $target = ++$ordering;
            $primary = $matched[0];
            if ((int) $primary->ordering === $target) {
                $this->unchanged++;
            } else {
                $updates[$primary->id] = $target;
                $this->reordered++;
            }
        }

        if ($updates === []) {
            return;
        }
        if ($this->sqlHandle !== null) {
            fwrite($this->sqlHandle, $this->renderUpdateSql($updates)); // --sql: export, don't touch the DB
        } elseif (! $dryRun) {
            $this->bulkUpdateOrdering($updates);
        }
    }

    /** Render the ordering rewrite as a standalone UPDATE ... CASE statement (int-only values, safe to inline). */
    private function renderUpdateSql(array $updates): string
    {
        $cases = '';
        foreach ($updates as $id => $order) {
            $cases .= ' WHEN '.(int) $id.' THEN '.(int) $order;
        }

        return 'UPDATE `property_media_files` SET `ordering` = CASE `id`'.$cases.' END'
            .' WHERE `id` IN ('.implode(',', array_map('intval', array_keys($updates))).");\n";
    }

    /** Rewrite ordering for many gallery rows in a single UPDATE ... CASE statement, keyed by id. */
    private function bulkUpdateOrdering(array $updates): void
    {
        $cases = '';
        $bindings = [];
        foreach ($updates as $id => $order) {
            $cases .= ' WHEN ? THEN ?';
            $bindings[] = $id;
            $bindings[] = $order;
        }

        $ids = array_keys($updates);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $bindings = array_merge($bindings, $ids);

        DB::update(
            "UPDATE `property_media_files` SET `ordering` = CASE `id`{$cases} END"
            ." WHERE `id` IN ({$placeholders})",
            $bindings,
        );
    }

    /** Parse a Salesforce sort cell to a sortable float; blank/non-numeric sorts last. */
    private function sortValue(?string $v): float
    {
        $v = trim((string) ($v ?? ''));

        return ($v !== '' && is_numeric($v)) ? (float) $v : PHP_FLOAT_MAX;
    }

    /** Same filename extension rule the importer used when it wrote the gallery row. */
    private function extension(string $fileName): string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return $ext !== '' ? $ext : 'jpg';
    }

    /** Resolve a pba__Listing__c.csv from a direct file path, a directory, or its _merged subdir. */
    private function resolveListingCsv(string $path): ?string
    {
        if (is_file($path)) {
            return $path;
        }
        foreach ([$path.'/pba__Listing__c.csv', $path.'/_merged/pba__Listing__c.csv'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Load the set of pba__Property__c Ids referenced by a filtered listing CSV. */
    private function loadPropertyAllowList(string $path): bool
    {
        $csv = $this->resolveListingCsv($path);
        if ($csv === null) {
            $this->error("Could not find a listing CSV at {$path} (or pba__Listing__c.csv under it).");

            return false;
        }

        $allow = [];
        foreach (SalesforceCsvReader::rows($csv) as $row) {
            $propRef = trim((string) ($row['pba__Property__c'] ?? ''));
            if ($propRef !== '') {
                $allow[$propRef] = true;
            }
        }
        $this->propertyAllowList = $allow;

        return true;
    }

    private function resolveMediaCsv(string $path): ?string
    {
        if (is_file($path.'/pba__PropertyMedia__c.csv')) {
            return $path.'/pba__PropertyMedia__c.csv';
        }
        if (is_file($path.'/_merged/pba__PropertyMedia__c.csv')) {
            return $path.'/_merged/pba__PropertyMedia__c.csv';
        }
        $this->error("Could not find pba__PropertyMedia__c.csv under {$path} (or {$path}/_merged).");

        return null;
    }

    private function isTrue(?string $v): bool
    {
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes'], true);
    }
}
