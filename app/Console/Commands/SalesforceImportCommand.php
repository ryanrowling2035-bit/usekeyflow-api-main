<?php

namespace App\Console\Commands;

use App\Services\Salesforce\Importers\AbstractObjectImporter;
use Illuminate\Console\Command;
use ZipArchive;

/**
 * Shared machinery for the Salesforce import commands. Subclasses declare which importers
 * they run (the pipeline) and may configure each importer before it runs; this base resolves
 * the export source (extracting/merging multi-part zips), runs the selected importers in
 * dependency order, and writes the per-module audit log.
 *
 * Abstract, so Laravel's command discovery skips it.
 */
abstract class SalesforceImportCommand extends Command
{
    /**
     * Importers this command runs, in dependency order: key => importer class.
     *
     * @return array<string, class-string<AbstractObjectImporter>>
     */
    abstract protected function pipeline(): array;

    /** Per-importer configuration hook (chunk size, age window, …) before import() runs. */
    protected function configureImporter(AbstractObjectImporter $importer): void {}

    /** Resolve the {path} argument, run the selected pipeline, and report a summary. */
    protected function runPipeline(): int
    {
        $sourceDir = $this->resolveSourceDir($this->argument('path'));
        if ($sourceDir === null) {
            return self::FAILURE;
        }

        $pipeline = $this->pipeline();
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        // Read --only / --from defensively: not every subcommand defines them.
        $onlyOpt = $this->options()['only'] ?? null;
        $only = $onlyOpt
            ? array_map('trim', explode(',', strtolower((string) $onlyOpt)))
            : array_keys($pipeline);

        // --from (defined only on commands with more than a couple of stages): import this
        // object and every later one in the pipeline.
        $from = $this->options()['from'] ?? null;
        if ($from) {
            $from = strtolower(trim((string) $from));
            $keys = array_keys($pipeline);
            $start = array_search($from, $keys, true);
            if ($start === false) {
                $this->error("Unknown --from object: {$from}. Valid: ".implode(', ', $keys));

                return self::FAILURE;
            }
            $only = array_slice($keys, $start);
        }

        // --shard=i/N: import only one shard of an object's rows so N instances can run in parallel
        // over disjoint rows. Safe for ONE object at a time only — later objects depend on earlier
        // ones via salesforce_id_map, so parallel shards spanning the pipeline would race on
        // cross-object FKs. Parallelise object-by-object with a barrier between objects.
        $shardIndex = $shardTotal = null;
        $shardOpt = $this->options()['shard'] ?? null;
        if ($shardOpt) {
            if (! preg_match('/^\s*(\d+)\s*\/\s*(\d+)\s*$/', (string) $shardOpt, $m)
                || (int) $m[2] < 1 || (int) $m[1] < 1 || (int) $m[1] > (int) $m[2]) {
                $this->error("Invalid --shard: {$shardOpt}. Use i/N with 1 ≤ i ≤ N, e.g. --shard=1/8.");

                return self::FAILURE;
            }
            if (count($only) !== 1) {
                $this->error('--shard requires a single object (use --only=<object>): parallel shards spanning multiple dependent objects would race on cross-object references. Run one object per shard set, with a barrier between objects.');

                return self::FAILURE;
            }
            $shardTotal = (int) $m[2];
            $shardIndex = (int) $m[1] - 1;
        }

        $logSuffix = $shardTotal !== null ? '-shard'.($shardIndex + 1).'of'.$shardTotal : '';
        $logFile = storage_path('logs/salesforce-import-'.now()->format('Ymd').$logSuffix.'.log');

        $this->info("Source: {$sourceDir}");
        $this->info('Objects: '.implode(', ', $only).($limit ? " (limit {$limit}/object)" : '').($shardTotal !== null ? ' [shard '.($shardIndex + 1)."/{$shardTotal}]" : ''));
        $this->info("Duplicate log: {$logFile}");
        $this->newLine();

        $summary = [];
        foreach ($pipeline as $key => $class) {
            if (! in_array($key, $only, true)) {
                continue;
            }

            /** @var AbstractObjectImporter $importer */
            $importer = new $class($this, $sourceDir, $limit);
            $this->configureImporter($importer);
            if ($shardTotal !== null) {
                $importer->setShard($shardIndex, $shardTotal);
            }

            $file = rtrim($sourceDir, '/').'/'.$importer->file();
            if (! is_file($file)) {
                $this->warn("  [{$key}] skipped — {$importer->file()} not found");

                continue;
            }

            $this->line("→ {$importer->label()} ({$importer->file()})");
            $start = microtime(true);
            $result = $importer->import();
            $secs = round(microtime(true) - $start, 1);

            $summary[$key] = $result + ['seconds' => $secs];
            $this->info("  total {$result['total']}, inserted {$result['created']}, duplicates {$result['duplicates']}, updated {$result['updated']}, skipped {$result['skipped']}  ({$secs}s)");

            $this->writeImportLog($logFile, $key, $importer->label(), $result);
        }

        $this->newLine();
        $this->info('Done.');
        $this->table(
            ['Module', 'Total', 'Inserted', 'Duplicates', 'Updated', 'Skipped'],
            collect($summary)->map(fn ($r, $key) => [
                $key, $r['total'], $r['created'], $r['duplicates'], $r['updated'], $r['skipped'],
            ])->values()->all(),
        );
        if (is_file($logFile)) {
            $this->info("Duplicate identifiers written to: {$logFile}");
        }

        return self::SUCCESS;
    }

    /** Append a module's duplicate and skipped identifiers to the run log. */
    private function writeImportLog(string $logFile, string $key, string $label, array $result): void
    {
        $sections = [
            ['duplicate(s)', $result['duplicates'], $result['duplicateLog'] ?? []],
            ['updated', $result['updated'], $result['updatedLog'] ?? []],
            ['skipped', $result['skipped'], $result['skippedLog'] ?? []],
        ];

        $lines = [];
        foreach ($sections as [$noun, $count, $rows]) {
            if (empty($rows)) {
                continue;
            }
            $lines[] = '';
            $lines[] = "===== {$label} ({$key}) — {$count} {$noun} of total {$result['total']} =====";
            $lines = array_merge($lines, $rows);
        }

        if ($lines === []) {
            return;
        }
        @mkdir(dirname($logFile), 0755, true);
        file_put_contents($logFile, implode(PHP_EOL, $lines).PHP_EOL, FILE_APPEND);
    }

    /**
     * Resolve the export source to a directory the importers can read. Accepts an already
     * extracted directory, a single .ZIP, or a folder of multi-part WE_…_N.ZIP files (which
     * are extracted and merged into one directory).
     */
    protected function resolveSourceDir(string $path): ?string
    {
        $path = rtrim($path, '/');

        if (is_dir($path)) {
            // Already-extracted export: the CSVs sit at the top level — use it directly.
            if (is_file($path.'/ContentVersion.csv') || is_file($path.'/pba__Listing__c.csv')) {
                return $path;
            }

            // A folder of multi-part Weekly Export zips (WE_…_1.ZIP … _N.ZIP): extract and
            // merge them into one directory the importers can read as a single export.
            $merged = $this->mergeExportParts($path);
            if ($merged !== null) {
                return $merged;
            }

            $this->error("Directory has no extracted CSVs and no recognisable export .zip parts: {$path}");

            return null;
        }

        if (is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'zip') {
            $target = preg_replace('/\.zip$/i', '', $path);
            if (is_dir($target)) {
                $this->info("Using already-extracted directory: {$target}");

                return $target;
            }

            $this->info("Extracting {$path} …");
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                $this->error("Could not open zip: {$path}");

                return null;
            }
            @mkdir($target, 0755, true);
            $zip->extractTo($target);
            $zip->close();

            return $target;
        }

        $this->error("Path is not a directory or .zip file: {$path}");

        return null;
    }

    /**
     * Merge a directory of multi-part Salesforce Weekly Export zips into one directory.
     *
     * Parts are named <prefix>_<n>.ZIP; the CSVs live only in part 1, the rest carry slices
     * of the ContentVersion/ binaries. We pick the largest same-prefix group (so stray
     * archives like sample_*.ZIP are ignored), integrity-check each part, and extract the
     * sound ones into "<dir>/_merged". A manifest records merged parts so re-running after
     * more parts arrive extracts only the new ones (the import itself is idempotent).
     * Corrupt/incomplete parts are skipped with a warning rather than aborting the run.
     *
     * @return string|null the merged directory, or null if no usable export parts were found
     */
    private function mergeExportParts(string $dir): ?string
    {
        $zips = array_unique(array_merge(glob($dir.'/*.ZIP') ?: [], glob($dir.'/*.zip') ?: []));

        $groups = [];
        foreach ($zips as $zip) {
            if (preg_match('/^(.*)_(\d+)\.zip$/i', basename($zip), $m)) {
                $groups[$m[1]][] = $zip;
            }
        }
        if ($groups === []) {
            return null;
        }

        // Largest group wins — the real export has many parts; stray samples have few.
        uasort($groups, fn ($a, $b) => count($b) <=> count($a));
        $prefix = array_key_first($groups);
        $parts = $groups[$prefix];
        natsort($parts);

        $this->info(sprintf('Export set "%s": %d part(s) found.', $prefix, count($parts)));

        $target = $dir.'/_merged';
        @mkdir($target, 0755, true);

        $manifestFile = $target.'/.merged-parts';
        $done = is_file($manifestFile)
            ? array_flip(array_filter(array_map('trim', explode("\n", (string) file_get_contents($manifestFile)))))
            : [];

        $merged = $alreadyDone = $corrupt = 0;
        foreach ($parts as $part) {
            $name = basename($part);
            if (isset($done[$name])) {
                $alreadyDone++;

                continue;
            }

            $zip = new ZipArchive;
            // CHECKCONS rejects truncated/incomplete downloads up front.
            if ($zip->open($part, ZipArchive::CHECKCONS) !== true) {
                $this->warn("  {$name} — corrupt or incomplete, skipped (re-download this part)");
                $corrupt++;

                continue;
            }
            $this->info("  extracting {$name} …");
            $ok = $zip->extractTo($target);
            $zip->close();
            if (! $ok) {
                $this->warn("  {$name} — extraction failed, skipped");
                $corrupt++;

                continue;
            }
            file_put_contents($manifestFile, $name.PHP_EOL, FILE_APPEND);
            $merged++;
        }

        $this->info(sprintf('  merged %d new, %d already merged, %d corrupt/skipped.', $merged, $alreadyDone, $corrupt));

        if (! is_file($target.'/ContentVersion.csv') && ! is_file($target.'/pba__Listing__c.csv')) {
            $this->error('  no CSVs in the merged export — part 1 (which holds the CSV files) is missing or corrupt.');

            return null;
        }

        return $target;
    }
}
