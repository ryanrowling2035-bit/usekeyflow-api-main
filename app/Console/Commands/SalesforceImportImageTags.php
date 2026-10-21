<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Services\Salesforce\SalesforceCsvReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Backfill listing-image TAGS from pba__PropertyMedia__c.csv into property_media_files.tags.
 *
 * salesforce:import-remote-images copies the image bytes/URLs but drops pba__Tags__c, so every
 * imported photo lands untagged. This is the tag-only companion: it reads the same media CSV and
 * writes the IMAGE tags from pba__Tags__c onto the already-imported row, VERBATIM (the exact
 * Salesforce label). Only Constants::IMAGE_TAGS are stored — video tags (Main Video, 360tour, …),
 * documents and unknowns are dropped, since videos live in property_videos, not the gallery. So
 * "Floorplan" -> ["Floorplan"] and "Main Video;Brochure" -> ["Brochure"]. It downloads nothing and
 * touches only the `tags` column — run it any time after the images are in.
 *
 * The match key is fileName = <mediaId>.<ext>, the same globally-unique id the image import writes,
 * so no listing bridge / property map is needed — a row is updated purely by its media id. Rows
 * whose image was never imported are simply not found (counted as skipped).
 *
 * Idempotent and non-destructive by default: only rows with no tags yet are filled, so a user's
 * hand-edited tags are preserved. Pass --override to overwrite existing tags with the source set.
 */
class SalesforceImportImageTags extends Command
{
    protected $signature = 'salesforce:import-image-tags
        {path : Export directory (or one containing _merged), the pba__PropertyMedia__c.csv file itself, OR an https URL to the CSV}
        {--override : Overwrite tags on rows that already have some (default: only fill empty ones)}
        {--dry-run : Report what would change without writing}
        {--limit= : Stop after updating this many rows (for testing)}
        {--refresh : Re-download the remote CSV even if a cached copy exists}';

    protected $description = 'Backfill property_media_files.tags from pba__PropertyMedia__c.csv (pba__Tags__c). Tags only — downloads nothing, matches on fileName.';

    /** Lower-cased Salesforce label => canonical IMAGE_TAGS label; only these tags are stored. */
    private array $imageTags = [];

    private bool $override = false;

    private bool $dryRun = false;

    private int $updated = 0;

    private int $skippedNoTags = 0;

    private int $skippedNotFound = 0;

    private int $skippedHasTags = 0;

    /** Rows tagged this run: list<array{id:int, mediaId:string, propertyId:int, tags:list<string>}>. */
    private array $tagged = [];

    public function handle(): int
    {
        $csv = $this->resolveCsv(rtrim((string) $this->argument('path'), '/'));
        if ($csv === null) {
            return self::FAILURE;
        }

        $this->override = (bool) $this->option('override');
        $this->dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        // Only IMAGE_TAGS are stored (verbatim). Index them lower-cased for case-insensitive
        // matching; everything else in pba__Tags__c (video tags, documents, unknowns) is dropped.
        foreach (Constants::IMAGE_TAGS as $label) {
            $this->imageTags[strtolower($label)] = $label;
        }

        if ($this->dryRun) {
            $this->info('Dry run: no rows will be written.');
        }
        $this->info('Source: '.$csv);

        foreach (SalesforceCsvReader::rows($csv) as $row) {
            if ($limit !== null && $this->updated >= $limit) {
                break;
            }
            try {
                $this->applyTags($row);
            } catch (Throwable) {
                $this->skippedNotFound++;
            }
        }

        $this->newLine();
        $this->reportTagged();
        $this->info(sprintf(
            'Done. Tagged %d row(s)%s. Skipped: %d no image tags, %d already tagged, %d not found.',
            $this->updated,
            $this->dryRun ? ' (dry run)' : '',
            $this->skippedNoTags,
            $this->skippedHasTags,
            $this->skippedNotFound,
        ));

        return self::SUCCESS;
    }

    /** Print the tagged rows as a table (capped so a full backfill doesn't flood the console). */
    private function reportTagged(): void
    {
        if ($this->tagged === []) {
            return;
        }

        $cap = 50;
        $rows = array_map(fn ($t) => [
            $t['id'],
            $t['mediaId'],
            $t['propertyId'],
            implode(', ', $t['tags']),
        ], array_slice($this->tagged, 0, $cap));

        $this->table(['media_file_id', 'sf_media_id', 'propertyId', 'tags'], $rows);
        if (count($this->tagged) > $cap) {
            $this->line(sprintf('  … and %d more.', count($this->tagged) - $cap));
        }
    }

    private function applyTags(array $row): void
    {
        if (trim((string) ($row['pba__Category__c'] ?? '')) !== 'Images') {
            return; // images only — matches what salesforce:import-remote-images brought across
        }
        if ($this->isTrue($row['IsDeleted'] ?? null)) {
            return;
        }

        $mediaId = trim((string) ($row['Id'] ?? ''));
        if ($mediaId === '') {
            return;
        }

        $tags = $this->imageTagsFor($row['pba__Tags__c'] ?? null);
        if ($tags === []) {
            $this->skippedNoTags++; // no image tags (e.g. only video/document tags, or none at all)

            return;
        }

        $name = $mediaId.'.'.$this->extension($row);
        $existing = DB::table('property_media_files')
            ->where('fileName', $name)
            ->first(['id', 'propertyId', 'tags']);

        if ($existing === null) {
            $this->skippedNotFound++;

            return;
        }

        // Non-destructive by default: don't clobber tags a user may have edited.
        $current = trim((string) ($existing->tags ?? ''));
        if (! $this->override && ! in_array($current, ['', '[]', 'null'], true)) {
            $this->skippedHasTags++;

            return;
        }

        if (! $this->dryRun) {
            DB::table('property_media_files')
                ->where('id', $existing->id)
                ->update(['tags' => json_encode($tags)]);
        }

        $this->updated++;
        $this->tagged[] = [
            'id' => (int) $existing->id,
            'mediaId' => $mediaId,
            'propertyId' => (int) $existing->propertyId,
            'tags' => $tags,
        ];
    }

    /**
     * Extract the IMAGE tags from pba__Tags__c: split on ; (multi-tag), match each against
     * IMAGE_TAGS case-insensitively, and keep the canonical label VERBATIM. Anything that isn't an
     * image tag — video tags, documents, unknowns — is dropped. Returns a de-duplicated list.
     *
     * @return list<string>
     */
    private function imageTagsFor(?string $raw): array
    {
        $out = [];
        foreach (preg_split('/[;,]/', (string) $raw) as $part) {
            $canonical = $this->imageTags[strtolower(trim((string) $part))] ?? null;
            if ($canonical !== null) {
                $out[] = $canonical;
            }
        }

        return array_values(array_unique($out));
    }

    private function extension(array $row): string
    {
        $ext = strtolower(pathinfo(trim((string) ($row['pba__Filename__c'] ?? '')), PATHINFO_EXTENSION));

        return $ext !== '' ? $ext : 'jpg';
    }

    /** Resolve the argument to the pba__PropertyMedia__c.csv path (local dir, file, or https). */
    private function resolveCsv(string $path): ?string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $this->downloadRemoteCsv($path);
        }
        if (is_file($path) && preg_match('/pba__PropertyMedia__c\.csv$/i', $path)) {
            return $path;
        }
        if (is_file($path.'/pba__PropertyMedia__c.csv')) {
            return $path.'/pba__PropertyMedia__c.csv';
        }
        if (is_file($path.'/_merged/pba__PropertyMedia__c.csv')) {
            return $path.'/_merged/pba__PropertyMedia__c.csv';
        }
        $this->error("Could not find pba__PropertyMedia__c.csv at/under {$path} (or {$path}/_merged).");

        return null;
    }

    /**
     * Download only pba__PropertyMedia__c.csv from a base URL into the shared sf-remote-source
     * cache (reused across salesforce commands). Cached between runs unless --refresh.
     */
    private function downloadRemoteCsv(string $url): ?string
    {
        $base = preg_match('/\.csv$/i', $url)
            ? substr($url, 0, (int) strrpos($url, '/'))
            : rtrim($url, '/');

        $dir = storage_path('app/sf-remote-source');
        @mkdir($dir, 0775, true);
        $target = $dir.'/pba__PropertyMedia__c.csv';

        if (! $this->option('refresh') && is_file($target) && filesize($target) > 0) {
            $this->info('Using cached pba__PropertyMedia__c.csv');

            return $target;
        }

        $src = $base.'/pba__PropertyMedia__c.csv';
        $this->info("Downloading {$src} …");
        try {
            $ok = Http::timeout(900)->sink($target)->get($src)->successful();
        } catch (Throwable) {
            $ok = false;
        }
        if (! $ok) {
            @unlink($target);
            $this->error("Failed to download pba__PropertyMedia__c.csv from {$base}/.");

            return null;
        }

        return $target;
    }

    private function isTrue(?string $v): bool
    {
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes'], true);
    }
}
