<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-rates enquiries after the lead-score bands change in Settings.
 *
 * enquiries.leadRating is a materialised value, kept in sync with the status by
 * the Enquiry observer — but only when the status itself changes. Editing the
 * _leadScore setting moves statuses between bands and leaves every existing row
 * on its old score, so this walks the affected statuses and corrects them.
 *
 * Rows with leadScoreLocked = true (manual overrides) are never touched.
 *
 * Each execution handles exactly one batch and then re-queues itself, so no
 * single run can approach the worker timeout or the queue's 90s retry_after.
 * The update is convergent — a retry or a duplicate run writes nothing.
 */
class RerateEnquiryLeadScoresJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    /** Rows examined per execution. */
    public const BATCH_SIZE = 2000;

    /** Array keys cannot be null, so statuses that carry no score group under this. */
    private const UNSCORED = '__unscored__';

    /**
     * @param  array<string, string|null>  $statusBands  status => lead score (null = no score)
     * @param  int  $lastId  keyset cursor into enquiries.id
     * @param  int  $updated  running total, carried along the chain for the final log line
     */
    public function __construct(
        public array $statusBands,
        public int $lastId = 0,
        public int $updated = 0,
    ) {}

    public function handle(): void
    {
        if ($this->statusBands === []) {
            return;
        }

        // Keyset-paginate on the primary key. enquiries.status has a cardinality
        // of 9, so MySQL scans it for the common statuses; walking by id keeps
        // the whole re-rate to one ordered pass instead of re-scanning from the
        // start on every batch.
        $rows = DB::table('enquiries')
            ->select('id', 'status', 'leadRating')
            ->where('id', '>', $this->lastId)
            ->where('leadScoreLocked', false)
            ->whereIn('status', array_keys($this->statusBands))
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get();

        if ($rows->isEmpty()) {
            $this->finish();

            return;
        }

        // Only rows whose band actually differs are written.
        $byBand = [];
        foreach ($rows as $row) {
            $band = $this->statusBands[$row->status] ?? null;
            if ($row->leadRating === $band) {
                continue;
            }
            $byBand[$band ?? self::UNSCORED][] = $row->id;
        }

        foreach ($byBand as $band => $ids) {
            // By primary key, and through the query builder so updated_at is
            // left alone: bumping it would suppress the 48h hot-enquiry
            // no-activity alert and scramble "last updated" ordering.
            $this->updated += DB::table('enquiries')
                ->whereIn('id', $ids)
                ->update(['leadRating' => $band === self::UNSCORED ? null : $band]);
        }

        $lastId = (int) $rows->last()->id;
        $exhausted = $rows->count() < self::BATCH_SIZE;

        // Released before the chain dispatches so a synchronous run (which
        // recurses through PendingDispatch) does not hold every batch in memory.
        unset($rows, $byBand);

        if ($exhausted) {
            $this->finish();

            return;
        }

        $next = self::dispatch($this->statusBands, $lastId, $this->updated);

        // Stay on the connection we were run from, so --sync keeps running inline.
        if ($connection = $this->job?->getConnectionName()) {
            $next->onConnection($connection);
        }
    }

    private function finish(): void
    {
        Log::info('RerateEnquiryLeadScoresJob: finished', [
            'statusBands' => $this->statusBands,
            'updated' => $this->updated,
        ]);
    }
}
