<?php

namespace App\Services\Salesforce;

use php_user_filter;

/**
 * Salesforce Weekly Export CSVs contain stray NUL (\0) bytes that break
 * line/field parsing. This stream filter scrubs them before fgetcsv runs.
 */
class StripNullBytesFilter extends php_user_filter
{
    public const NAME = 'salesforce_strip_null';

    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $bucket->data = str_replace("\x00", '', $bucket->data);
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
