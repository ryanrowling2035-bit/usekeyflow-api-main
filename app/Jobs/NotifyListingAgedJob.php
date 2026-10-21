<?php

namespace App\Jobs;

use App\Constants\Constants;
use App\Models\Property;
use App\Services\MailService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyListingAgedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public function __construct(public int $propertyId, public string $status, public int $ageDays) {}

    public function handle(NotificationService $notificationService, MailService $mailService): void
    {
        $config = $this->configFor($this->status);
        if (! $config) {
            return;
        }

        $property = Property::with(['agent:id,name,email', 'creator:id,name,email', 'meta'])->find($this->propertyId);

        // Defensive: the listing may have changed status between scheduling and processing.
        if (! $property || $property->status !== $this->status) {
            return;
        }

        // Listing agent is the primary recipient; fall back to the creator.
        $recipient = $property->agent ?: $property->creator;
        if (! $recipient) {
            return;
        }

        $listingRef = $property->referenceNumber ?? (string) $property->id;
        $statusLabel = Constants::PROPERTY_STATUS[$this->status];
        $statusDate = optional($property->meta->firstWhere('metaKey', $config['metaKey']))->metaValue;

        // CRM notification
        $notificationService->notifyUser(
            userId: $recipient->id,
            actorId: $recipient->id,
            type: $config['notificationType'],
            title: "{$config['shortLabel']} listing review required: {$listingRef}",
            message: "{$listingRef} has been in {$config['shortLabel']} status for {$this->ageDays} days.",
            module: Constants::MODULE_PROPERTY,
            moduleId: $property->id,
            extraData: [
                'listingReference' => $listingRef,
                'propertyTitle' => $property->title,
                'currentStatus' => $statusLabel,
                'statusDate' => $statusDate,
                'daysInStatus' => $this->ageDays,
                // Back-compat keys the CRM already reads on pocket listing alerts.
                'pocketListingDate' => $this->status === Constants::PROPERTY_STATUS_POCKET_LISTING ? $statusDate : null,
                'daysInPocket' => $this->ageDays,
                'recommendedAction' => $config['recommendedAction'],
            ]
        );

        // Email notification
        if (empty($recipient->email)) {
            return;
        }

        try {
            $mailService->sendEmail(
                $config['emailSlug'],
                $recipient->email,
                [
                    'recipient_name' => ucwords($recipient->name),
                    'listing_reference' => $listingRef,
                    'property_title' => $property->title ?? '-',
                    'agent_name' => ucwords($recipient->name),
                    'created_date' => Carbon::parse($statusDate)->format('F d, Y'),
                    'current_status' => $statusLabel,
                    'days_in_status' => $this->ageDays,
                    // Back-compat macro still used by the pocket listing template.
                    'days_in_pocket' => $this->ageDays,
                    'recommended_action' => $config['recommendedAction'],
                    'property_link' => rtrim(env('FRONTEND_URL', config('app.url')), '/').'/dashboard/property/'.$property->id.'/view',
                    'company_name' => getCompanyName(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('NotifyListingAgedJob : sendEmail : '.$e->getMessage());
        }
    }

    /**
     * Per-status wiring: which date the listing aged off, which notification type and
     * email template to use, and what the agent is being asked to do about it.
     */
    private function configFor(string $status): ?array
    {
        return match ($status) {
            Constants::PROPERTY_STATUS_POCKET_LISTING => [
                'metaKey' => Constants::PROPERTY_META_KEYS['pocketListingDate'],
                'shortLabel' => 'Pocket',
                'notificationType' => Constants::NOTIFICATION_TYPES['pocket_listing_aged'],
                'emailSlug' => Constants::EMAIL_SLUGS['property']['pocket_listing_aged'],
                'recommendedAction' => 'Publish, update, or withdraw the listing, or change its status if it is no longer active.',
            ],
            Constants::PROPERTY_STATUS_UPCOMING => [
                'metaKey' => Constants::PROPERTY_META_KEYS['upcomingDate'],
                'shortLabel' => 'Upcoming',
                'notificationType' => Constants::NOTIFICATION_TYPES['upcoming_listing_aged'],
                'emailSlug' => Constants::EMAIL_SLUGS['property']['upcoming_listing_aged'],
                'recommendedAction' => 'Complete the listing and request its permit so it can go live, or change its status if it is no longer coming to market.',
            ],
            default => null,
        };
    }
}
