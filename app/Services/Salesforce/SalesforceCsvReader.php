<?php

namespace App\Services\Salesforce;

use RuntimeException;

/**
 * Memory-safe streaming reader for the large Salesforce export CSVs.
 * Yields each data row as an associative array keyed by the header columns.
 */
class SalesforceCsvReader
{
    private static bool $filterRegistered = false;

    public static function rowCount(string $path): int
    {
        $count = 0;
        foreach (self::rows($path) as $_) {
            $count++;
        }

        return $count;
    }

    /**
     * @return \Generator<int, array<string, ?string>>
     */
    public static function rows(string $path): \Generator
    {
        if (! is_file($path)) {
            throw new RuntimeException("CSV not found: {$path}");
        }

        if (! self::$filterRegistered) {
            stream_filter_register(StripNullBytesFilter::NAME, StripNullBytesFilter::class);
            self::$filterRegistered = true;
        }

        $handle = fopen($path, 'r');
        stream_filter_append($handle, StripNullBytesFilter::NAME);

        try {
            // escape="" preserves backslashes literally (Salesforce uses RFC-style "" quoting).
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header === false) {
                return;
            }
            // Strip a UTF-8 BOM that can cling to the first column name.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $width = count($header);

            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue; // blank line
                }
                $row = array_slice($row, 0, $width);
                if (count($row) < $width) {
                    $row = array_pad($row, $width, null);
                }
                yield array_combine($header, array_map([self::class, 'toUtf8'], $row));
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Repair stray legacy bytes so values are storable in utf8mb4 columns. The export is mostly
     * valid UTF-8, but some fields carry isolated Windows-1252 bytes (e.g. \xA0 non-breaking
     * space, smart quotes) that MySQL rejects. Only high-byte runs that don't already form valid
     * UTF-8 are re-decoded from Windows-1252; ASCII and genuine UTF-8 multibyte chars pass through.
     */
    private static function toUtf8(?string $value): ?string
    {
        if ($value === null || $value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return preg_replace_callback('/[\x80-\xFF]+/', function (array $m): string {
            return mb_check_encoding($m[0], 'UTF-8')
                ? $m[0]
                : mb_convert_encoding($m[0], 'UTF-8', 'Windows-1252');
        }, $value);
    }
}
