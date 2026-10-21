<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Models\Enquiry;
use App\Models\EnquiryLinkedListing;
use App\Models\Property;
use App\Repositories\PropertyRepository;
use App\Services\EnquiryLinkedListingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PushEnquiryLinkedListings extends Command
{
    protected $signature = 'app:push-enquiry-linked-listings';

    protected $description = 'Hourly: for each active enquiry, push the 2 newest matching listings (excluding already-linked ones) to the external API and store them in enquiry_linked_listings.';

    /**
     * System user that owns the auto-generated links.
     */
    private const SYSTEM_USER_ID = 1;

    /**
     * Listings pushed per enquiry per run.
     */
    private const BATCH_SIZE = 2;

    public function handle(
        PropertyRepository $propertyRepository,
        EnquiryLinkedListingService $enquiryLinkedListingService
    ): int {
        // Only enquiries that have been in the system for at least an hour.
        $enquiries = Enquiry::whereIn('status', Constants::ENQUIRY_ACTIVE_STATUSES)
            ->where('created_at', '<=', now()->subHour())
            ->get();

        $pushedCount = 0;

        foreach ($enquiries as $enquiry) {
            $alreadyLinked = EnquiryLinkedListing::where('enquiryId', $enquiry->id)
                ->pluck('propertyId');

            // The matcher unions branches, so exclude/order/limit must wrap the union
            // (a chained whereNotIn would only filter the first branch).
            $matchQuery = $propertyRepository->matchActiveListingsForEnquiry($enquiry);

            // Soft-deletes are already filtered inside the subquery; drop the outer
            // global scope so it doesn't qualify properties.deleted_at against the alias.
            $matches = Property::query()
                ->withoutGlobalScopes()
                ->fromSub($matchQuery, 'matched_listings')
                ->when(
                    $alreadyLinked->isNotEmpty(),
                    fn ($query) => $query->whereNotIn('id', $alreadyLinked)
                )
                ->orderByDesc('created_at')
                ->limit(self::BATCH_SIZE)
                ->get();

            if ($matches->isEmpty()) {
                continue;
            }

            $propertyIds = $matches->pluck('id')->all();

            // Dummy API for now: log the payload that would be sent.
            Log::info('PushEnquiryLinkedListings: payload', [
                'enquiryId' => $enquiry->id,
                'propertyIds' => $propertyIds,
            ]);

            $enquiryLinkedListingService->linkPropertiesToEnquiry([
                'enquiryId' => $enquiry->id,
                'propertyId' => $propertyIds,
                'createdBy' => self::SYSTEM_USER_ID,
            ]);

            $pushedCount += count($propertyIds);
        }

        $this->info("Pushed {$pushedCount} linked listings across active enquiries.");

        return self::SUCCESS;
    }
}
