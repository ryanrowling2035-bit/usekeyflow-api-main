<?php

namespace App\Services\Salesforce\Importers;

use App\Constants\Constants;
use App\Services\FileStorageService;
use App\Services\Salesforce\SalesforceCsvReader;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import Salesforce Files (ContentVersion) and attach them to CRM records.
 *
 * Salesforce stores files in three parts:
 *  - ContentDocumentLink.csv : LinkedEntityId (a record) -> ContentDocumentId  (many-to-many)
 *  - ContentVersion.csv       : ContentDocumentId -> file metadata; the IsLatest=1 row is current
 *  - ContentVersion/<Id>      : the raw bytes, one file per ContentVersion Id
 *
 * Each link whose LinkedEntityId resolves to a CRM module gets its file stored as a
 * document_centers row under the resolved module — images included. This importer does
 * NOT write property galleries (property_media_files); every file is a document.
 *
 * Files go to the PRIVATE bucket and the row stores the full (unsigned) private-bucket URL.
 * The object is not publicly readable — serving it requires signing the key (temporaryUrl);
 * this importer only persists the bytes and records the URL.
 *
 * Two modes (the row stores the same private URL either way; only where the bytes land differs):
 *  - upload (default)        : upload each file to the private bucket.
 *  - download (setDownloadDir): write each file into a local dir mirroring the key layout
 *                               (no upload); the bytes are uploaded later with
 *                               `aws s3 sync <dir> s3://<private-bucket>`.
 *
 * Idempotent: a ContentDocumentLink Id is recorded in salesforce_id_map (objectType
 * "ContentLink") once imported, so re-runs skip links already brought across.
 */
class MediaImporter extends AbstractObjectImporter
{
    /** Salesforce object Id prefix => CRM module, for the link's LinkedEntityId. */
    private const ENTITY_PREFIX = [
        'a0V' => 'pba__Listing__c',
        'a0n' => 'pba__Request__c',
        'a0a' => 'pba__Offer__c',
        'a0D' => 'pba__Closing__c',
        '003' => 'Contact',
        '001' => 'Account',
    ];

    private FileStorageService $files;

    /** ContentDocumentId => [versionId, fileType, ext, title, size, owner, created, modified] */
    private array $docMeta = [];

    /** ContentDocumentIds linked to a CRM record — bounds what ContentVersion meta we keep. */
    private array $neededDocs = [];

    /** Links already imported in a previous run: ContentDocumentLink Id => true. */
    private array $importedLinks = [];

    /** "module:moduleId" => record reference number (or null), for storage paths. */
    private array $refCache = [];

    /** module => next document_centers reference number. */
    private array $refCounter = [];

    /** Fallback createdBy for document_centers (NOT NULL + FK to users). */
    private ?int $fallbackUserId = null;

    private int $documents = 0;

    private int $reused = 0;

    public function objectType(): string
    {
        return 'ContentVersion';
    }

    public function module(): string
    {
        return Constants::MODULE_PROPERTY;
    }

    public function file(): string
    {
        // The command checks this exists before running; the link CSV is read alongside.
        return 'ContentVersion.csv';
    }

    protected function table(): string
    {
        return 'document_centers';
    }

    protected function transform(array $row): ?array
    {
        return null; // unused — import() is fully overridden
    }

    public function import(): array
    {
        // Bulk one-time import: hold the FK maps plus the linked-file metadata in memory.
        @ini_set('memory_limit', '1024M');

        $this->total = $this->created = $this->updated = $this->skipped = 0;
        $this->duplicates = [];
        $this->skippedRows = [];
        $this->updatedRows = [];
        $this->documents = $this->reused = 0;

        $this->files = app(FileStorageService::class);

        // Maps resolveModule() needs to turn a LinkedEntityId into a CRM record.
        foreach (['User', 'Contact', 'Account', 'pba__Listing__c', 'pba__Request__c', 'pba__Offer__c', 'pba__Closing__c'] as $type) {
            $this->loadMap($type);
        }

        $this->fallbackUserId = (int) (DB::table('users')->min('id') ?? 0) ?: null;
        $this->loadImportedLinks();
        $this->seedReferenceCounters();

        $linkPath = rtrim($this->sourceDir, '/').'/ContentDocumentLink.csv';
        if (! is_file($linkPath)) {
            $this->command->warn('  ContentDocumentLink.csv not found — nothing to attach');

            return $this->result();
        }

        // Pass 1: collect the documents that are linked to a CRM-bound entity, so the
        // ContentVersion metadata we keep is bounded by real attachments (not the
        // ~117k Chatter/user-avatar links we'll never store).
        $this->collectNeededDocs($linkPath);
        $this->loadDocMeta();

        // Pass 2: process each link.
        $processed = 0;
        foreach (SalesforceCsvReader::rows($linkPath) as $link) {
            if ($this->limit !== null && $processed >= $this->limit) {
                break;
            }
            $processed++;
            $this->total++;

            try {
                $this->importLink($link);
            } catch (Throwable $e) {
                $this->skipped++;
                $this->skippedRows[] = ($link['Id'] ?? '(no id)')." — error: {$e->getMessage()}";
            }
        }

        $this->command->info($this->downloadDir !== null
            ? "  downloaded documents {$this->documents} to {$this->downloadDir}"
            : "  documents {$this->documents}".($this->reuseS3 ? ", reused from S3 {$this->reused}" : ''));

        return $this->result();
    }

    private function importLink(array $link): void
    {
        $linkId = trim((string) ($link['Id'] ?? ''));
        $docId = trim((string) ($link['ContentDocumentId'] ?? ''));
        $entityId = trim((string) ($link['LinkedEntityId'] ?? ''));

        if ($linkId === '' || $docId === '' || $entityId === '') {
            $this->skipped++;

            return;
        }

        // Re-runs skip links already recorded in the id map — unless --override is set, which
        // re-processes them and overwrites the stored document in place.
        $alreadyLinked = isset($this->importedLinks[$linkId]);
        if ($alreadyLinked && ! $this->override) {
            $this->duplicates[] = "{$linkId} — already imported";

            return;
        }

        [$module, $moduleId] = $this->resolveEntity($entityId);
        if ($module === null) {
            // Chatter/user avatars and other non-CRM owners — not attached anywhere.
            $this->skipped++;

            return;
        }

        $meta = $this->docMeta[$docId] ?? null;
        if ($meta === null) {
            $this->skipped++;
            $this->skippedRows[] = "{$linkId} — no live ContentVersion for {$docId}";

            return;
        }

        $binary = rtrim($this->sourceDir, '/').'/ContentVersion/'.$meta['versionId'];
        if (! is_file($binary)) {
            $this->skipped++;
            $this->skippedRows[] = "{$linkId} — binary missing ({$meta['versionId']})";

            return;
        }

        $bytes = @file_get_contents($binary);
        if ($bytes === false || $bytes === '') {
            $this->skipped++;
            $this->skippedRows[] = "{$linkId} — unreadable binary ({$meta['versionId']})";

            return;
        }

        // Every file — images included — is imported as a document under its resolved module.
        // Property galleries (property_media_files) are not written by this importer. With
        // --override an existing document is overwritten in place; storeDocument() counts it.
        $this->storeDocument($module, $moduleId, $meta, $bytes);

        // A link already in the map keeps its single entry — only brand-new links are recorded.
        if (! $alreadyLinked) {
            $this->recordLink($linkId, $module, $moduleId);
        }
        $this->documents++;
    }

    /**
     * Store a file's bytes in the PRIVATE bucket and return its full (unsigned) private-bucket
     * URL to record on the document row. The object is not publicly readable — serving it
     * requires signing the key (temporaryUrl); this importer only persists the bytes + URL.
     *
     *  - download mode : write the file into the local mirror under its key (no upload); the
     *    full URL is stored so the row resolves once `aws s3 sync <dest> s3://<private-bucket>` runs.
     *  - --reuse-s3    : the object is already in the private bucket — skip the upload, reuse it.
     *  - default       : upload to the private bucket.
     */
    private function persist(string $key, string $bytes): string
    {
        if ($this->downloadDir !== null) {
            $dest = $this->downloadDir.'/'.ltrim($key, '/');
            @mkdir(dirname($dest), 0775, true);
            file_put_contents($dest, $bytes);

            return $this->files->privateUrl($key);
        }

        if ($this->reuseS3 && $this->files->existsPrivate($key)) {
            $this->reused++;

            return $this->files->privateUrl($key);
        }

        $this->files->putPrivate($key, $bytes);

        return $this->files->privateUrl($key);
    }

    /** Private-bucket key for a document under its owning module's folder, named by its original file. */
    private function documentKey(string $module, int $moduleId, array $meta): string
    {
        $base = $this->files->recordPrefix('documents/'.$module, $this->recordReference($module, $moduleId), $moduleId);

        return $base.'/'.$meta['fileName'];
    }

    /**
     * Resolve the original filename to store the object under: the client filename (which carries
     * the extension) when present, otherwise the title with its extension appended. Stripped of
     * any path separators/control characters so it can't escape its folder; spaces are preserved
     * (the URL encodes them). Falls back to "file" when nothing usable remains.
     */
    private function originalFileName(string $pathOnClient, string $title, string $ext): string
    {
        $name = basename(str_replace('\\', '/', trim($pathOnClient)));
        if ($name === '') {
            $name = $title.($ext !== '' && ! str_ends_with(strtolower($title), '.'.$ext) ? '.'.$ext : '');
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/]+/', '', $name));

        return mb_substr($name !== '' ? $name : 'file', 0, 255);
    }

    private function storeDocument(string $module, int $moduleId, array $meta, string $bytes): int
    {
        $key = $this->documentKey($module, $moduleId, $meta);
        $path = $this->persist($key, $bytes);

        // --override: overwrite the existing document in place rather than inserting a duplicate.
        // The storage key is deterministic (module + record + original filename), so the same
        // file maps to the same full URL on every run — match the existing row on it.
        if ($this->override) {
            $existingId = DB::table('document_centers')
                ->where('module', $module)
                ->where('moduleId', $moduleId)
                ->where('filePath', $path)
                ->value('id');

            if ($existingId !== null) {
                DB::table('document_centers')->where('id', $existingId)->update([
                    'title' => $meta['title'],
                    'filePath' => $path,
                    'type' => $meta['fileType'] ?: null,
                    'size' => $meta['size'] !== null ? (string) $meta['size'] : null,
                    'updated_at' => $meta['modified'],
                ]);
                $this->updated++;

                return (int) $existingId;
            }
        }

        $id = DB::table('document_centers')->insertGetId([
            'referenceNo' => $this->nextReference($module),
            'module' => $module,
            'moduleId' => $moduleId,
            'title' => $meta['title'],
            'filePath' => $path,
            'type' => $meta['fileType'] ?: null,
            'size' => $meta['size'] !== null ? (string) $meta['size'] : null,
            'docType' => null,
            'createdBy' => $this->resolveUser($meta['owner']) ?? $this->fallbackUserId,
            'created_at' => $meta['created'],
            'updated_at' => $meta['modified'],
        ]);
        $this->created++;

        return $id;
    }

    /**
     * Resolve (and cache) a record's human reference number so its files can be grouped
     * under {root}/{module}/{reference}/. Returns null for modules without a reference
     * (e.g. contact) — the storage helper's id fallback then applies.
     */
    private function recordReference(string $module, int $moduleId): ?string
    {
        $cacheKey = $module.':'.$moduleId;
        if (! array_key_exists($cacheKey, $this->refCache)) {
            [$table, $column] = match ($module) {
                Constants::MODULE_PROPERTY => ['properties', 'referenceNumber'],
                Constants::MODULE_ENQUIRY => ['enquiries', 'referenceNo'],
                Constants::MODULE_OFFER => ['offers', 'referenceNo'],
                Constants::MODULE_CLOSING => ['closings', 'referenceNo'],
                default => [null, null],
            };
            $this->refCache[$cacheKey] = $table === null
                ? null
                : DB::table($table)->where('id', $moduleId)->value($column);
        }

        return $this->refCache[$cacheKey];
    }

    /** Resolve a LinkedEntityId to [crmModule, crmId] using its Salesforce Id prefix. */
    private function resolveEntity(string $entityId): array
    {
        $objectType = self::ENTITY_PREFIX[substr($entityId, 0, 3)] ?? null;
        if ($objectType === null) {
            return [null, null];
        }

        $moduleByObject = [
            'pba__Listing__c' => Constants::MODULE_PROPERTY,
            'pba__Request__c' => Constants::MODULE_ENQUIRY,
            'pba__Offer__c' => Constants::MODULE_OFFER,
            'pba__Closing__c' => Constants::MODULE_CLOSING,
            'Contact' => Constants::MODULE_CONTACT,
            'Account' => Constants::MODULE_CONTACT,
        ];

        $id = $this->resolve($objectType, $entityId);

        return $id === null ? [null, null] : [$moduleByObject[$objectType], $id];
    }

    private function resolveUser(?string $sfId): ?int
    {
        return $this->resolve('User', $sfId);
    }

    /** Pass 1: mark every ContentDocumentId linked to a CRM-bound entity prefix. */
    private function collectNeededDocs(string $linkPath): void
    {
        foreach (SalesforceCsvReader::rows($linkPath) as $link) {
            $entityId = trim((string) ($link['LinkedEntityId'] ?? ''));
            $docId = trim((string) ($link['ContentDocumentId'] ?? ''));
            if ($docId === '' || ! isset(self::ENTITY_PREFIX[substr($entityId, 0, 3)])) {
                continue;
            }
            $this->neededDocs[$docId] = true;
        }
    }

    /**
     * Build ContentDocumentId => latest-version metadata from ContentVersion.csv,
     * keeping only documents in $neededDocs. Dates are stored as strings (not Carbon)
     * to keep this 100k+ row map within memory.
     */
    private function loadDocMeta(): void
    {
        $path = rtrim($this->sourceDir, '/').'/ContentVersion.csv';
        if (! is_file($path)) {
            return;
        }
        foreach (SalesforceCsvReader::rows($path) as $row) {
            if (! $this->isTrue($row['IsLatest'] ?? null)) {
                continue;
            }
            $docId = trim((string) ($row['ContentDocumentId'] ?? ''));
            $versionId = trim((string) ($row['Id'] ?? ''));
            if ($docId === '' || $versionId === '' || ! isset($this->neededDocs[$docId])) {
                continue;
            }
            $pathOnClient = (string) ($row['PathOnClient'] ?? '');
            $ext = strtolower(pathinfo($pathOnClient, PATHINFO_EXTENSION));
            $title = mb_substr($this->str($row['Title'] ?? null) ?? ($pathOnClient ?: 'file'), 0, 255);
            $created = $this->parseDateTime($row['CreatedDate'] ?? null)?->toDateTimeString() ?? now()->toDateTimeString();
            $this->docMeta[$docId] = [
                'versionId' => $versionId,
                'fileType' => trim((string) ($row['FileType'] ?? '')),
                'ext' => $ext,
                'title' => $title,
                // Original filename used as the storage object name: the client filename if
                // present (it carries the extension), else the title with its extension appended.
                'fileName' => $this->originalFileName($pathOnClient, $title, $ext),
                'size' => $this->intval($row['ContentSize'] ?? null),
                'owner' => trim((string) ($row['OwnerId'] ?? '')) ?: null,
                'created' => $created,
                'modified' => $this->parseDateTime($row['LastModifiedDate'] ?? null)?->toDateTimeString() ?? $created,
            ];
        }
    }

    private function loadImportedLinks(): void
    {
        $this->importedLinks = DB::table('salesforce_id_map')
            ->where('objectType', 'ContentLink')
            ->pluck('salesforceId')
            ->flip()
            ->all();
    }

    /**
     * Record an imported ContentDocumentLink for idempotency. moduleId points at the
     * owning CRM module record (property/contact/etc.) — never the property_media_files
     * or document_centers row id, whose id spaces overlap and would be ambiguous here.
     */
    private function recordLink(string $linkId, string $module, int $moduleId): void
    {
        DB::table('salesforce_id_map')->insertOrIgnore([
            'objectType' => 'ContentLink',
            'salesforceId' => $linkId,
            'module' => $module,
            'moduleId' => $moduleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->importedLinks[$linkId] = true;
    }

    private function seedReferenceCounters(): void
    {
        foreach ([Constants::MODULE_PROPERTY, Constants::MODULE_ENQUIRY, Constants::MODULE_CONTACT, Constants::MODULE_OFFER, Constants::MODULE_CLOSING] as $module) {
            $last = DB::table('document_centers')
                ->where('module', $module)
                ->whereNotNull('referenceNo')
                ->orderByDesc('id')
                ->value('referenceNo');
            $this->refCounter[$module] = ($last && preg_match('/(\d+)$/', $last, $m)) ? (int) $m[1] : 0;
        }
    }

    private function nextReference(string $module): string
    {
        $prefix = Constants::DOCUMENT_REFERENCE_PREFIX[$module] ?? strtoupper(substr($module, 0, 2));
        $this->refCounter[$module] = ($this->refCounter[$module] ?? 0) + 1;

        return $prefix.'-'.str_pad((string) $this->refCounter[$module], 4, '0', STR_PAD_LEFT);
    }

    private function result(): array
    {
        return [
            'total' => $this->total,
            'created' => $this->created,
            'updated' => $this->updated,
            'duplicates' => count($this->duplicates),
            'skipped' => $this->skipped,
            'duplicateLog' => $this->duplicates,
            'skippedLog' => $this->skippedRows,
            'updatedLog' => [],
        ];
    }
}
