<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\AssignTeamMember;
use App\Models\Closing;
use App\Models\Enquiry;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class OfferService
{
    public function list($request)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        $query = Offer::query()->with(['buyer', 'buyer.mergedInto', 'sellerLandlordContact', 'sellerLandlordContact.mergedInto', 'property.physicalOwner', 'property.physicalOwner.mergedInto', 'tasks', 'internalNotes', 'enquiry']);

        if (isAgent()) {
            $query->where('assignedAgentId', user()->id);
        } elseif ($request->filled('assignedAgentId')) {
            $query->where('assignedAgentId', $request->assignedAgentId);
        } elseif ($request->filled('teamId')) {
            $memberIds = AssignTeamMember::where('teamId', $request->teamId)->pluck('userId')->all();
            $query->whereIn('assignedAgentId', $memberIds ?: [0]);
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('offeredAmount', 'LIKE', "%{$search}%")
                    ->orWhere('status', 'LIKE', "%{$search}%")
                    ->orWhere('name', 'LIKE', "%{$search}%")
                    ->orWhereHas('buyer', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('property', function ($q) use ($search) {
                        $q->where('title', 'LIKE', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $statuses = array_map('trim', explode(',', $request->status));
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('listingType')) {
            $query->where('listingType', $request->listingType);
        }

        if ($request->filled('buyerId')) {
            $query->where('buyerId', $request->buyerId);
        }

        if ($request->filled('ownerId')) {
            $query->whereHas('buyer', function ($q) use ($request) {
                $q->where('nationality', $request->ownerId);
            });
        }

        if ($request->filled('startDate') && $request->filled('endDate')) {
            $query->whereBetween('created_at', [
                $request->startDate.' 00:00:00',
                $request->endDate.' 23:59:59',
            ]);
        }

        if ($request->filled('id')) {
            $query->orderByRaw(
                'CASE WHEN id = ? THEN 0 ELSE 1 END',
                [$request->id]
            );
        }

        if ($request->filled('sortBy') || $request->filled('sort_by')) {
            $sortBy = $request->sortBy ?? $request->sort_by;
            $order = $request->get('order', 'desc');

            match ($sortBy) {
                'offer_date', 'offerDate' => $query->orderBy('offerDate', $order),
                'offeredAmount' => $query->orderBy('offeredAmount', $order),
                'targetDate' => $query->orderBy('targetDate', $order),
                'listingPrice' => $query->orderBy('listingPrice', $order),
                'deposits' => $query->orderBy('deposits', $order),
                'potentialCommission' => $query->orderBy('potentialCommission', $order),
                'created_at' => $query->orderBy('created_at', $order),
                default => $query->latest(),
            };
        } else {
            $query->latest();
        }

        return $query->paginate($perPage);
    }

    public function listByEnquiryId($request, $enquiryId)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        $column = strtolower((string) $request->input('type')) === 'property' ? 'listingId' : 'enquiryId';

        $query = Offer::query()->with(['buyer', 'buyer.mergedInto', 'sellerLandlordContact', 'sellerLandlordContact.mergedInto', 'property.physicalOwner', 'property.physicalOwner.mergedInto', 'tasks', 'internalNotes', 'enquiry'])
            ->where($column, $enquiryId);

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('status', 'LIKE', "%{$search}%")
                    ->orWhereHas('buyer', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('sortBy') || $request->filled('sort_by')) {
            $sortBy = $request->sortBy ?? $request->sort_by;
            $order = $request->get('order', 'desc');

            match ($sortBy) {
                'offeredAmount' => $query->orderBy('offeredAmount', $order),
                'targetDate' => $query->orderBy('targetDate', $order),
                'created_at' => $query->orderBy('created_at', $order),
                default => $query->latest(),
            };
        } else {
            $query->latest();
        }

        return $query->paginate($perPage);
    }

    public function store(array $data)
    {
        $data['referenceNo'] = $this->generateReferenceNo();

        $offer = Offer::create($data);

        $actor = user();
        app()->terminating(function () use ($offer, $actor) {
            $this->notifyOfferCreated($offer, $actor);
        });

        return $offer;
    }

    public function generateReferenceNo(): string
    {
        $lastOffer = Offer::whereNotNull('referenceNo')
            ->orderBy('id', 'desc')
            ->first();

        $nextNumber = 1;
        if ($lastOffer && $lastOffer->referenceNo) {
            if (preg_match('/(\d+)$/', $lastOffer->referenceNo, $matches)) {
                $nextNumber = (int) $matches[1] + 1;
            }
        }

        // Skip past any number already taken.
        do {
            $referenceNo = 'O-'.str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
            $nextNumber++;
        } while (Offer::where('referenceNo', $referenceNo)->exists());

        return $referenceNo;
    }

    public function getById(int $id)
    {
        $query = Offer::with(['buyer', 'buyer.mergedInto', 'sellerLandlordContact', 'sellerLandlordContact.mergedInto', 'property.physicalOwner', 'property.physicalOwner.mergedInto', 'tasks', 'internalNotes', 'enquiry']);
        if (isAgent()) {
            $query->where('assignedAgentId', user()->id);
        }

        return $query->find($id);
    }

    public function update(int $id, array $data)
    {
        $query = Offer::query();
        $offer = $query->findOrFail($id);
        $oldStatus = $offer->status;
        $offer->update($data);

        $newStatus = $data['status'] ?? null;
        if ($newStatus && $oldStatus !== $newStatus) {
            $statusLabel = Constants::OFFER_STATUS[$newStatus] ?? $newStatus;
            $this->notifyOfferStatusChange($offer->fresh(), $statusLabel);
        }

        return $offer;
    }

    /**
     * Send CRM notification and email to the assigned agent when a new offer is created.
     */
    protected function notifyOfferCreated(Offer $offer, ?User $actor = null): void
    {
        $actor = $actor ?? user();
        if (! $actor || ! $offer->assignedAgentId || $offer->assignedAgentId == $actor->id) {
            return;
        }

        $agent = User::find($offer->assignedAgentId);
        if (! $agent) {
            return;
        }

        $offer->loadMissing('property');
        $notificationService = app(NotificationService::class);
        $mailService = app(MailService::class);

        $offerLink = env('FRONTEND_URL', config('app.url')).'/dashboard/offer/'.$offer->id.'/view';
        $offerName = $offer->name ?? "Offer #{$offer->id}";
        $propertyTitle = $offer->property?->title ?? 'N/A';

        $notificationService->notifyUser(
            userId: $agent->id,
            actorId: $actor->id,
            type: Constants::NOTIFICATION_TYPES['offer_created'],
            title: "New offer assigned to you: {$offerName}",
            message: "{$actor->name} created a new offer and assigned it to you: {$offerName}",
            module: Constants::MODULE_OFFER,
            moduleId: $offer->id,
            extraData: [
                'offerId' => $offer->id,
                'offerName' => $offerName,
            ]
        );

        if ($agent->email) {
            try {
                $mailService->sendEmail(
                    Constants::EMAIL_SLUGS['offer']['offer_created_agent'],
                    $agent->email,
                    [
                        'recipient_name' => ucwords($agent->name),
                        'offer_name' => $offerName,
                        'offer_id' => $offer->referenceNo ?? $offer->id,
                        'property_title' => $propertyTitle,
                        'offered_amount' => $offer->offeredAmount ?? 'N/A',
                        'status' => Constants::OFFER_STATUS[$offer->status] ?? ($offer->status ?? 'N/A'),
                        'created_by' => ucwords($actor->name),
                        'created_date' => now()->setTimezone(appTimezone())->format('F d, Y H:i'),
                        'offer_link' => $offerLink,
                        'company_name' => getCompanyName(),
                    ]
                );
            } catch (\Throwable $e) {
                Log::error("Failed to send offer created email to agent {$agent->email}: ".$e->getMessage());
            }
        }
    }

    /**
     * Notify admins, managers and the assigned agent when an offer status changes.
     */
    protected function notifyOfferStatusChange(Offer $offer, string $statusLabel): void
    {
        $actor = user();
        if (! $actor) {
            return;
        }

        $offer->loadMissing('property');
        $notificationService = app(NotificationService::class);
        $mailService = app(MailService::class);

        $adminRoleIds = Role::where('name', Constants::SUPER_ADMIN)->pluck('id');

        $recipientIds = User::whereIn('roleId', $adminRoleIds)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        if ($offer->assignedAgentId) {
            $recipientIds[] = $offer->assignedAgentId;
        }

        $recipientIds = array_values(array_unique(array_filter($recipientIds, fn ($id) => $id && $id != $actor->id)));

        if (empty($recipientIds)) {
            return;
        }

        $recipients = User::whereIn('id', $recipientIds)
            ->where('status', 'active')
            ->get(['id', 'name', 'email']);

        $offerLink = env('FRONTEND_URL', config('app.url')).'/dashboard/offer/'.$offer->id.'/view';
        $offerName = $offer->name ?? "Offer #{$offer->id}";
        $propertyTitle = $offer->property?->title ?? 'N/A';

        foreach ($recipients as $recipient) {
            $notificationService->notifyUser(
                userId: $recipient->id,
                actorId: $actor->id,
                type: Constants::NOTIFICATION_TYPES['offer_status_change'],
                title: "Offer status changed: {$offerName}",
                message: "{$actor->name} changed the status of offer \"{$offerName}\" to \"{$statusLabel}\".",
                module: Constants::MODULE_OFFER,
                moduleId: $offer->id,
                extraData: [
                    'offerId' => $offer->id,
                    'offerName' => $offerName,
                    'statusLabel' => $statusLabel,
                ]
            );

            if ($recipient->email) {
                try {
                    $mailService->sendEmail(
                        Constants::EMAIL_SLUGS['offer']['offer_status_change'],
                        $recipient->email,
                        [
                            'recipient_name' => ucwords($recipient->name),
                            'offer_name' => $offerName,
                            'offer_id' => $offer->referenceNo ?? $offer->id,
                            'property_title' => $propertyTitle,
                            'status' => $statusLabel,
                            'changed_by' => ucwords($actor->name),
                            'changed_date' => now()->setTimezone(appTimezone())->format('F d, Y H:i'),
                            'offer_link' => $offerLink,
                            'company_name' => getCompanyName(),
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::error("Failed to send offer status change email to {$recipient->email}: ".$e->getMessage());
                }
            }
        }
    }

    public function delete(int $id): bool
    {
        $query = Offer::query();
        $offer = $query->find($id);

        if (! $offer) {
            return false;
        }

        $offer->delete();

        return true;
    }

    public function createClosingIfAccepted(Offer $offer, ?string $status): void
    {
        if ($status !== Constants::OFFER_STATUS_ACCEPTED) {
            return;
        }

        $existingClosing = Closing::where('offerId', $offer->id)->first();
        if ($existingClosing) {
            return;
        }

        $property = Property::find($offer->listingId);
        if ($property) {
            $property->update(['status' => Constants::PROPERTY_STATUS_RENTED_SOLD]);
            $propertyStatusLabel = $offer->listingType === 'rent' ? 'Rented' : 'Sold';
            PropertyService::notifyAdminsManagersOnStatusChange($property, $propertyStatusLabel);
        }

        $enquiry = Enquiry::with('contact:id,nationality')
            ->select('id', 'contactId', 'leadSource', 'status')
            ->find($offer->enquiryId);

        $enquiryClosedStatus = $offer->listingType === 'rent'
            ? Constants::ENQUIRY_RENTED
            : Constants::ENQUIRY_SOLD;

        if ($enquiry && $enquiry->status !== $enquiryClosedStatus) {
            $previousStatus = $enquiry->status;
            $enquiry->update(['status' => $enquiryClosedStatus]);

            logEnquiryHistory([
                'enquiryId' => $enquiry->id,
                'field' => 'status',
                'originalValue' => $previousStatus,
                'newValue' => $enquiryClosedStatus,
            ]);
        }

        $closingService = app(ClosingService::class);

        $closing = $closingService->create([
            'name' => $offer->name,
            'listingId' => $offer->listingId,
            'enquiryId' => $offer->enquiryId,
            'assignedAgentId' => $offer->assignedAgentId,
            'offerId' => $offer->id,
            'buyerId' => $offer->buyerId,
            'status' => Constants::CLOSE_STATUS_OPEN,
            'listingType' => $offer->listingType,
            'sellerLandlord' => $offer->sellerLandlord,
            'saleRentalPrice' => $offer->offeredAmount,
            'targetCloseDate' => $offer->targetDate,
            'clientNationality' => $enquiry?->contact?->nationality,
            'clientLeadSource' => $enquiry?->leadSource,
            'buyerTenant' => $enquiry?->contact?->id,
            'createdBy' => auth()->id(),
        ]);

        logUserActivity([
            'action' => 'created',
            'module' => Constants::MODULE_CLOSING,
            'moduleId' => $closing->id,
            'description' => "Auto-created closing from accepted offer: {$offer->name}",
        ]);

        if ($offer->enquiryId) {
            logEnquiryHistory([
                'enquiryId' => $offer->enquiryId,
                'field' => 'closing_created',
                'originalValue' => null,
                'newValue' => json_encode(['message' => "Closing created from accepted offer: {$offer->name}"]),
            ]);
        }

        if ($offer->listingId) {
            logPropertyHistory([
                'propertyId' => $offer->listingId,
                'field' => 'closing_created',
                'newValue' => json_encode(['message' => "Closing created from accepted offer: {$offer->name}"]),
            ]);
        }
    }
}
