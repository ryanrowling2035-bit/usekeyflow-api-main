<?php

namespace App\Services;

use App\Constants\Constants;
use App\Jobs\NotifyAgentClosingCompletedJob;
use App\Jobs\NotifyClosingDocumentsMissingJob;
use App\Jobs\NotifyEnquiryLostByColleagueJob;
use App\Jobs\SyncPropertyToPortalsJob;
use App\Models\AssignTeamMember;
use App\Models\Closing;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ClosingService
{
    public function list(Request $request)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        $query = Closing::query()->with(['property', 'buyer', 'buyer.mergedInto', 'sellerLandlordContact', 'sellerLandlordContact.mergedInto', 'buyerTenantContact', 'buyerTenantContact.mergedInto', 'offer', 'enquiry', 'assignedAgent', 'tasks', 'creator']);

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
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('referenceNo', 'LIKE', "%{$search}%")
                    ->orWhere('status', 'LIKE', "%{$search}%")
                    ->orWhere('listingType', 'LIKE', "%{$search}%")
                    ->orWhereHas('buyer', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('property', function ($q) use ($search) {
                        $q->where('referenceNumber', 'LIKE', "%{$search}%")
                            ->orWhere('title', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('enquiry', function ($q) use ($search) {
                        $q->where('referenceNo', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('offer', function ($q) use ($search) {
                        $q->where('referenceNo', 'LIKE', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $statuses = array_filter(array_map('trim', explode(',', $request->status)));
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('listingType')) {
            $query->where('listingType', $request->listingType);
        }

        if ($request->filled('buyerId')) {
            $query->where('buyerId', $request->buyerId);
        }

        if ($request->filled('startDate') && $request->filled('endDate')) {
            $query->whereBetween('created_at', [
                $request->startDate.' 00:00:00',
                $request->endDate.' 23:59:59',
            ]);
        }

        if ($request->filled('closingStartDate') && $request->filled('closingEndDate')) {
            $query->whereBetween('closingDate', [
                $request->closingStartDate.' 00:00:00',
                $request->closingEndDate.' 23:59:59',
            ]);
        }

        // Company Performance / Enquiry Conversion "by Closed Deals" drill-downs.
        // Nationality is stored on the closing; 'Unknown' means it is blank/null.
        if ($request->filled('clientNationality')) {
            $request->clientNationality === 'Unknown'
                ? $query->where(fn ($q) => $q->whereNull('clientNationality')->orWhere('clientNationality', ''))
                : $query->where('clientNationality', $request->clientNationality);
        }

        // Community lives on the linked property (pfCommunity = communityId).
        // 'Unknown' means the listing has no community set.
        if ($request->filled('community')) {
            $request->community === 'Unknown'
                ? $query->whereDoesntHave('property', fn ($q) => $q->whereNotNull('pfCommunity')->where('pfCommunity', '!=', ''))
                : $query->whereHas('property', fn ($q) => $q->where('pfCommunity', $request->community));
        }

        // Lead source is stored on the closing (clientLeadSource) — powers the
        // "Closed Deals by Source" drill-down. Takes a comma-separated list, like
        // `status` above and the enquiry-side `leadSource` filter, so the two
        // modules answer a multi-select the same way.
        //
        // Each selection expands to every spelling that rolls up to it: one portal
        // reached the CRM under several spellings, so an exact match returns a
        // fraction of the chart row the drill-down was opened from. No 'Unknown'
        // branch here (unlike nationality above): the source charts drop blank
        // sources, so no slice can ever drill down on one.
        if ($request->filled('clientLeadSource')) {
            $values = [];
            foreach (array_filter(array_map('trim', explode(',', $request->clientLeadSource))) as $selection) {
                $values = array_merge($values, Constants::leadSourceGroupMembers($selection) ?: [$selection]);
            }

            if ($values !== []) {
                $query->whereIn('clientLeadSource', array_values(array_unique($values)));
            }
        }

        // Lead Source Performance's "Deals Closed by Community" buckets on the
        // ENQUIRY's community (not the property's), so this is separate from the
        // property-based `community` filter. 'Unknown' = enquiry has none.
        if ($request->filled('enquiryCommunity')) {
            $request->enquiryCommunity === 'Unknown'
                ? $query->whereHas('enquiry', fn ($q) => $q->where(fn ($e) => $e->whereNull('community')->orWhere('community', '')->orWhere('community', '0')))
                : $query->whereHas('enquiry', fn ($q) => $q->where('community', $request->enquiryCommunity));
        }

        // Lead Source Performance's "Deals Closed by Nationality" resolves the
        // nationality from the closing, falling back to the linked contact's when
        // the closing's is blank — differs from the closing-only clientNationality.
        if ($request->filled('resolvedNationality')) {
            $val = $request->resolvedNationality;
            $blank = fn ($q) => $q->whereNull('clientNationality')->orWhere('clientNationality', '');
            if ($val === 'Unknown') {
                $query->where($blank)->whereHas('enquiry', function ($e) {
                    $e->whereDoesntHave('contact')
                        ->orWhereHas('contact', fn ($c) => $c->where(fn ($x) => $x->whereNull('nationality')->orWhere('nationality', '')));
                });
            } else {
                $query->where(function ($q) use ($val, $blank) {
                    $q->where('clientNationality', $val)
                        ->orWhere(function ($q2) use ($val, $blank) {
                            $q2->where($blank)->whereHas('enquiry', fn ($e) => $e->whereHas('contact', fn ($c) => $c->where('nationality', $val)));
                        });
                });
            }
        }

        if ($request->filled('sortBy') || $request->filled('sort_by')) {
            $sortBy = $request->sortBy ?? $request->sort_by;
            $order = $request->get('order', 'desc');

            match ($sortBy) {
                'targetCloseDate' => $query->orderBy('targetCloseDate', $order),
                'closingDate' => $query->orderBy('closingDate', $order),
                'saleRentalPrice' => $query->orderBy('saleRentalPrice', $order),
                'buyerCommission' => $query->orderBy('buyerCommission', $order),
                'sellerCommission' => $query->orderBy('sellerCommission', $order),
                'totalCommission' => $query->orderBy('totalCommission', $order),
                'created_at' => $query->orderBy('created_at', $order),
                default => $query->latest(),
            };
        } else {
            $query->latest();
        }

        return $query->paginate($perPage);
    }

    // ================= list by listingId =================
    public function listByListingId(Request $request, $listingId = null)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        $query = Closing::query()->with(['property', 'buyer', 'buyer.mergedInto', 'sellerLandlordContact', 'sellerLandlordContact.mergedInto', 'buyerTenantContact', 'buyerTenantContact.mergedInto', 'offer', 'enquiry', 'assignedAgent', 'tasks', 'creator']);

        $column = strtolower((string) $request->input('type')) === 'property' ? 'listingId' : 'enquiryId';

        if ($listingId) {
            $query->where($column, $listingId);
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('status', 'LIKE', "%{$search}%")
                    ->orWhere('listingType', 'LIKE', "%{$search}%")
                    ->orWhere('sellerLandlord', 'LIKE', "%{$search}%")
                    ->orWhereHas('buyer', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('assignedAgent', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('property', function ($q) use ($search) {
                        $q->where('title', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('offer', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('listingType')) {
            $query->where('listingType', $request->listingType);
        }

        if ($request->filled('assignedAgentId')) {
            $query->where('assignedAgentId', $request->assignedAgentId);
        }

        if ($request->filled('buyerId')) {
            $query->where('buyerId', $request->buyerId);
        }

        if ($request->filled('startDate') && $request->filled('endDate')) {
            $query->whereBetween('created_at', [
                $request->startDate.' 00:00:00',
                $request->endDate.' 23:59:59',
            ]);
        } elseif ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('targetCloseDate', [$request->date_from, $request->date_to]);
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
                'targetCloseDate' => $query->orderBy('targetCloseDate', $order),
                'closingDate' => $query->orderBy('closingDate', $order),
                'saleRentalPrice' => $query->orderBy('saleRentalPrice', $order),
                'buyerCommission' => $query->orderBy('buyerCommission', $order),
                'sellerCommission' => $query->orderBy('sellerCommission', $order),
                'totalCommission' => $query->orderBy('totalCommission', $order),
                'created_at' => $query->orderBy('created_at', $order),
                default => $query->latest(),
            };
        } else {
            $query->latest();
        }

        return $query->paginate($perPage);
    }

    public function create(array $data)
    {
        $data['referenceNo'] = $this->generateReferenceNo();

        $closing = Closing::create($data);

        $property = Property::find($closing->listingId);
        if ($property) {
            $oldStatus = $property->status;
            $property->update(['status' => Constants::PROPERTY_STATUS_UNPUBLISHED]);

            logPropertyHistory([
                'propertyId' => $property->id,
                'field' => 'status',
                'originalValue' => json_encode(['message' => $oldStatus]),
                'newValue' => json_encode(['message' => Constants::PROPERTY_STATUS_UNPUBLISHED]),
            ]);

            // A closing ends the deal — pull the listing off the external portals
            // (Bayut / PF). Only when it was actually live there, so we don't fire
            // portal calls for a listing that was never published. This direct
            // status update bypasses PropertyService, so we dispatch the same
            // unpublish job it would have.
            if (in_array($oldStatus, Constants::PROPERTY_PORTAL_SYNC_STATUSES, true)) {
                SyncPropertyToPortalsJob::dispatch((int) $property->id, SyncPropertyToPortalsJob::MODE_DRAFT);
            }
        }

        $this->notifyClosingCreated($closing);

        return $closing;
    }

    public function generateReferenceNo(): string
    {
        $lastClosing = Closing::whereNotNull('referenceNo')
            ->orderBy('id', 'desc')
            ->first();

        $nextNumber = 1;
        if ($lastClosing && $lastClosing->referenceNo) {
            if (preg_match('/(\d+)$/', $lastClosing->referenceNo, $matches)) {
                $nextNumber = (int) $matches[1] + 1;
            }
        }

        // Skip past any number already taken.
        do {
            $referenceNo = 'C-'.str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
            $nextNumber++;
        } while (Closing::where('referenceNo', $referenceNo)->exists());

        return $referenceNo;
    }

    public function update(int $id, array $data)
    {
        $query = Closing::query();

        if (isAgent()) {
            $query->where('assignedAgentId', user()->id);
        }

        $closing = $query->findOrFail($id);
        $previousStatus = $closing->status;

        if (($data['status'] ?? null) === Constants::CLOSE_STATUS_CLOSED && empty($data['closingDate']) && empty($closing->closingDate)) {
            $data['closingDate'] = now()->format('Y-m-d');
        }

        $closing->update($data);

        $newStatus = $data['status'] ?? null;

        if ($newStatus && $previousStatus !== $newStatus) {
            $property = null;
            $propertyStatusLabel = null;

            // Existing side effect: when moving to Closed, mark property as Rented/Sold.
            if ($newStatus === Constants::CLOSE_STATUS_CLOSED) {
                $property = Property::find($closing->listingId);
                if ($property) {
                    $property->update(['status' => Constants::PROPERTY_STATUS_RENTED_SOLD]);
                    $propertyStatusLabel = $closing->listingType === 'rent' ? 'Rented' : 'Sold';
                }
            }

            $statusLabel = Constants::CLOSE_STATUS[$newStatus] ?? $newStatus;
            $actor = user();
            $freshClosing = $closing->fresh();

            // Congratulate the assigned agent when a closing is completed — queued, CRM only.
            if ($newStatus === Constants::CLOSE_STATUS_CLOSED && $closing->assignedAgentId) {
                NotifyAgentClosingCompletedJob::dispatch($closing->id, $actor?->id ?? $closing->assignedAgentId);
            }

            // On completion, alert the agent + admins if mandatory documents are missing — queued, CRM only.
            if ($newStatus === Constants::CLOSE_STATUS_CLOSED) {
                NotifyClosingDocumentsMissingJob::dispatch($closing->id, $actor?->id ?? $closing->assignedAgentId ?? 0);
            }

            // On completion, move the same client's other agents' open enquiries to Lost and alert them — queued.
            if ($newStatus === Constants::CLOSE_STATUS_CLOSED) {
                NotifyEnquiryLostByColleagueJob::dispatch($closing->id);
            }

            app()->terminating(function () use ($property, $propertyStatusLabel, $freshClosing, $statusLabel, $actor) {
                if ($property && $propertyStatusLabel) {
                    PropertyService::notifyAdminsManagersOnStatusChange($property, $propertyStatusLabel, $actor);
                }
                $this->notifyClosingStatusChange($freshClosing, $statusLabel, $actor);
            });
        }

        return $closing;
    }

    /**
     * Send CRM notification and email to the assigned agent when a new closing is created.
     */
    protected function notifyClosingCreated(Closing $closing): void
    {
        $actor = user();
        if (! $actor || ! $closing->assignedAgentId || $closing->assignedAgentId == $actor->id) {
            return;
        }

        $agent = User::find($closing->assignedAgentId);
        if (! $agent) {
            return;
        }

        $closing->loadMissing(['property', 'buyerTenantContact', 'sellerLandlordContact']);
        $notificationService = app(NotificationService::class);
        $mailService = app(MailService::class);

        $closingLink = env('FRONTEND_URL', config('app.url')).'/dashboard/closing/'.$closing->id.'/view';
        $closingName = $closing->name ?? "Closing #{$closing->id}";
        $closingRef = $closing->referenceNo ?? $closing->id;
        $rawPropertyTitle = $closing->property?->title;
        $propertyTitle = $rawPropertyTitle ?: 'N/A';
        $propertyRef = $rawPropertyTitle
            ? "{$rawPropertyTitle} / {$closingRef}"
            : (string) $closingRef;

        $clientNames = collect([
            $closing->buyerTenantContact?->name,
            $closing->sellerLandlordContact?->name,
        ])->filter()->implode(', ');
        $clientNames = $clientNames !== '' ? $clientNames : 'N/A';

        $dealValue = $closing->saleRentalPrice !== null
            ? number_format((float) $closing->saleRentalPrice, 2)
            : 'N/A';

        $expectedClosingDate = $closing->targetCloseDate
            ? Carbon::parse($closing->targetCloseDate)->format('F d, Y')
            : 'N/A';

        $notificationService->notifyUser(
            userId: $agent->id,
            actorId: $actor->id,
            type: Constants::NOTIFICATION_TYPES['closing_created'],
            title: "New closing created: {$closingName}",
            message: "{$actor->name} created a new closing and assigned it to you: {$closingName}",
            module: Constants::MODULE_CLOSING,
            moduleId: $closing->id,
            extraData: [
                'closingId' => $closing->id,
                'closingName' => $closingName,
            ]
        );

        if ($agent->email) {
            try {
                $mailService->sendEmail(
                    Constants::EMAIL_SLUGS['closing']['closing_created_agent'],
                    $agent->email,
                    [
                        'recipient_name' => ucwords($agent->name),
                        'closing_id' => $closingRef,
                        'property_ref' => $propertyRef,
                        'property_title' => $propertyTitle,
                        'client_names' => $clientNames,
                        'deal_value' => $dealValue,
                        'expected_closing_date' => $expectedClosingDate,
                        'closing_link' => $closingLink,
                        'company_name' => getCompanyName(),
                    ]
                );
            } catch (\Throwable $e) {
                Log::error("Failed to send closing created email to agent {$agent->email}: ".$e->getMessage());
            }
        }
    }

    /**
     * Notify admins, managers and the assigned agent when a closing status changes.
     */
    protected function notifyClosingStatusChange(Closing $closing, string $statusLabel, ?User $actor = null): void
    {
        $actor = $actor ?? user();
        if (! $actor) {
            return;
        }

        $closing->loadMissing('property');
        $notificationService = app(NotificationService::class);
        $mailService = app(MailService::class);

        $adminRoleIds = Role::where('name', Constants::SUPER_ADMIN)->pluck('id');

        $recipientIds = User::whereIn('roleId', $adminRoleIds)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        if ($closing->assignedAgentId) {
            $recipientIds[] = $closing->assignedAgentId;
        }

        $recipientIds = array_values(array_unique(array_filter($recipientIds, fn ($id) => $id && $id != $actor->id)));

        if (empty($recipientIds)) {
            return;
        }

        $recipients = User::whereIn('id', $recipientIds)
            ->where('status', 'active')
            ->get(['id', 'name', 'email']);

        $closingLink = env('FRONTEND_URL', config('app.url')).'/dashboard/closing/'.$closing->id.'/view';
        $closingName = $closing->name ?? "Closing #{$closing->id}";
        $propertyTitle = $closing->property?->title ?? 'N/A';

        foreach ($recipients as $recipient) {
            $notificationService->notifyUser(
                userId: $recipient->id,
                actorId: $actor->id,
                type: Constants::NOTIFICATION_TYPES['closing_status_change'],
                title: "Closing status changed: {$closingName}",
                message: "{$actor->name} changed the status of closing \"{$closingName}\" to \"{$statusLabel}\".",
                module: Constants::MODULE_CLOSING,
                moduleId: $closing->id,
                extraData: [
                    'closingId' => $closing->id,
                    'closingName' => $closingName,
                    'statusLabel' => $statusLabel,
                ]
            );

            if ($recipient->email) {
                try {
                    $mailService->sendEmail(
                        Constants::EMAIL_SLUGS['closing']['closing_status_change'],
                        $recipient->email,
                        [
                            'recipient_name' => ucwords($recipient->name),
                            'closing_name' => $closingName,
                            'closing_id' => $closing->referenceNo ?? $closing->id,
                            'property_title' => $propertyTitle,
                            'status' => $statusLabel,
                            'changed_by' => ucwords($actor->name),
                            'changed_date' => now()->setTimezone(appTimezone())->format('F d, Y H:i'),
                            'closing_link' => $closingLink,
                            'company_name' => getCompanyName(),
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::error("Failed to send closing status change email to {$recipient->email}: ".$e->getMessage());
                }
            }
        }
    }

    public function delete(int $id): bool
    {
        $query = Closing::query();

        if (isAgent()) {
            $query->where('assignedAgentId', user()->id);
        }

        $closing = $query->find($id);

        if (! $closing) {
            return false;
        }

        $closing->delete();

        return true;
    }

    public function getById(int $id)
    {
        $query = Closing::with(['property', 'buyer', 'buyer.mergedInto', 'sellerLandlordContact', 'sellerLandlordContact.mergedInto', 'buyerTenantContact', 'buyerTenantContact.mergedInto', 'offer', 'enquiry', 'assignedAgent', 'tasks', 'creator']);

        if (isAgent()) {
            $query->where('assignedAgentId', user()->id);
        }

        return $query->find($id);
    }

    /**
     * Sales-pipeline summary: per-agent revenue (saleRentalPrice) and commission
     * (totalCommission), split into Off Plan / Secondary / Rental, plus company totals.
     *
     * Categories:
     *   - rental    : listingType = rent
     *   - offPlan   : sale of an off-plan property (completionStatus offPlan/offPlanPrimary)
     *   - secondary : any other sale (completed / resale market, or unmapped)
     *
     * Counts open + closed closings (cancelled excluded), matching the dashboard's
     * pipeline convention. Optional startDate/endDate filter on created_at. Agents see
     * only their own row.
     */
    public function pipelineSummary(Request $request): array
    {
        $startDate = $request->filled('startDate') ? $request->startDate : null;
        $endDate = $request->filled('endDate') ? $request->endDate : null;

        $rows = Closing::query()
            ->leftJoin('properties', 'properties.id', '=', 'closings.listingId')
            ->whereIn('closings.status', [Constants::CLOSE_STATUS_OPEN, Constants::CLOSE_STATUS_CLOSED])
            ->whereNotNull('closings.assignedAgentId')
            ->when(isAgent(), fn ($q) => $q->where('closings.assignedAgentId', user()->id))
            ->when($startDate, fn ($q) => $q->where('closings.created_at', '>=', localDayStartUtc($startDate)))
            ->when($endDate, fn ($q) => $q->where('closings.created_at', '<=', localDayEndUtc($endDate)))
            ->groupBy('closings.assignedAgentId', 'closings.listingType', 'properties.completionStatus')
            ->selectRaw('closings.assignedAgentId as agentId')
            ->selectRaw('closings.listingType as listingType')
            ->selectRaw('properties.completionStatus as completionStatus')
            ->selectRaw('COALESCE(SUM(closings.saleRentalPrice), 0) as revenue')
            ->selectRaw('COALESCE(SUM(closings.totalCommission), 0) as commission')
            ->get();

        $offPlanStatuses = ['offPlan', 'offPlanPrimary'];

        $agents = [];
        $totals = $this->emptyPipelineBucket();

        foreach ($rows as $row) {
            $category = match (true) {
                $row->listingType === Constants::CLOSE_LISTING_TYPE_RENT => 'rental',
                in_array($row->completionStatus, $offPlanStatuses, true) => 'offPlan',
                default => 'secondary',
            };

            $agentId = (int) $row->agentId;
            $agents[$agentId] ??= $this->emptyPipelineBucket();

            $revenue = (float) $row->revenue;
            $commission = (float) $row->commission;

            $agents[$agentId]['revenue'][$category] += $revenue;
            $agents[$agentId]['revenue']['total'] += $revenue;
            $agents[$agentId]['commission'][$category] += $commission;
            $agents[$agentId]['commission']['total'] += $commission;

            $totals['revenue'][$category] += $revenue;
            $totals['revenue']['total'] += $revenue;
            $totals['commission'][$category] += $commission;
            $totals['commission']['total'] += $commission;
        }

        $agentNames = User::whereIn('id', array_keys($agents))->pluck('name', 'id');

        $agentRows = collect($agents)
            ->map(fn ($bucket, $agentId) => [
                'agentId' => (int) $agentId,
                'agentName' => $agentNames[$agentId] ?? 'Unassigned',
                'revenue' => $bucket['revenue'],
                'commission' => $bucket['commission'],
            ])
            ->sortBy('agentName', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [
            'agents' => $agentRows,
            'totals' => $totals,
        ];
    }

    private function emptyPipelineBucket(): array
    {
        return [
            'revenue' => ['offPlan' => 0.0, 'secondary' => 0.0, 'rental' => 0.0, 'total' => 0.0],
            'commission' => ['offPlan' => 0.0, 'secondary' => 0.0, 'rental' => 0.0, 'total' => 0.0],
        ];
    }
}
