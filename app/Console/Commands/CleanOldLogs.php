<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CleanOldLogs extends Command
{
    protected $signature = 'app:clean-old-logs {--days=30 : Delete log files last modified more than this many days ago}';

    protected $description = 'Delete old log files in storage/logs (request logs, laravel logs, supervisor logs, …)';

    public function handle(): int
    {
        $logPath = storage_path('logs');
        $threshold = Carbon::now()->subDays((int) $this->option('days'));

        // Every log file we produce: request-log-*.txt plus *.log
        // (laravel.log, daily-rotated laravel-*.log, supervisor-*.log, etc.).
        $files = array_merge(
            (array) glob("{$logPath}/*.log"),
            (array) glob("{$logPath}/*.txt"),
        );

        $deleted = 0;

        foreach ($files as $file) {
            // Skip anything still being written to — active logs keep a fresh
            // mtime, so this never deletes the file a running process holds open.
            if (Carbon::createFromTimestamp(File::lastModified($file))->gte($threshold)) {
                continue;
            }

            File::delete($file);
            $deleted++;
            $this->info('Deleted: '.basename($file));
        }

        $this->info($deleted === 0
            ? 'No old log files to clean up.'
            : "Cleaned up {$deleted} old log file(s).");

        return self::SUCCESS;
    }
}
