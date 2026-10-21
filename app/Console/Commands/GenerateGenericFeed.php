<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\PropertiesController;
use Illuminate\Console\Command;

/**
 * Pre-builds the generic XML portal feed to a file, off the web request.
 *
 * The web endpoint (GET /api/generic-xml) only ever reads this file, so a large
 * catalogue can never time out or crash the web tier. This runs in CLI where
 * there is no web-server timeout, in constant memory (chunked, streamed to file).
 */
class GenerateGenericFeed extends Command
{
    protected $signature = 'app:generate-generic-feed';

    protected $description = 'Pre-build the generic XML portal feed file (runs off the web request).';

    public function handle(PropertiesController $controller): int
    {
        $start = microtime(true);

        try {
            $path = $controller->generateGenericFeedFile();
        } catch (\Throwable $e) {
            $this->error('Generic feed build failed: '.$e->getMessage());
            \Log::error('app:generate-generic-feed failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $size = is_file($path) ? filesize($path) : 0;
        $this->info(sprintf(
            'Generic feed built: %s (%s KB) in %.1fs',
            $path,
            number_format($size / 1024, 1),
            microtime(true) - $start,
        ));

        return self::SUCCESS;
    }
}
