<?php

namespace App\Jobs;

use App\Http\Controllers\Api\PropertiesController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Builds the generic XML portal feed to a file, off the web request.
 *
 * The web endpoint (GET /api/generic-xml) only reads the pre-built file, so a
 * large catalogue can never time out / crash the web tier. This job runs on the
 * queue worker (no web timeout) in constant memory (chunked, streamed to file).
 *
 * ShouldBeUnique keeps overlapping runs from piling up if a build is still going.
 */
class GenerateGenericFeedJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    /** Unique lock lifetime (seconds) so a stuck job can't block dispatch forever. */
    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueId(): string
    {
        return 'generate-generic-feed';
    }

    public function handle(PropertiesController $controller): void
    {
        $start = microtime(true);
        $path = $controller->generateGenericFeedFile();
        $size = is_file($path) ? filesize($path) : 0;

        Log::info('GenerateGenericFeedJob: built generic feed', [
            'path' => $path,
            'kb' => round($size / 1024, 1),
            'seconds' => round(microtime(true) - $start, 1),
        ]);
    }
}
