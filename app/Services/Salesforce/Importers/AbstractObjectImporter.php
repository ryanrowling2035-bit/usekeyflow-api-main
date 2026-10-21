<?php

namespace App\Services\Salesforce\Importers;

use App\Constants\Constants;
use App\Services\Salesforce\SalesforceCsvReader;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Base class for every Salesforce object importer.
 *
 * Responsibilities shared by all importers:
 *  - stream the object CSV (memory-safe)
 *  - resolve foreign keys via the salesforce_id_map table (in-memory cached)
 *  - upsert idempotently (re-running updates instead of duplicating)
 *  - preserve Salesforce CreatedDate / LastModifiedDate as row timestamps
 *  - record one or more salesforce_id_map aliases per imported record
 *
 * Subclasses implement the per-object field mapping in transform().
 */
abstract class AbstractObjectImporter
{
    protected int $chunkSize = 500;

    /**
     * Incremental window: when set, only rows created OR last-modified on/after this moment
     * are imported (existing CRM rows are updated, new ones inserted). Null = import every row.
     */
    protected ?Carbon $since = null;

    /**
     * Parallel shard filter (salesforce:import --shard=i/N): when set, only rows whose Salesforce
     * Id hashes into this shard are processed, so N instances can import disjoint rows of the SAME
     * object concurrently. $shardIndex is 0-based. Null = process every row.
     */
    protected ?int $shardIndex = null;

    protected ?int $shardTotal = null;

    /** Shared across importers for the whole run: objectType => [salesforceId => crmId] */
    protected static array $mapCache = [];

    protected int $total = 0;

    protected int $created = 0;

    protected int $updated = 0;

    protected int $skipped = 0;

    /**
     * Records linked to an existing CRM row (matched on email/phone/etc.) instead of
     * inserted. Each entry: "salesforceId (identifier) -> CRM #id", for the audit log.
     *
     * @var array<int, string>
     */
    protected array $duplicates = [];

    /**
     * Rows skipped (no Salesforce Id, filtered out, or transform error), with their
     * identifier and reason, for the audit log.
     *
     * @var array<int, string>
     */
    protected array $skippedRows = [];

    /**
     * Rows that updated an already-imported record (same Salesforce Id, re-run), with
     * their identifier and target CRM id, for the audit log.
     *
     * @var array<int, string>
     */
    protected array $updatedRows = [];

    /** column => max length, for string columns of the target table. */
    protected array $columnLengths = [];

    /** column => [precision, scale], for fixed-precision decimal columns. */
    protected array $columnNumeric = [];

    protected bool $columnsLoaded = false;

    public function __construct(
        protected Command $command,
        protected string $sourceDir,
        protected ?int $limit = null,
    ) {}

    /** Override the insert chunk size (salesforce:import --chunk). No-op for media importers. */
    public function setChunkSize(int $size): void
    {
        if ($size > 0) {
            $this->chunkSize = $size;
        }
    }

    /**
     * Restrict the import to an incremental window (salesforce:import --since): only rows
     * created or last-modified on/after the given date are processed. Pass null/"" to clear.
     */
    public function setSince(?string $date): void
    {
        $this->since = ($date === null || trim($date) === '') ? null : $this->parseDateTime($date);
    }

    /**
     * Restrict this importer to one shard of the rows (salesforce:import --shard=i/N): only rows
     * whose Salesforce Id falls in shard $index (0-based) of $total are processed. Run N instances
     * over the SAME object to parallelise it; the shards cover disjoint, non-overlapping row sets.
     * Pass null/total ≤ 1 to clear.
     */
    public function setShard(?int $index, ?int $total): void
    {
        if ($index === null || $total === null || $total <= 1) {
            $this->shardIndex = $this->shardTotal = null;

            return;
        }
        $this->shardIndex = $index;
        $this->shardTotal = $total;
    }

    /** Set the media age window in years (salesforce:blobs:* --years); see loadRecentPropertyIds(). */
    public function setMediaYears(int $years): void
    {
        if ($years > 0) {
            $this->mediaYears = $years;
        }
    }

    /** Enable S3 reuse: recreate DB rows for files already on S3 without re-uploading (clean re-seed). */
    public function setReuseS3(bool $reuse): void
    {
        $this->reuseS3 = $reuse;
    }

    /**
     * Override mode (salesforce:blobs:* --override): re-process every link even if it was already
     * imported. An existing record is overwritten in place with the freshly stored URL/metadata
     * instead of being skipped, and no duplicate salesforce_id_map entry is written.
     */
    public function setOverride(bool $override): void
    {
        $this->override = $override;
    }

    /**
     * Enable download mode (salesforce:blobs:download): instead of uploading files to S3, write
     * each into this local directory under its exact S3 key, so they can be uploaded later with
     * `aws s3 sync`. DB attach rows are still written, pointing at the eventual S3 URL.
     */
    public function setDownloadDir(?string $dir): void
    {
        $this->downloadDir = $dir === null ? null : rtrim($dir, '/');
    }

    /** Salesforce object name used as objectType in the id map (e.g. "Contact"). */
    abstract public function objectType(): string;

    /** Target CRM module constant (Constants::MODULE_*). */
    abstract public function module(): string;

    /** CSV filename inside the export directory. */
    abstract public function file(): string;

    /** Target database table name. */
    abstract protected function table(): string;

    /** Map a raw CSV row to a column => value array, or null to skip the row. */
    abstract protected function transform(array $row): ?array;

    /** Whether the target table has a deleted_at column to honour IsDeleted. */
    protected function hasSoftDeletes(): bool
    {
        return true;
    }

    public function label(): string
    {
        return class_basename(static::class);
    }

    /**
     * Human-readable identifier for a source row, used in the duplicate audit log.
     * Defaults to the Salesforce Id; subclasses override with email/phone/name.
     */
    protected function identifier(array $row): string
    {
        return trim((string) ($row['Id'] ?? '')) ?: '(no id)';
    }

    public function import(): array
    {
        $path = rtrim($this->sourceDir, '/').'/'.$this->file();
        $this->total = $this->created = $this->updated = $this->skipped = 0;
        $this->duplicates = [];
        $this->skippedRows = [];
        $this->updatedRows = [];

        // Preload this object's existing map so re-runs update instead of duplicate.
        $existing = $this->loadMap($this->objectType());

        $this->beforeImport();

        // Pre-count rows so progress can show processed/total while the import runs.
        $denominator = SalesforceCsvReader::rowCount($path);
        if ($this->limit !== null) {
            $denominator = min($denominator, $this->limit);
        }
        $step = max(200, intdiv($denominator, 100));

        $buffer = [];
        $processed = 0;

        foreach (SalesforceCsvReader::rows($path) as $row) {
            // Incremental import: silently drop rows outside the --since window before they
            // count, so the summary/audit log reflect only the records actually imported.
            if ($this->since !== null && ! $this->inSinceWindow($row)) {
                continue;
            }
            // Parallel sharding: skip rows owned by another shard before they count toward this
            // shard's totals, so each shard's summary/log reflects only the rows it imported.
            if ($this->shardTotal !== null && ! $this->inShard($row)) {
                continue;
            }
            if ($this->limit !== null && $processed >= $this->limit) {
                break;
            }
            $processed++;
            $this->total++;
            if ($processed % $step === 0) {
                $this->reportProgress($processed, $denominator);
            }

            $sfId = trim((string) ($row['Id'] ?? ''));
            if ($sfId === '') {
                $this->skipped++;
                $this->skippedRows[] = "{$this->identifier($row)} — no Salesforce Id";

                continue;
            }

            $attrs = null;
            $reason = 'filtered out';
            try {
                $attrs = $this->transform($row);
            } catch (Throwable $e) {
                $reason = 'transform error: '.$e->getMessage();
                $this->command->getOutput()->writeln(
                    "  <comment>skip {$sfId}: {$e->getMessage()}</comment>"
                );
            }
            if ($attrs === null) {
                $this->skipped++;
                $this->skippedRows[] = "{$sfId} ({$this->identifier($row)}) — {$reason}";

                continue;
            }

            if (isset($existing[$sfId])) {
                if ($this->updateRecord($existing[$sfId], $attrs, $row)) {
                    $this->updated++;
                    $this->updatedRows[] = "{$sfId} ({$this->identifier($row)}) -> CRM #{$existing[$sfId]}";
                } else {
                    // Changed-fields-only mode: nothing differed, the row was left untouched.
                    $this->skipped++;
                }

                continue;
            }

            // Update-only importers never create new CRM rows: a source row with no existing
            // mapping is skipped and logged rather than inserted.
            if (! $this->insertNewRecords()) {
                $this->skipped++;
                $this->skippedRows[] = "{$sfId} ({$this->identifier($row)}) — no existing record (update-only)";

                continue;
            }

            $buffer[] = [$sfId, $attrs, $row];
            if (count($buffer) >= $this->chunkSize) {
                $this->flush($buffer, $existing);
                $buffer = [];
            }
        }

        if ($buffer) {
            $this->flush($buffer, $existing);
        }

        $this->reportProgress($processed, $denominator, true);

        return [
            'total' => $this->total,
            'created' => $this->created,
            'updated' => $this->updated,
            'duplicates' => count($this->duplicates),
            'skipped' => $this->skipped,
            'duplicateLog' => $this->duplicates,
            'skippedLog' => $this->skippedRows,
            'updatedLog' => $this->updatedRows,
        ];
    }

    /**
     * Overwrite a single console line with live progress (processed/total + counts).
     * Skipped on non-decorated output (e.g. piped to a file) to keep logs clean; the
     * final call clears the line so the command's summary replaces it.
     */
    private function reportProgress(int $processed, int $total, bool $final = false): void
    {
        $out = $this->command->getOutput();
        if (! $out->isDecorated()) {
            return;
        }
        if ($final) {
            $out->write("\r\033[K");

            return;
        }
        $out->write(sprintf(
            "\r  %d/%d  (inserted %d, updated %d, skipped %d)",
            $processed, $total, $this->created, $this->updated, $this->skipped,
        ));
    }

    /**
     * Whether a row falls in the --since incremental window: created OR last-modified on/after
     * $this->since. Rows missing both timestamps are treated as out of window (excluded).
     */
    /**
     * Whether a row belongs to this shard (salesforce:import --shard=i/N). Rows are assigned by a
     * stable hash of the Salesforce Id, so the same row always lands in the same shard across runs
     * and the shards never overlap. Rows with no Id go to shard 0, so they are skipped (and logged)
     * exactly once rather than by every shard. crc32 is non-negative on 64-bit PHP.
     */
    private function inShard(array $row): bool
    {
        $sfId = trim((string) ($row['Id'] ?? ''));
        if ($sfId === '') {
            return $this->shardIndex === 0;
        }

        return crc32($sfId) % $this->shardTotal === $this->shardIndex;
    }

    private function inSinceWindow(array $row): bool
    {
        foreach (['CreatedDate', 'LastModifiedDate'] as $field) {
            $dt = $this->parseDateTime($row[$field] ?? null);
            if ($dt !== null && $dt->greaterThanOrEqualTo($this->since)) {
                return true;
            }
        }

        return false;
    }

    /** Hook for subclasses to preload reference data once per run. */
    protected function beforeImport(): void {}

    /** Return the id of an existing CRM record this row should link to (no insert), or null. */
    protected function matchExisting(array $row, array $attrs): ?int
    {
        return null;
    }

    /** propertyId => true for listings within the media-import age window (loaded on demand). */
    protected array $recentPropertyIds = [];

    /** Media age window in years — listings created within this many years keep their media. */
    protected int $mediaYears = 1;

    /** When true, reuse files already on S3 instead of re-downloading/re-uploading them. */
    protected bool $reuseS3 = false;

    /** When true, re-import overwrites an existing record in place instead of skipping it. */
    protected bool $override = false;

    /** When set, download mode: write files to this local directory (S3 key layout) instead of uploading. */
    protected ?string $downloadDir = null;

    /**
     * Load the listings eligible for media import — those created within the last --years
     * (default 1). Media (images and documents) for older listings is skipped so the import
     * only brings across the live inventory. Called once from a media importer's bootstrap.
     *
     * Recency is read straight from the listing export sheet's CreatedDate (the same value
     * the property's created_at is set from), joined to CRM ids via the pba__Listing__c map,
     * so there is no query against the properties table.
     */
    protected function loadRecentPropertyIds(): void
    {
        $listingMap = $this->loadMap('pba__Listing__c'); // Listing Id (a0V…) => CRM propertyId
        $path = rtrim($this->sourceDir, '/').'/pba__Listing__c.csv';
        if (! is_file($path)) {
            return;
        }

        $cutoff = now()->subYears($this->mediaYears);
        foreach (SalesforceCsvReader::rows($path) as $row) {
            $propertyId = $listingMap[trim((string) ($row['Id'] ?? ''))] ?? null;
            if ($propertyId === null) {
                continue;
            }
            $created = $this->parseDateTime($row['CreatedDate'] ?? null);
            if ($created !== null && $created->greaterThanOrEqualTo($cutoff)) {
                $this->recentPropertyIds[$propertyId] = true;
            }
        }
    }

    /** Whether a listing is recent enough (created within the last year) to import its media. */
    protected function isRecentProperty(int $propertyId): bool
    {
        return isset($this->recentPropertyIds[$propertyId]);
    }

    private function flush(array $buffer, array &$existing): void
    {
        // Retry on transient InnoDB deadlocks (concurrent scheduler/other writers).
        // Counters reset per attempt so a retried closure doesn't double-count.
        $baseCreated = $this->created;
        $baseDuplicates = $this->duplicates;
        DB::transaction(function () use ($buffer, &$existing, $baseCreated, $baseDuplicates) {
            $this->created = $baseCreated;
            $this->duplicates = $baseDuplicates;
            foreach ($buffer as [$sfId, $attrs, $row]) {
                // Matched an existing CRM record (on email/phone, etc.): link the
                // Salesforce id to it and log the duplicate — never insert, and never
                // touch the existing record's data. Checked here so duplicates created
                // earlier in this same run/chunk are also caught.
                $matchId = $this->matchExisting($row, $attrs);
                if ($matchId !== null) {
                    self::$mapCache[$this->objectType()][$sfId] = $matchId;
                    $existing[$sfId] = $matchId;
                    $this->recordAliases($sfId, $matchId, $row);
                    $this->afterSave($matchId, $row, false);
                    $this->duplicates[] = "{$sfId} ({$this->identifier($row)}) -> CRM #{$matchId}";

                    continue;
                }

                $attrs = $this->beforeInsert($attrs, $row);
                $attrs = $this->withTimestamps($attrs, $row, true);
                $id = DB::table($this->table())->insertGetId($this->clampToColumns($attrs));

                self::$mapCache[$this->objectType()][$sfId] = $id;
                $existing[$sfId] = $id;
                $this->recordAliases($sfId, $id, $row);
                $this->afterSave($id, $row, true);
                $this->created++;
            }
        }, 3);
    }

    /**
     * Update an existing CRM record from a re-imported row. Returns whether the row was
     * actually written: always true normally, but false in changed-fields-only mode when
     * every transformed column already matched the stored value (the row is left untouched,
     * keeping its updated_at).
     */
    private function updateRecord(int $id, array $attrs, array $row): bool
    {
        // Columns that are seeded on insert but must never be overwritten on re-import.
        foreach ($this->insertOnlyColumns() as $col) {
            unset($attrs[$col]);
        }
        // When set, restrict the update to this whitelist; all other columns keep their
        // current CRM values on re-import (updated_at is re-applied below).
        $only = $this->updateOnlyColumns();
        if ($only !== []) {
            $attrs = array_intersect_key($attrs, array_flip($only));
        }

        // Changed-fields-only mode: diff the transformed columns against the stored row and
        // write only the ones that actually differ.
        $changed = true;
        if ($this->updateChangedColumnsOnly()) {
            $current = DB::table($this->table())->where('id', $id)->first();
            if ($current !== null) {
                $attrs = $this->changedColumns($attrs, (array) $current);
            }
            $changed = $attrs !== [];
        }

        if ($changed) {
            $attrs = $this->withTimestamps($attrs, $row, false);
            $attrs = $this->beforeUpdate($attrs, $id, $row);
            DB::table($this->table())->where('id', $id)->update($this->clampToColumns($attrs));
        }
        $this->recordAliases($this->objectType(), null, $row); // no-op default
        $this->afterSave($id, $row, false);

        return $changed;
    }

    /**
     * Reduce a transformed attribute set to only the columns whose new value differs from the
     * stored row, for changed-fields-only updates. Columns absent from the stored row are
     * always kept (e.g. when the schema is ahead of the snapshot).
     */
    private function changedColumns(array $attrs, array $current): array
    {
        $changed = [];
        foreach ($attrs as $col => $new) {
            if (! array_key_exists($col, $current) || ! $this->valuesEqual($current[$col], $new)) {
                $changed[$col] = $new;
            }
        }

        return $changed;
    }

    /**
     * updateOrInsert that, in changed-fields-only mode, skips the write when the stored value
     * already matches — so a re-sync touches a child row (meta, note, …) only when its content
     * actually changed. Outside changed-only mode it is a plain updateOrInsert.
     *
     * @param  array<string, mixed>  $keys  unique-key columns identifying the row
     * @param  array<string, mixed>  $extra  additional columns to write alongside $valueColumn
     */
    protected function upsertChanged(string $table, array $keys, string $valueColumn, mixed $value, array $extra = []): void
    {
        if ($this->updateChangedColumnsOnly()) {
            $current = DB::table($table)->where($keys)->value($valueColumn);
            if ($current !== null && $this->valuesEqual($current, $value)) {
                return;
            }
        }
        DB::table($table)->updateOrInsert($keys, [$valueColumn => $value] + $extra);
    }

    /**
     * Type-tolerant equality between a stored DB value (string|null) and a freshly transformed
     * value: numbers compare by value, dates/Carbon by their stored string form, everything
     * else as strings; NULL matches only NULL.
     */
    protected function valuesEqual(mixed $old, mixed $new): bool
    {
        if ($new instanceof \DateTimeInterface) {
            $new = $new->format(strlen((string) $old) <= 10 ? 'Y-m-d' : 'Y-m-d H:i:s');
        }
        if ($old === null || $new === null) {
            return $old === null && $new === null;
        }
        if (is_numeric($old) && is_numeric($new)) {
            return (float) $old === (float) $new;
        }

        return (string) $old === (string) $new;
    }

    /**
     * Columns set when a record is first inserted but preserved (never overwritten)
     * when an existing record is updated on re-import. Default: none.
     *
     * @return array<int, string>
     */
    protected function insertOnlyColumns(): array
    {
        return [];
    }

    /**
     * Whitelist of columns to overwrite when an existing record is updated on re-import.
     * Empty (default) means update every transformed column (minus insertOnlyColumns()).
     * When non-empty, only these columns — plus updated_at — are written; all others
     * retain their current CRM values.
     *
     * @return array<int, string>
     */
    protected function updateOnlyColumns(): array
    {
        return [];
    }

    /**
     * Whether this importer may create new CRM rows. Default true. When false (update-only
     * importers), a source row with no existing mapping is skipped and logged rather than
     * inserted — only already-imported records are touched.
     */
    protected function insertNewRecords(): bool
    {
        return true;
    }

    /**
     * Whether re-imports write only the columns that actually changed. Default false (every
     * transformed column is written). When true, the stored row is diffed and unchanged
     * columns are left untouched; a row with no differences is not written at all.
     */
    protected function updateChangedColumnsOnly(): bool
    {
        return false;
    }

    /**
     * Clamp values to what the target column can hold — string values to the column's
     * max length, and numeric values to a fixed-precision decimal column's range
     * (e.g. decimal(5,2) caps at 999.99). Salesforce free-text and out-of-range numbers
     * (junk percentages, oversized amounts) are common; clamp rather than crash.
     */
    private function clampToColumns(array $attrs): array
    {
        if (! $this->columnsLoaded) {
            $rows = DB::table('information_schema.columns')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $this->table())
                ->select('column_name as col', 'character_maximum_length as len', 'numeric_precision as prec', 'numeric_scale as scale', 'data_type as type')
                ->get();
            foreach ($rows as $r) {
                if ($r->len !== null) {
                    $this->columnLengths[$r->col] = (int) $r->len;
                }
                if (in_array(strtolower((string) $r->type), ['decimal', 'numeric'], true) && $r->prec !== null) {
                    $this->columnNumeric[$r->col] = [(int) $r->prec, (int) $r->scale];
                }
            }
            $this->columnsLoaded = true;
        }

        foreach ($attrs as $col => $value) {
            $max = $this->columnLengths[$col] ?? 0;
            if ($max > 0 && is_string($value) && mb_strlen($value) > $max) {
                $attrs[$col] = mb_substr($value, 0, $max);

                continue;
            }
            if (is_numeric($value) && isset($this->columnNumeric[$col])) {
                [$prec, $scale] = $this->columnNumeric[$col];
                $limit = 10 ** ($prec - $scale) - 10 ** -$scale; // decimal(5,2) => 999.99
                $attrs[$col] = round(max(-$limit, min($limit, (float) $value)), $scale);
            }
        }

        return $attrs;
    }

    /** Hook to adjust attributes immediately before a fresh insert (within the transaction). */
    protected function beforeInsert(array $attrs, array $row): array
    {
        return $attrs;
    }

    /** Hook to adjust attributes immediately before an existing record is updated on re-import. */
    protected function beforeUpdate(array $attrs, int $id, array $row): array
    {
        return $attrs;
    }

    /** Hook for child-record / meta writes after the main row is saved. */
    protected function afterSave(int $crmId, array $row, bool $isNew): void {}

    /**
     * Salesforce id aliases to register for a created record.
     * Default: the object's own Id. Override to add e.g. AccountId.
     *
     * @return array<int, array{0:string,1:?string}> list of [objectType, salesforceId]
     */
    protected function aliasEntries(string $sfId, array $row): array
    {
        return [[$this->objectType(), $sfId]];
    }

    private function recordAliases(string $sfId, ?int $crmId, array $row): void
    {
        if ($crmId === null) {
            return; // update path: aliases already exist
        }
        $rows = [];
        $now = now();
        foreach ($this->aliasEntries($sfId, $row) as [$objectType, $aliasId]) {
            $aliasId = trim((string) $aliasId);
            if ($aliasId === '' || $aliasId === '000000000000000AAA') {
                continue;
            }
            $rows[] = [
                'objectType' => $objectType,
                'salesforceId' => $aliasId,
                'module' => $this->module(),
                'moduleId' => $crmId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            self::$mapCache[$objectType][$aliasId] = $crmId;
        }
        if ($rows) {
            DB::table('salesforce_id_map')->insertOrIgnore($rows);
        }
    }

    private function withTimestamps(array $attrs, array $row, bool $isNew): array
    {
        $modified = $this->parseDateTime($row['LastModifiedDate'] ?? null);
        if ($isNew) {
            $created = $this->parseDateTime($row['CreatedDate'] ?? null) ?? now();
            $attrs['created_at'] = $created;
            $attrs['updated_at'] = $modified ?? $created;
            if ($this->hasSoftDeletes() && $this->isTrue($row['IsDeleted'] ?? null)) {
                $attrs['deleted_at'] = $attrs['updated_at'];
            }
        } else {
            $attrs['updated_at'] = $modified ?? now();
        }

        return $attrs;
    }

    // ----- FK resolution -------------------------------------------------

    protected function loadMap(string $objectType): array
    {
        if (! isset(self::$mapCache[$objectType])) {
            self::$mapCache[$objectType] = DB::table('salesforce_id_map')
                ->where('objectType', $objectType)
                ->pluck('moduleId', 'salesforceId')
                ->map(fn ($v) => (int) $v)
                ->all();
        }

        return self::$mapCache[$objectType];
    }

    protected function resolve(string $objectType, ?string $sfId): ?int
    {
        $sfId = trim((string) $sfId);
        if ($sfId === '' || $sfId === '000000000000000AAA') {
            return null;
        }

        return $this->loadMap($objectType)[$sfId] ?? null;
    }

    /** Resolve a person reference that may be stored as a Contact id or an Account id. */
    protected function resolveContact(?string $contactSfId, ?string $accountSfId = null): ?int
    {
        return $this->resolve('Contact', $contactSfId)
            ?? $this->resolve('Account', $accountSfId);
    }

    /**
     * Resolve a polymorphic Salesforce reference (e.g. Activity WhatId) to the CRM
     * [module, moduleId] it points at, trying each known object type in priority order.
     * Returns [null, null] when nothing resolves. Callers must have loaded the relevant
     * maps in beforeImport().
     *
     * @return array{0:?string,1:?int}
     */
    protected function resolveModule(?string $sfId): array
    {
        $sfId = trim((string) ($sfId ?? ''));
        if ($sfId === '' || $sfId === '000000000000000AAA') {
            return [null, null];
        }

        foreach ([
            ['pba__Closing__c', Constants::MODULE_CLOSING],
            ['pba__Offer__c', Constants::MODULE_OFFER],
            ['pba__Request__c', Constants::MODULE_ENQUIRY],
            ['pba__Listing__c', Constants::MODULE_PROPERTY],
            ['Contact', Constants::MODULE_CONTACT],
            ['Account', Constants::MODULE_CONTACT],
        ] as [$objectType, $module]) {
            $id = $this->resolve($objectType, $sfId);
            if ($id !== null) {
                return [$module, $id];
            }
        }

        return [null, null];
    }

    // ----- value helpers -------------------------------------------------

    protected function str(?string $v, int $max = 0): ?string
    {
        $v = trim((string) ($v ?? ''));
        if ($v === '') {
            return null;
        }

        return $max > 0 ? mb_substr($v, 0, $max) : $v;
    }

    protected function num(?string $v): ?float
    {
        $v = trim((string) ($v ?? ''));
        if ($v === '') {
            return null;
        }
        $v = str_replace(',', '', $v);

        return is_numeric($v) ? (float) $v : null;
    }

    protected function intval(?string $v): ?int
    {
        $n = $this->num($v);

        return $n === null ? null : (int) $n;
    }

    protected function isTrue(?string $v): bool
    {
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes'], true);
    }

    /**
     * Normalise a phone number into a comparison key for duplicate detection.
     * Keeps digits only and, for longer numbers, the last 9 so that country-code
     * and local-prefix variants of the same UAE number collapse together
     * (e.g. +9715XXXXXXXX, 05XXXXXXXX and 5XXXXXXXX). Returns null when empty.
     */
    protected function phoneKey(?string $v): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($v ?? ''));
        if ($digits === '') {
            return null;
        }

        return strlen($digits) > 9 ? substr($digits, -9) : $digits;
    }

    /**
     * Normalise a phone/mobile number to E.164 form for storage. Thin wrapper
     * over the shared normalizePhoneE164() helper so the inbound import and the
     * SalesforceService outbound sync stay in lockstep (phone-based contact
     * dedup relies on both producing the same format).
     */
    protected function formatPhone(?string $v): ?string
    {
        return normalizePhoneE164($v);
    }

    protected function parseDate(?string $v): ?string
    {
        $dt = $this->parseDateTime($v);

        return $dt?->toDateString();
    }

    protected function parseDateTime(?string $v): ?Carbon
    {
        $v = trim((string) ($v ?? ''));
        if ($v === '') {
            return null;
        }
        try {
            return Carbon::parse($v);
        } catch (Throwable) {
            return null;
        }
    }

    /** First non-empty trimmed value among the given keys. */
    protected function first(array $row, array $keys): ?string
    {
        foreach ($keys as $k) {
            $v = trim((string) ($row[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return null;
    }

    /** Map a Salesforce value through a case-insensitive lookup table. */
    protected function lookup(?string $v, array $map, ?string $default = null): ?string
    {
        $v = strtolower(trim((string) ($v ?? '')));
        if ($v === '') {
            return $default;
        }

        return $map[$v] ?? $default;
    }
}
