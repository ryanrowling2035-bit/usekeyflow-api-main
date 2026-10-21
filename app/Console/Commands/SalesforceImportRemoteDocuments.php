<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Services\FileStorageService;
use App\Services\Salesforce\SalesforceCsvReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Import PropertyBase listing DOCUMENTS (pba__PropertyMedia__c where Category = Documents).
 *
 * These files live on PropertyBase's S3 and are referenced by URL in the same media CSV the image
 * import reads — only the Category differs. Nothing in the codebase reads Category=Documents, so
 * the brochures (and a handful of untagged PDFs/zips) were never brought across, even though the
 * floor plans and master plans were, by an earlier one-off run. This command closes that gap:
 *   1. downloads each file from pba__BaseUrl__c + pba__Filename__c,
 *   2. uploads it to the PRIVATE bucket under documents/property/{ref}/{mediaId}-{fileName},
 *   3. inserts the document_centers row with docType + verbatim pba__Tags__c.
 *
 * The storage key and row shape mirror the existing imported floor plans exactly, so the two sets
 * are indistinguishable afterwards. filePath stores the full unsigned private-bucket URL; serving
 * it requires signing (temporaryUrl), which DocumentCenterController/PropertyCrmResource already do.
 *
 * Rows whose pba__IsExternalLink__c is set are SKIPPED — those are video URLs mis-filed under
 * Category=Documents and belong in property_videos, not the document centre.
 *
 * Idempotent: a document is skipped when the listing already has a document_centers row with the
 * same title, or with the same (deterministic) filePath. That covers both this command's own
 * re-runs and the rows an earlier import already created under a different key layout.
 */
class SalesforceImportRemoteDocuments extends Command
{
    protected $signature = 'salesforce:import-remote-documents
        {path : Local export directory (or one containing _merged), OR an https URL to the CSVs — both pba__PropertyMedia__c.csv and pba__Listing__c.csv must be there}
        {--tag= : Only import rows whose pba__Tags__c matches this label (e.g. --tag=Brochure)}
        {--dry-run : Report what would be imported without downloading or writing}
        {--limit= : Stop after importing this many documents (for testing)}
        {--refresh : Re-download the remote CSVs even if a cached copy exists}';

    protected $description = 'Import PropertyBase listing documents (pba__PropertyMedia__c, Category=Documents) — download each file, upload to the private bucket, and insert the document_centers row with its tags.';

    /**
     * Salesforce tag label => document_centers.docType. Only labels that exist in
     * Constants::LISTING_DOCUMENT_TYPE get a docType; everything else (Brochure, …) stays null.
     */
    private const DOC_TYPE_BY_TAG = [
        'Floorplan' => 'floorPlan',
        'Master Plan' => 'masterPlan',
        'Site Map' => 'siteMap',
    ];

    private FileStorageService $files;

    /** pba__Property__c (a0k) => CRM propertyId. */
    private array $propertyMap = [];

    /** propertyId => referenceNumber (for the storage key). */
    private array $refMap = [];

    /** "propertyId|lower(title)" => true, for rows any earlier import already created. */
    private array $existingTitles = [];

    /** Every document_centers.filePath already stored, so a deterministic key can't duplicate. */
    private array $existingPaths = [];

    /** Next document_centers.referenceNo counter for the property module. */
    private int $refCounter = 0;

    private ?int $fallbackUserId = null;

    private bool $dryRun = false;

    private ?string $onlyTag = null;

    private int $imported = 0;

    private int $skippedExisting = 0;

    private int $skippedExternal = 0;

    private int $skippedNoProperty = 0;

    private int $downloadFailures = 0;

    /** list<array{ref:string, tags:string, file:string}> */
    private array $done = [];

    public function handle(): int
    {
        @ini_set('memory_limit', '1024M');

        $source = $this->resolveSource(rtrim((string) $this->argument('path'), '/'));
        if ($source === null) {
            return self::FAILURE;
        }

        $this->files = app(FileStorageService::class);
        $this->dryRun = (bool) $this->option('dry-run');
        $this->onlyTag = trim((string) $this->option('tag')) ?: null;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if ($this->dryRun) {
            $this->info('Dry run: nothing will be downloaded or written.');
        }
        if ($this->onlyTag !== null) {
            $this->info("Tag filter: only rows tagged \"{$this->onlyTag}\".");
        }

        $this->fallbackUserId = (int) (DB::table('users')->min('id') ?? 0) ?: null;
        $this->buildPropertyMap($source);
        $this->buildExistingIndex();
        $this->seedReferenceCounter();

        $path = $source.'/pba__PropertyMedia__c.csv';
        if (! is_file($path)) {
            $this->error("pba__PropertyMedia__c.csv not found in {$source}");

            return self::FAILURE;
        }

        $this->info(sprintf('Source: %s  (%d properties mapped, %d existing documents indexed)',
            $source, count($this->propertyMap), count($this->existingTitles)));

        foreach (SalesforceCsvReader::rows($path) as $row) {
            if ($limit !== null && $this->imported >= $limit) {
                break;
            }
            try {
                $this->importDocument($row);
            } catch (Throwable $e) {
                $this->downloadFailures++;
            }
        }

        $this->newLine();
        $this->report();
        $this->info(sprintf(
            'Done. Imported %d document(s)%s. Skipped: %d already present, %d external links, %d no property. Download failures: %d.',
            $this->imported,
            $this->dryRun ? ' (dry run)' : '',
            $this->skippedExisting, $this->skippedExternal, $this->skippedNoProperty, $this->downloadFailures,
        ));

        return self::SUCCESS;
    }

    private function importDocument(array $row): void
    {
        if (trim((string) ($row['pba__Category__c'] ?? '')) !== 'Documents') {
            return;
        }
        if ($this->isTrue($row['IsDeleted'] ?? null)) {
            return;
        }

        // External links are video URLs mis-filed as Documents; they belong in property_videos.
        if ($this->isTrue($row['pba__IsExternalLink__c'] ?? null)) {
            $this->skippedExternal++;

            return;
        }

        $mediaId = trim((string) ($row['Id'] ?? ''));
        if ($mediaId === '') {
            return;
        }

        $tags = $this->tagsFor($row['pba__Tags__c'] ?? null);
        if ($this->onlyTag !== null && ! in_array($this->onlyTag, $tags, true)) {
            return;
        }

        $propertyId = $this->propertyMap[trim((string) ($row['pba__Property__c'] ?? ''))] ?? null;
        if ($propertyId === null) {
            $this->skippedNoProperty++;

            return;
        }

        $fileName = $this->fileName($row, $mediaId);
        $ref = $this->refMap[$propertyId] ?? '';
        $key = $this->files->recordPrefix('documents/property', $ref !== '' ? $ref : null, $propertyId)
            .'/'.$mediaId.'-'.$fileName;
        $filePath = $this->files->privateUrl($key);

        // Already brought across — by this command, or by the earlier one-off import.
        if (isset($this->existingTitles[$propertyId.'|'.strtolower($fileName)])
            || isset($this->existingPaths[$filePath])) {
            $this->skippedExisting++;

            return;
        }

        if ($this->dryRun) {
            $this->recordDone($ref, $tags, $fileName);
            $this->imported++;
            // Claim it so a duplicate row in the CSV isn't counted twice.
            $this->existingTitles[$propertyId.'|'.strtolower($fileName)] = true;

            return;
        }

        $bytes = $this->download($row);
        if ($bytes === null || $bytes === '') {
            $this->downloadFailures++;

            return;
        }

        $this->files->putPrivate($key, $bytes);

        $created = $this->parseDate($row['CreatedDate'] ?? null);
        $modified = $this->parseDate($row['LastModifiedDate'] ?? null) ?? $created;

        DB::table('document_centers')->insert([
            'referenceNo' => $this->nextReference(),
            'module' => Constants::MODULE_PROPERTY,
            'moduleId' => $propertyId,
            'title' => $fileName,
            'filePath' => $filePath,
            'type' => trim((string) ($row['pba__MimeType__c'] ?? '')) ?: null,
            // Byte count of what we actually stored. pba__Size__c is a float string ("9015722.0"),
            // which would land a stray ".0" in the column; the existing rows hold plain integers.
            'size' => (string) strlen($bytes),
            'docType' => $this->docTypeFor($tags),
            'tags' => $tags !== [] ? json_encode($tags) : null,
            'createdBy' => $this->fallbackUserId,
            'created_at' => $created,
            'updated_at' => $modified,
        ]);

        $this->existingTitles[$propertyId.'|'.strtolower($fileName)] = true;
        $this->existingPaths[$filePath] = true;
        $this->imported++;
        $this->recordDone($ref, $tags, $fileName);
    }

    /** Split pba__Tags__c on ; and keep each label VERBATIM (same convention as image tags). */
    private function tagsFor(?string $raw): array
    {
        $out = [];
        foreach (preg_split('/;/', (string) $raw) as $part) {
            $label = trim((string) $part);
            if ($label !== '') {
                $out[] = $label;
            }
        }

        return array_values(array_unique($out));
    }

    /** First tag that maps to a real Constants::LISTING_DOCUMENT_TYPE key, else null. */
    private function docTypeFor(array $tags): ?string
    {
        foreach ($tags as $tag) {
            if (isset(self::DOC_TYPE_BY_TAG[$tag])) {
                return self::DOC_TYPE_BY_TAG[$tag];
            }
        }

        return null;
    }

    /** Original filename, stripped of anything that could escape its folder. */
    private function fileName(array $row, string $mediaId): string
    {
        $name = basename(str_replace('\\', '/', trim((string) ($row['pba__Filename__c'] ?? ''))));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/]+/', '', $name));

        return $name !== '' ? $name : $mediaId;
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
            $response = Http::timeout(120)->retry(2, 1000, throw: false)->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * pba__Property__c => CRM propertyId, plus propertyId => referenceNumber. salesforce_id_map only
     * maps pba__Listing__c, so pba__Listing__c.csv is the a0V => a0k bridge (same as the image import).
     */
    private function buildPropertyMap(string $source): void
    {
        $listingMap = DB::table('salesforce_id_map')
            ->where('objectType', 'pba__Listing__c')
            ->pluck('moduleId', 'salesforceId')
            ->map(fn ($v) => (int) $v)
            ->all();

        foreach (DB::table('properties')->select('id', 'referenceNumber')->cursor() as $p) {
            $this->refMap[(int) $p->id] = (string) $p->referenceNumber;
        }

        $path = $source.'/pba__Listing__c.csv';
        if (! is_file($path)) {
            $this->warn('pba__Listing__c.csv not found — no properties can be resolved.');

            return;
        }

        foreach (SalesforceCsvReader::rows($path) as $row) {
            $propertyId = $listingMap[trim((string) ($row['Id'] ?? ''))] ?? null;
            $propRef = trim((string) ($row['pba__Property__c'] ?? ''));
            if ($propertyId !== null && $propRef !== '') {
                $this->propertyMap[$propRef] ??= $propertyId;
            }
        }
    }

    /** Index every existing property document by title and by filePath, for idempotency. */
    private function buildExistingIndex(): void
    {
        foreach (DB::table('document_centers')
            ->where('module', Constants::MODULE_PROPERTY)
            ->select('moduleId', 'title', 'filePath')->cursor() as $d) {
            $this->existingTitles[$d->moduleId.'|'.strtolower(trim((string) $d->title))] = true;
            if ($d->filePath) {
                $this->existingPaths[$d->filePath] = true;
            }
        }
    }

    private function seedReferenceCounter(): void
    {
        $last = DB::table('document_centers')
            ->where('module', Constants::MODULE_PROPERTY)
            ->whereNotNull('referenceNo')
            ->orderByDesc('id')
            ->value('referenceNo');

        $this->refCounter = ($last && preg_match('/(\d+)$/', $last, $m)) ? (int) $m[1] : 0;
    }

    private function nextReference(): string
    {
        $prefix = Constants::DOCUMENT_REFERENCE_PREFIX[Constants::MODULE_PROPERTY] ?? 'PR';

        return $prefix.'-'.str_pad((string) ++$this->refCounter, 4, '0', STR_PAD_LEFT);
    }

    private function recordDone(string $ref, array $tags, string $file): void
    {
        $this->done[] = ['ref' => $ref, 'tags' => implode(', ', $tags) ?: '-', 'file' => $file];
    }

    private function report(): void
    {
        if ($this->done === []) {
            return;
        }
        $cap = 50;
        $rows = array_map(fn ($d) => [$d['ref'], $d['tags'], substr($d['file'], 0, 58)], array_slice($this->done, 0, $cap));
        $this->table(['referenceNumber', 'tags', 'file'], $rows);
        if (count($this->done) > $cap) {
            $this->line(sprintf('  … and %d more.', count($this->done) - $cap));
        }
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
        $this->error("Could not find pba__PropertyMedia__c.csv under {$path} (or {$path}/_merged).");

        return null;
    }

    /** Cache both CSVs from a remote base URL into storage/app/sf-remote-source (shared with the image import). */
    private function downloadRemoteSource(string $url): ?string
    {
        $base = preg_match('/\.csv$/i', $url)
            ? substr($url, 0, (int) strrpos($url, '/'))
            : rtrim($url, '/');

        $dir = storage_path('app/sf-remote-source');
        @mkdir($dir, 0775, true);

        foreach (['pba__Listing__c.csv', 'pba__PropertyMedia__c.csv'] as $file) {
            $target = $dir.'/'.$file;
            if (! $this->option('refresh') && is_file($target) && filesize($target) > 0) {
                $this->info("Using cached {$file}");

                continue;
            }
            $this->info("Downloading {$base}/{$file} …");
            try {
                $ok = Http::timeout(900)->sink($target)->get($base.'/'.$file)->successful();
            } catch (Throwable) {
                $ok = false;
            }
            if (! $ok) {
                @unlink($target);
                $this->error("Failed to download {$file} from {$base}/.");

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
        $v = trim((string) ($v ?? ''));
        if ($v === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($v)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }
}
