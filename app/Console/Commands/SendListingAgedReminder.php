<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Jobs\NotifyListingAgedJob;
use App\Models\Property;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendListingAgedReminder extends Command
{
    protected $signature = 'app:send-listing-aged-reminder';

    protected $description = 'Notify the listing agent (CRM + email) when a pocket or upcoming listing has sat in that status for 2 weeks and is still not published, sold, rented or withdrawn.';

    private const AGE_DAYS = 14;

    public function handle(): int
    {
        // Fire exactly on the 14th day so the reminder is sent once, not every day after.
        // A listing whose status moved to published/rentSold/unpublished/etc. has its
        // status-date meta dropped by the Property model, so the status filter alone
        // guarantees it is still sitting in the status we are reminding about.
        $targetDate = Carbon::today()->subDays(self::AGE_DAYS)->toDateString();

        // Status => the property_meta key holding the date the listing entered it.
        $watchedStatuses = [
            Constants::PROPERTY_STATUS_POCKET_LISTING => Constants::PROPERTY_META_KEYS['pocketListingDate'],
            Constants::PROPERTY_STATUS_UPCOMING => Constants::PROPERTY_META_KEYS['upcomingDate'],
        ];

        foreach ($watchedStatuses as $status => $metaKey) {
            $statusLabel = Constants::PROPERTY_STATUS[$status];

            $propertyIds = Property::where('status', $status)
                ->whereHas('meta', fn ($q) => $q
                    ->where('metaKey', $metaKey)
                    ->whereRaw('DATE(metaValue) = ?', [$targetDate]))
                ->pluck('id');

            if ($propertyIds->isEmpty()) {
                $this->info("No {$statusLabel} listings reached ".self::AGE_DAYS." days on {$targetDate}.");

                continue;
            }

            // Offload the actual notification + email work to the queue, one job per listing.
            foreach ($propertyIds as $propertyId) {
                NotifyListingAgedJob::dispatch((int) $propertyId, $status, self::AGE_DAYS);
            }

            $this->info("Dispatched {$propertyIds->count()} {$statusLabel} reminder job(s).");
        }

        return self::SUCCESS;
    }
}
