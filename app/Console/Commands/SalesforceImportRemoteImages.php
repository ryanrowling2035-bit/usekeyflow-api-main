<?php

namespace App\Console\Commands;

use App\Services\FileStorageService;
use App\Services\ImageCompressionService;
use App\Services\Salesforce\SalesforceCsvReader;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Import PropertyBase listing IMAGES (the remote galleries) — images only, no documents.
 *
 * Listing photos are not in the Salesforce export blobs; they live on PropertyBase's public S3
 * and are referenced by URL in pba__PropertyMedia__c (Category = Images). For every image whose
 * property resolves to a CRM listing, this does everything in one pass — no manual `aws s3 sync`:
 *   1. downloads the original from its remote URL,
 *   2. uploads original + watermarked compressed + 150x150 thumbnail to the public S3 bucket
 *      (the same pipeline live uploads use),
 *   3. upserts the property_media_files row with the resulting S3 URLs.
 *
 * There is no listing-recency/status gate: every media row is imported as long as its property
 * exists in the CRM and the media itself hasn't been imported yet. The pba__Listing__c.csv is
 * still read, but only as the SF-property => CRM-property id bridge (salesforce_id_map has no
 * pba__Property__c entry), not as a filter. Narrow the input by pre-filtering the media CSV.
 *
 * Resumable and idempotent: an image is skipped when a property_media_files row already exists
 * for its media id (fileName = <mediaId>.<ext>). Run salesforce:import (data) first so the
 * listings exist.
 *
 * Scaling a large backfill (600k+ images is download- and CPU-bound):
 *  - --shard=i/N processes only properties where id % N == (i-1), so N instances can run in
 *    parallel over disjoint property sets (all of a property's photos stay in one shard).
 *  - --originals-only skips watermarked/compressed + thumbnail generation (the heavy per-image
 *    work), uploading just the original; regenerate the variants later or on demand.
 */
class SalesforceImportRemoteImages extends Command
{
    protected $signature = 'salesforce:import-remote-images
        {path : Local export directory (or one containing _merged), OR an https URL to the CSVs (e.g. an S3 bucket) — both pba__PropertyMedia__c.csv and pba__Listing__c.csv must be there}
        {--since= : Only import media (PropertyMedia) whose own CreatedDate is on/after this date YYYY-MM-DD}
        {--recent-months= : Only import media whose own CreatedDate falls within the last N months (relative window, e.g. --recent-months=12). Ignored when --since is given}
        {--older-than-months= : Only import images of LISTINGS whose CreatedDate is OLDER than N months (listing created before today - N months) - the backlog complement of the last-N-months run}
        {--limit= : Max distinct properties to process (for testing)}
        {--shard= : Process only one shard of properties, i/N (e.g. --shard=1/8) — run N instances in parallel over disjoint properties}
        {--originals-only : Upload originals only, skipping watermarked/compressed + thumbnail generation (faster bulk backfill)}
        {--refresh : Re-download the remote CSVs even if a cached copy exists}';

    protected $description = 'Import PropertyBase listing images (remote URLs) only — download, upload original/compressed/thumbnail to S3, and sync the property_media_files rows in one pass.';

    private FileStorageService $files;

    private ImageCompressionService $imageCompression;

    /** pba__Property__c => CRM propertyId (all listings; no recency filter). */
    private array $propertyMap = [];

    /** propertyId => referenceNumber (for the storage key). */
    private array $refMap = [];

    /** propertyId => current max ordering. */
    private array $ordering = [];

    private array $selectedProperties = [];

    /** Shard filter: keep only properties where id % $shardTotal === $shardIndex (0-based). */
    private ?int $shardIndex = null;

    private ?int $shardTotal = null;

    /** Skip watermarked/compressed + thumbnail generation; store originals only. */
    private bool $originalsOnly = false;

    /** Only import media whose own CreatedDate is on/after this moment (null = no window). */
    private ?\Carbon\Carbon $sinceCutoff = null;

    /** Only import images of listings whose CreatedDate is strictly before this moment (null = no upper bound). */
    private ?\Carbon\Carbon $untilCutoff = null;

    private ?int $fallbackUserId = null;

    private int $imported = 0;

    private int $skipped = 0;

    private int $downloadFailures = 0;

    public function handle(): int
    {
        // Hold the listing/property maps in memory for the whole run. Suppressed (@) because
        // capped hosts (e.g. CloudLinux) clamp this and would otherwise emit a warning.
        @ini_set('memory_limit', '1024M');

        $source = $this->resolveSource(rtrim((string) $this->argument('path'), '/'));
        if ($source === null) {
            return self::FAILURE;
        }

        $this->files = app(FileStorageService::class);
        $this->imageCompression = app(ImageCompressionService::class);
        $this->fallbackUserId = (int) (DB::table('users')->min('id') ?? 0) ?: null;

        $maxProperties = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $this->originalsOnly = (bool) $this->option('originals-only');

        $shard = trim((string) $this->option('shard'));
        if ($shard !== '') {
            if (! preg_match('/^(\d+)\s*\/\s*(\d+)$/', $shard, $m)
                || (int) $m[2] < 1 || (int) $m[1] < 1 || (int) $m[1] > (int) $m[2]) {
                $this->error("Invalid --shard: {$shard}. Use i/N with 1 ≤ i ≤ N, e.g. --shard=1/8.");

                return self::FAILURE;
            }
            $this->shardTotal = (int) $m[2];
            $this->shardIndex = (int) $m[1] - 1;
            $this->info("Shard {$m[1]}/{$m[2]}: properties where id % {$this->shardTotal} == {$this->shardIndex}");
        }
        if ($this->originalsOnly) {
            $this->info('Originals only: skipping compressed + thumbnail generation.');
        }

        $since = trim((string) $this->option('since'));
        if ($since !== '') {
            try {
                $this->sinceCutoff = \Carbon\Carbon::parse($since)->startOfDay();
            } catch (Throwable) {
                $this->error("Invalid --since date: {$since} (use YYYY-MM-DD).");

                return self::FAILURE;
            }
            $this->info("Media CreatedDate filter: on/after {$this->sinceCutoff->toDateString()}");
        }

        // Relative-window convenience: --recent-months=12 sets the same CreatedDate cutoff to N
        // months before today. An explicit --since always wins, so the two are never combined.
        $recentMonths = trim((string) $this->option('recent-months'));
        if ($recentMonths !== '' && $this->sinceCutoff === null) {
            if (! ctype_digit($recentMonths) || (int) $recentMonths < 1) {
                $this->error("Invalid --recent-months: {$recentMonths}. Use a positive integer, e.g. --recent-months=12.");

                return self::FAILURE;
            }
            $this->sinceCutoff = now()->subMonths((int) $recentMonths)->startOfDay();
            $this->info("Media CreatedDate filter: last {$recentMonths} months (on/after {$this->sinceCutoff->toDateString()}).");
        }

        // Backlog window: --older-than-months=12 imports only images of LISTINGS created more than N
        // months ago (listing CreatedDate strictly before today - N months) - the complement of a
        // last-N-months run, applied at the listing level in buildPropertyMap().
        $olderThanMonths = trim((string) $this->option('older-than-months'));
        if ($olderThanMonths !== '') {
            if (! ctype_digit($olderThanMonths) || (int) $olderThanMonths < 1) {
                $this->error("Invalid --older-than-months: {$olderThanMonths}. Use a positive integer, e.g. --older-than-months=12.");

                return self::FAILURE;
            }
            $this->untilCutoff = now()->subMonths((int) $olderThanMonths)->startOfDay();
            $this->info("Listing CreatedDate filter: older than {$olderThanMonths} months (listing created before {$this->untilCutoff->toDateString()}).");
        }

        $this->buildPropertyMap($source);
        if ($this->propertyMap === []) {
            $this->warn('No listings resolved — check pba__Listing__c.csv and that salesforce:import has run.');
        }

        $path = $source.'/pba__PropertyMedia__c.csv';
        if (! is_file($path)) {
            $this->error("pba__PropertyMedia__c.csv not found in {$source}");

            return self::FAILURE;
        }

        $this->info(sprintf('Source: %s  (%d properties mapped)', $source, count($this->propertyMap)));

        foreach (SalesforceCsvReader::rows($path) as $row) {
            try {
                $this->importImage($row, $maxProperties);
            } catch (Throwable $e) {
                $this->skipped++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. Images imported: %d across %d properties (skipped %d, download failures %d).',
            $this->imported, count($this->selectedProperties), $this->skipped, $this->downloadFailures,
        ));

        return self::SUCCESS;
    }

    private function importImage(array $row, ?int $maxProperties): void
    {
        if (trim((string) ($row['pba__Category__c'] ?? '')) !== 'Images') {
            return; // images only
        }
        if ($this->isTrue($row['IsDeleted'] ?? null)) {
            return;
        }

        $mediaId = trim((string) ($row['Id'] ?? ''));
        $propertyId = $this->propertyMap[trim((string) ($row['pba__Property__c'] ?? ''))] ?? null;
        if ($mediaId === '' || $propertyId === null) {
            return; // no media id, or property not in the CRM
        }

        // Window on the media's OWN CreatedDate (not the listing's) when --since is given.
        $created = $this->parseCarbon($row['CreatedDate'] ?? null);
        if ($this->sinceCutoff !== null && ($created === null || $created->lessThan($this->sinceCutoff))) {
            return;
        }

        $name = $mediaId.'.'.$this->extension($row);

        // Resumable: skip if this media was already imported FOR THIS PROPERTY. The check is scoped
        // to propertyId because one SF Property can bridge to several CRM listings (a re-listing
        // under a new reference keeps the same pba__Property__c), and each of them needs its own
        // gallery row — the S3 key is per-reference, so there is no collision.
        if (DB::table('property_media_files')
            ->where('propertyId', $propertyId)
            ->where('fileName', $name)
            ->exists()) {
            return;
        }

        if (! isset($this->selectedProperties[$propertyId])) {
            if ($maxProperties !== null && count($this->selectedProperties) >= $maxProperties) {
                return;
            }
            $this->selectedProperties[$propertyId] = true;
        }

        $ref = $this->refMap[$propertyId] ?? '';
        $base = $this->files->recordPrefix('properties', $ref !== '' ? $ref : null, $propertyId);

        $bytes = $this->download($row);
        if ($bytes === null) {
            $this->downloadFailures++;
            $this->skipped++;

            return;
        }

        // Upload original + compressed + thumbnail to S3 via the same pipeline as live uploads.
        $paths = $this->uploadVariants($bytes, $name, $base);

        $createdAt = $created?->toDateTimeString();
        $modified = $this->parseDate($row['LastModifiedDate'] ?? null) ?? $createdAt;

        DB::table('property_media_files')->insert([
            'propertyId' => $propertyId,
            'fileName' => $name,
            'filePath' => $paths['originalPath'],
            'originalPath' => $paths['originalPath'],
            'compressedPath' => $paths['compressedPath'],
            'thumbnailPath' => $paths['thumbnailPath'],
            'mediaType' => 'uploadPhotos',
            'ordering' => $this->nextOrdering($propertyId),
            'isWebsite' => $this->isTrue($row['pba__IsOnWebsite__c'] ?? null),
            'isPortals' => $this->isTrue($row['pba__IsOnPortalFeed__c'] ?? null),
            'createdBy' => $this->fallbackUserId,
            'created_at' => $createdAt,
            'updated_at' => $modified,
        ]);
        $this->imported++;
    }

    /**
     * Wrap the downloaded bytes in an UploadedFile and run ImageCompressionService, which uploads
     * the original + compressed + thumbnail to the public bucket and returns their URLs. Falls
     * back to storing just the original if the bytes can't be processed as an image.
     *
     * @return array{originalPath: string, compressedPath: string, thumbnailPath: string|null}
     */
    private function uploadVariants(string $bytes, string $name, string $base): array
    {
        // Originals-only: skip the (CPU-heavy) compressed + thumbnail pipeline entirely.
        if ($this->originalsOnly) {
            $url = $this->files->putPublic($base.'/originals/'.$name, $bytes);

            return ['originalPath' => $url, 'compressedPath' => $url, 'thumbnailPath' => null];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ri_');
        $tmpFile = $tmp.'.'.pathinfo($name, PATHINFO_EXTENSION);
        rename($tmp, $tmpFile);
        file_put_contents($tmpFile, $bytes);

        try {
            $upload = new UploadedFile($tmpFile, $name, null, null, true);

            return $this->imageCompression->compressAndStore($upload, $name, $base);
        } catch (Throwable) {
            $url = $this->files->putPublic($base.'/originals/'.$name, $bytes);

            return ['originalPath' => $url, 'compressedPath' => $url, 'thumbnailPath' => null];
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Build pba__Property__c => CRM propertyId for ALL listings, plus refMap (propertyId =>
     * referenceNumber for the storage key). No recency/status gate — the listing CSV is read
     * purely as the SF-property => CRM-property bridge, since salesforce_id_map only maps
     * pba__Listing__c (each CRM property is one SF listing) and has no pba__Property__c entry.
     */
    private function buildPropertyMap(string $source): void
    {
        $listingMap = DB::table('salesforce_id_map')
            ->where('objectType', 'pba__Listing__c')
            ->pluck('moduleId', 'salesforceId')
            ->map(fn ($v) => (int) $v)
            ->all();

        foreach (DB::table('properties')->select('id', 'referenceNumber')->get() as $p) {
            $this->refMap[(int) $p->id] = (string) $p->referenceNumber;
        }

        $path = $source.'/pba__Listing__c.csv';
        if (! is_file($path)) {
            return;
        }

        foreach (SalesforceCsvReader::rows($path) as $row) {
            $propertyId = $listingMap[trim((string) ($row['Id'] ?? ''))] ?? null;
            $propRef = trim((string) ($row['pba__Property__c'] ?? ''));
            if ($propertyId === null || $propRef === '') {
                continue;
            }
            // Backlog gate (--older-than-months): keep only listings created before the cutoff, so the
            // whole property's images are excluded once it falls inside the last-N-months window. A
            // listing with no CreatedDate can't be placed in time and is excluded.
            if ($this->untilCutoff !== null) {
                $created = $this->parseCarbon($row['CreatedDate'] ?? null);
                if ($created === null || $created->greaterThanOrEqualTo($this->untilCutoff)) {
                    continue;
                }
            }
            if ($this->shardTotal !== null && $propertyId % $this->shardTotal !== $this->shardIndex) {
                continue; // another shard owns this property
            }
            $this->propertyMap[$propRef] ??= $propertyId;
        }
    }

    private function download(array $row): ?string
    {
        $base = trim((string) ($row['pba__BaseUrl__c'] ?? ''));
        $filename = trim((string) ($row['pba__FilenameUrlEncoded__c'] ?? '')) !== ''
            ? trim((string) $row['pba__FilenameUrlEncoded__c'])
            : rawurlencode(trim((string) ($row['pba__Filename__c'] ?? '')));
        $url = ($base !== '' && $filename !== '')
            ? rtrim($base, '/').'/'.ltrim($filename, '/')
            : trim((string) ($row['pba__ExternalLink__c'] ?? ''));
        if ($url === '') {
            return null;
        }

        try {
            $response = Http::timeout(30)->retry(2, 1000, throw: false)->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function extension(array $row): string
    {
        $ext = strtolower(pathinfo(trim((string) ($row['pba__Filename__c'] ?? '')), PATHINFO_EXTENSION));

        return $ext !== '' ? $ext : 'jpg';
    }

    private function nextOrdering(int $propertyId): int
    {
        if (! isset($this->ordering[$propertyId])) {
            $this->ordering[$propertyId] = (int) (DB::table('property_media_files')
                ->where('propertyId', $propertyId)
                ->where('mediaType', 'uploadPhotos')
                ->max('ordering') ?? 0);
        }

        return ++$this->ordering[$propertyId];
    }

    private function resolveSource(string $path): ?string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $this->downloadRemoteSource($path);
        }
        if (is_file($path.'/pba__PropertyMedia__c.csv')) {
            return $path;
        }
        if (is_file($path.'/_merged/pba__PropertyMedia__c.csv')) {
            return $path.'/_merged';
        }
        $this->error("Could not find pba__PropertyMedia__c.csv under {$path} (or {$path}/_merged). Run the import/build once so the export is extracted.");

        return null;
    }

    /**
     * Download the CSVs this command needs from a remote base URL (e.g. an S3 bucket) into a
     * local temp dir, then use that as the source. The given URL may point at a CSV or the
     * containing folder; both pba__PropertyMedia__c.csv (the images) and pba__Listing__c.csv
     * (which maps the PropertyBase property to the CRM listing and carries the listing dates
     * for the --years window) must live at the same base. Cached between runs unless --refresh.
     */
    private function downloadRemoteSource(string $url): ?string
    {
        $base = preg_match('/\.csv$/i', $url)
            ? substr($url, 0, (int) strrpos($url, '/'))
            : rtrim($url, '/');

        $dir = storage_path('app/sf-remote-source');
        @mkdir($dir, 0775, true);

        // Listing CSV first (small) so a missing one fails fast before the large media download.
        foreach (['pba__Listing__c.csv', 'pba__PropertyMedia__c.csv'] as $file) {
            $target = $dir.'/'.$file;
            if (! $this->option('refresh') && is_file($target) && filesize($target) > 0) {
                $this->info("Using cached {$file}");

                continue;
            }

            $src = $base.'/'.$file;
            $this->info("Downloading {$src} …");
            try {
                $ok = Http::timeout(900)->sink($target)->get($src)->successful();
            } catch (Throwable) {
                $ok = false;
            }
            if (! $ok) {
                @unlink($target);
                $this->error("Failed to download {$file} from {$base}/. Make sure both pba__PropertyMedia__c.csv and pba__Listing__c.csv are uploaded there (and publicly readable).");

                return null;
            }
        }

        return $dir;
    }

    private function isTrue(?string $v): bool
    {
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes'], true);
    }

    private function parseDate(?string $v): ?string
    {
        return $this->parseCarbon($v)?->toDateTimeString();
    }

    private function parseCarbon(?string $v): ?\Carbon\Carbon
    {
        $v = trim((string) ($v ?? ''));
        if ($v === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($v);
        } catch (Throwable) {
            return null;
        }
    }
}
