<?php

namespace App\Services;

use App\Constants\Constants;
use App\Exceptions\UserFacingException;
use App\Jobs\SendWhatsAppTemplateJob;
use App\Models\Enquiry;
use App\Models\User;
use App\Repositories\EnquiryRepository;
use Illuminate\Http\Request;

class EnquiryService
{
    public function __construct(
        protected EnquiryRepository $enquiryRepository
    ) {}

    /**
     * Get all enquiries with filters
     */
    public function getAllEnquiries(Request $request)
    {
        return $this->enquiryRepository->getAll($request);
    }

    public function getAllMatchingEnquiries(Request $request)
    {
        return $this->enquiryRepository->getAllMatchingEnquiries($request);
    }

    /**
     * Create a new enquiry
     */
    public function createEnquiry(array $data, bool $sendNewLeadNotification = false)
    {
        // Duplicate detection: if an enquiry from the same contact on the same
        // property already exists, do NOT create another one. Alert the agent
        // who owns the original and return it, tagged so callers can skip their
        // own "new enquiry" side effects (emails, history, Salesforce sync).
        if (! empty($data['contactId']) && ! empty($data['listingId'])) {
            $existingEnquiry = Enquiry::where('contactId', $data['contactId'])
                ->where('listingId', $data['listingId'])
                ->orderBy('id')
                ->first();

            if ($existingEnquiry) {
                $this->notifyDuplicateEnquiry($existingEnquiry);

                // Returned as-is (not freshly inserted), so $enquiry->wasRecentlyCreated
                // is false — callers use that to skip their "new enquiry" side effects.
                return $existingEnquiry;
            }
        }

        $data['referenceNo'] = $this->generateReferenceNo();

        $data = normalizePhoneFields($data, ['phone']);

        $enquiry = $this->enquiryRepository->create($data);

        // Build a descriptive name for the enquiry
        $enquiryNameOrType = $enquiry->contact?->masterOrSelf()->name ?? $enquiry->enquiryType ?? "Enquiry #{$enquiry->id}";
        $notificationService = app(NotificationService::class);
        $actorId = user()->id;

        // Notify the agent if assigned and different from the creator
        if (isset($data['agentId']) && $data['agentId'] && $data['agentId'] != $actorId) {
            $notificationService->notifyUser(
                userId: $data['agentId'],
                actorId: $actorId,
                type: Constants::NOTIFICATION_TYPES['new_enquiry'],
                title: "New enquiry assigned to you: {$enquiryNameOrType}",
                message: user()->name." created a new enquiry and assigned it to you: {$enquiryNameOrType}",
                module: Constants::MODULE_ENQUIRY,
                moduleId: $enquiry->id,
                extraData: [
                    'moduleTitle' => $enquiryNameOrType,
                    'moduleId' => $enquiry->id,
                ]
            );
        }

        // Always send email to the assigned agent (even if creator is the agent)
        if (isset($data['agentId']) && $data['agentId']) {
            $agent = User::find($data['agentId']);
            if ($agent && $agent->email) {
                try {
                    $mailService = app(MailService::class);
                    $enquiryLink = env('FRONTEND_URL', config('app.url')).'/dashboard/enquiries/'.$enquiry->id.'/view';

                    $mailService->sendEmail(
                        Constants::EMAIL_SLUGS['enquiry']['new_enquiry_agent'],
                        $agent->email,
                        [
                            'agentName' => ucwords($agent->name),
                            'enquiryName' => $enquiryNameOrType,
                            'enquiryId' => $enquiry->referenceNo ?? $enquiry->id,
                            'enquiryType' => $enquiry->enquiryType ?? 'N/A',
                            'contactName' => $enquiry->contact?->masterOrSelf()->name ?? 'N/A',
                            'assignedBy' => ucwords(user()->name),
                            'assignedDate' => now()->setTimezone(appTimezone())->format('F d, Y H:i'),
                            'enquiryLink' => $enquiryLink,
                            'companyName' => getCompanyName(),
                        ]
                    );
                } catch (\Throwable $e) {
                    \Log::error("Failed to send new enquiry email to agent {$agent->email}: ".$e->getMessage());
                }
            }

            // New-lead WhatsApp to the assigned agent — only for MANUAL CRM entries
            // (caller passes $sendNewLeadNotification = true). Automatic/portal leads
            // (ExternalController) are intentionally skipped: ComBots already notifies
            // those, so firing here would duplicate. Reassignment is handled separately
            // in updateEnquiry().
            if ($sendNewLeadNotification) {
                $this->sendKeyflowNewLeadFallback($enquiry, (int) $data['agentId']);
            }
        }

        // Notify the contact if they exist and are a user
        if (isset($data['contactId']) && $data['contactId'] && $data['contactId'] != $actorId) {
            $contact = $enquiry->contact;
            if ($contact && method_exists($contact, 'user') && $contact->user) {
                $notificationService->notifyUser(
                    userId: $contact->user->id,
                    actorId: $actorId,
                    type: Constants::NOTIFICATION_TYPES['new_enquiry'],
                    title: "New enquiry created for you: {$enquiryNameOrType}",
                    message: user()->name." created a new enquiry for you: {$enquiryNameOrType}",
                    module: Constants::MODULE_ENQUIRY,
                    moduleId: $enquiry->id,
                    extraData: [
                        'moduleTitle' => $enquiryNameOrType,
                        'moduleId' => $enquiry->id,
                    ]
                );
            }
        }

        return $enquiry;
    }

    /**
     * Alert the agent who owns the original enquiry that the same contact tried
     * to raise another enquiry on the same property (a detected duplicate). The
     * duplicate is never persisted — this notification is the only outcome.
     */
    private function notifyDuplicateEnquiry(Enquiry $existingEnquiry): void
    {
        if (empty($existingEnquiry->agentId)) {
            return;
        }

        try {
            $contactName = $existingEnquiry->contact?->masterOrSelf()->name ?? 'A contact';
            $property = $existingEnquiry->listing;
            $propertyName = $property?->referenceNumber ?? $property?->title ?? "property #{$existingEnquiry->listingId}";

            app(NotificationService::class)->notifyUser(
                userId: $existingEnquiry->agentId,
                actorId: user()->id,
                type: Constants::NOTIFICATION_TYPES['duplicate_enquiry'],
                title: "Duplicate enquiry on {$propertyName}",
                message: "{$contactName} raised another enquiry on {$propertyName}, which already has your enquiry {$existingEnquiry->referenceNo}.",
                module: Constants::MODULE_ENQUIRY,
                moduleId: $existingEnquiry->id,
                extraData: [
                    'moduleTitle' => $contactName,
                    'moduleId' => $existingEnquiry->id,
                ]
            );
        } catch (\Throwable $e) {
            \Log::error('EnquiryService : notifyDuplicateEnquiry : '.$e->getMessage());
        }
    }

    /**
     * Send the "KeyFlow Notification – New Lead" WhatsApp to the assigned agent.
     *
     * This is a FALLBACK for ComBots (the primary new-lead notifier) and is OFF
     * during normal operation — it only fires when
     * `services.whatsapp_360.keyflow_new_lead_fallback_enabled` is turned on. Use
     * it solely for a genuine new assignment (new/manual enquiry creation, or a
     * reassignment to a different agent) — never on plain status/other updates —
     * so we don't reintroduce duplicate messages during internal workflow changes.
     */
    private function sendKeyflowNewLeadFallback(Enquiry $enquiry, ?int $agentId): void
    {
        if (! config('services.whatsapp_360.keyflow_new_lead_fallback_enabled') || ! $agentId) {
            return;
        }

        // Use the approved "new_lead" template (enquiry_response_to_agent). The
        // dedicated keyflow_new_lead template is not approved on the WhatsApp
        // account, so it never delivers — this one is live and expects 5 params.
        $template = config('services.whatsapp_360.templates.new_lead');
        if (! $template) {
            return;
        }

        $agent = User::find($agentId);
        if (! $agent || ! $agent->phone) {
            return;
        }

        try {
            $enquiryLink = env('FRONTEND_URL', config('app.url')).'/dashboard/enquiries/'.$enquiry->id.'/view';

            // Param order MUST match the approved enquiry_response_to_agent template.
            $components = [
                WhatsAppService::textParams([
                    $enquiry->referenceNo ?? (string) $enquiry->id, // {{1}} enquiry reference
                    $enquiry->contact?->masterOrSelf()->name ?? 'N/A',              // {{2}} client name
                    $enquiry->contact?->phone ?? 'N/A',             // {{3}} client phone
                    $enquiry->enquiryType ?? 'N/A',                 // {{4}} enquiry type
                    $enquiryLink,                                   // {{5}} enquiry link
                ]),
            ];

            SendWhatsAppTemplateJob::dispatch($agent->phone, $template, $components);
        } catch (\Throwable $e) {
            \Log::error('KeyFlow New Lead fallback WhatsApp failed for enquiry '.$enquiry->id.': '.$e->getMessage());
        }
    }

    /**
     * Generate the next enquiry reference number (e.g. "R-00042"), guaranteed
     * not to collide with any existing reference — including soft-deleted rows.
     */
    /**
     * Human-readable summary of what changed on an enquiry update, for use in the
     * notification message (e.g. "Status: New Lead → Property Search, Agent → Sara").
     * Compares the enquiry's current (pre-update) values against the incoming data;
     * caps the list so the message stays short.
     *
     * @param  array<string, mixed>  $data
     */
    protected function describeEnquiryChanges(Enquiry $enquiry, array $data): string
    {
        $fieldLabels = [
            'status' => 'Status',
            'agentId' => 'Agent',
            'leadSource' => 'Lead Source',
            'technicalSource' => 'Technical Source',
            'enquiryType' => 'Enquiry Type',
            'listingType' => 'Listing Type',
            'enquiryPropertyType' => 'Property Type',
            'budget' => 'Budget',
            'priceMin' => 'Min Price',
            'priceMax' => 'Max Price',
            'community' => 'Community',
            'phone' => 'Phone',
            'referredBy' => 'Referred By',
            'comments' => 'Comments',
        ];

        $changes = [];
        foreach ($fieldLabels as $field => $label) {
            if (! array_key_exists($field, $data) || ! array_key_exists($field, $enquiry->getAttributes())) {
                continue;
            }

            $old = $enquiry->$field;
            $new = $data[$field];
            if ((string) $old === (string) (is_array($new) ? json_encode($new) : $new)) {
                continue;
            }

            if ($field === 'status') {
                $changes[] = 'Status: '.$this->enquiryValueLabel('status', $old).' → '.$this->enquiryValueLabel('status', $new);
            } elseif ($field === 'agentId') {
                $changes[] = 'Agent → '.(User::whereKey($new)->value('name') ?? 'Unassigned');
            } elseif ($field === 'comments') {
                $changes[] = 'Comments updated';
            } else {
                $changes[] = $label.': '.$this->enquiryValueLabel($field, $old).' → '.$this->enquiryValueLabel($field, $new);
            }
        }

        if (empty($changes)) {
            return '';
        }

        // Keep the message readable — show the first few, summarise the rest.
        if (count($changes) > 3) {
            $extra = count($changes) - 3;
            $changes = array_slice($changes, 0, 3);
            $changes[] = "and {$extra} more";
        }

        return implode(', ', $changes);
    }

    /**
     * Display value for an enquiry field: maps coded fields (status, type, source)
     * to their labels, blanks to "—", everything else as-is.
     */
    private function enquiryValueLabel(string $field, $value): string
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            return '—';
        }

        $map = match ($field) {
            'status' => Constants::ENQUIRY_ALL_STATUS,
            'leadSource' => Constants::LEAD_SOURCE_VARIANT_LABELS,
            'technicalSource' => Constants::TECHNICAL_SOURCE,
            'enquiryType' => Constants::ENQUIRY_TYPE,
            default => [],
        };

        return $map[$value] ?? (is_array($value) ? 'updated' : (string) $value);
    }

    public function generateReferenceNo(): string
    {
        $lastEnquiry = Enquiry::withTrashed()
            ->whereNotNull('referenceNo')
            ->orderBy('id', 'desc')
            ->first();

        $nextNumber = $lastEnquiry ? (int) last(explode('-', $lastEnquiry->referenceNo)) + 1 : 1;

        // Skip past any number already taken (incl. soft-deleted enquiries).
        do {
            $referenceNo = 'R-'.str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
            $nextNumber++;
        } while (Enquiry::withTrashed()->where('referenceNo', $referenceNo)->exists());

        return $referenceNo;
    }

    /**
     * Update an existing enquiry
     */
    public function updateEnquiry(int $id, array $data)
    {
        $enquiry = $this->enquiryRepository->findById($id);

        if (! $enquiry) {
            throw new UserFacingException('Enquiry not found');
        }

        // Snapshot the current agent so we only notify on a genuine reassignment
        // to a DIFFERENT agent — never on plain status or other field updates.
        $oldAgentId = (int) $enquiry->agentId;

        // Auto-transition: when isQualified is set to true, move Contacting/newLead → propertySearch
        $isQualified = array_key_exists('isQualified', $data)
            ? filter_var($data['isQualified'], FILTER_VALIDATE_BOOLEAN)
            : (bool) $enquiry->isQualified;

        if ($isQualified && in_array($enquiry->status, [Constants::ENQUIRY_NEW_LEAD, Constants::ENQUIRY_CONTACTING], true)) {
            $data['status'] = Constants::ENQUIRY_PROPERTY_SEARCH;
        }

        // When isActive is set to false, move to lost status
        if (array_key_exists('isActive', $data) && ! filter_var($data['isActive'], FILTER_VALIDATE_BOOLEAN) && $enquiry->isActive) {
            $data['status'] = Constants::ENQUIRY_LOST;
        }

        $targetStatus = $data['status'] ?? $enquiry->status;
        if (! $isQualified && ! in_array($targetStatus, [
            Constants::ENQUIRY_NEW_LEAD,
            Constants::ENQUIRY_CONTACTING,
            Constants::ENQUIRY_LOST,
        ], true)) {
            throw new UserFacingException('Lead must be qualified before moving to this status. Use the "Qualified" checkbox.');
        }

        // Lead-score override (wireframe pg.26). leadRating is the effective score
        // and is kept in sync with status automatically by the Enquiry model. Here
        // we only handle the two agent-driven cases:
        //   - Manual override: an explicit leadRating locks the score (highest
        //     priority). An empty value clears the lock, back to automatic.
        //   - Status explicitly changed by the agent (no manual score in the same
        //     request): release any existing lock so the score follows the new
        //     status. A manual score in the same request always wins.
        $statusChanging = array_key_exists('status', $data) && $data['status'] !== $enquiry->status;
        if (array_key_exists('leadRating', $data)) {
            $rating = is_string($data['leadRating']) ? strtolower(trim($data['leadRating'])) : $data['leadRating'];
            if ($rating === '' || $rating === null) {
                $data['leadRating'] = null;
                $data['leadScoreLocked'] = false;
            } else {
                $data['leadRating'] = $rating;
                $data['leadScoreLocked'] = true;
            }
        } elseif ($statusChanging) {
            $data['leadScoreLocked'] = false;
        }

        $data = normalizePhoneFields($data, ['phone']);

        $this->enquiryRepository->createHistory($enquiry, $data);

        // Summarise what actually changed, BEFORE the update mutates $enquiry, so
        // the notification says e.g. "Status: New Lead → Property Search" instead
        // of just "updated the enquiry".
        $changeSummary = $this->describeEnquiryChanges($enquiry, $data);

        $updatedEnquiry = $this->enquiryRepository->update($enquiry, $data);

        $enquiryNameOrType = $updatedEnquiry->contact?->masterOrSelf()->name ?? $updatedEnquiry->enquiryType;
        $notificationService = app(NotificationService::class);
        $actorId = user()->id;

        // notifyModule already reaches the owner (createdBy) AND the followers,
        // minus the actor — so there is no separate createdBy notification here.
        notifyModule(Constants::MODULE_ENQUIRY, $updatedEnquiry->id, Constants::NOTIFICATION_TYPES['enquiry_update'], 'updated', $enquiryNameOrType, null, $changeSummary ?: null);

        if (isset($data['agentId']) && $data['agentId'] && $data['agentId'] != $actorId) {
            $notificationService->notifyUser(
                userId: $data['agentId'],
                actorId: $actorId,
                type: Constants::NOTIFICATION_TYPES['enquiry_update'],
                title: "Enquiry assigned to you: {$enquiryNameOrType}",
                message: user()->name." assigned you to enquiry {$enquiryNameOrType}",
                module: Constants::MODULE_ENQUIRY,
                moduleId: $updatedEnquiry->id,
                extraData: [
                    'moduleTitle' => $enquiryNameOrType,
                    'moduleId' => $updatedEnquiry->id,
                ]
            );
        }

        // Reassignment to a different agent — fire the KeyFlow New Lead fallback
        // for the newly-assigned agent only (gated by config). Guarding on an
        // actual agent change keeps status/other updates from sending anything.
        if (isset($data['agentId']) && $data['agentId'] && (int) $data['agentId'] !== $oldAgentId) {
            $this->sendKeyflowNewLeadFallback($updatedEnquiry, (int) $data['agentId']);
        }

        if ($updatedEnquiry->contactId && $updatedEnquiry->contactId != $actorId) {
            $contact = $updatedEnquiry->contact;
            if ($contact && method_exists($contact, 'user') && $contact->user) {
                $notificationService->notifyUser(
                    userId: $contact->user->id,
                    actorId: $actorId,
                    type: Constants::NOTIFICATION_TYPES['enquiry_update'],
                    title: "Your enquiry updated: {$enquiryNameOrType}",
                    message: user()->name.' updated your enquiry '.$enquiryNameOrType.($changeSummary ? " — {$changeSummary}" : ''),
                    module: Constants::MODULE_ENQUIRY,
                    moduleId: $updatedEnquiry->id,
                    extraData: [
                        'moduleTitle' => $enquiryNameOrType,
                        'moduleId' => $updatedEnquiry->id,
                    ]
                );
            }
        }

        return $updatedEnquiry;
    }

    /**
     * Delete a single enquiry
     */
    public function deleteEnquiry(int $id)
    {
        $enquiry = $this->enquiryRepository->findById($id);

        if (! $enquiry) {
            throw new UserFacingException('Enquiry not found');
        }

        return $this->enquiryRepository->delete($enquiry);
    }

    /**
     * Delete multiple enquiries
     */
    public function deleteEnquiries(array $ids): int
    {
        $deletedCount = 0;

        foreach ($ids as $id) {
            try {
                $this->deleteEnquiry($id);
                $deletedCount++;
            } catch (\Exception $e) {
                continue;
            }
        }

        return $deletedCount;
    }

    public function takeOwnership(int $enquiryId, int $agentId)
    {
        $enquiry = Enquiry::find($enquiryId);

        if (! $enquiry) {
            throw new UserFacingException('The requested enquiry was not found.');
        }

        if (! $enquiry->isOpenGame) {
            throw new UserFacingException('This enquiry is not available for ownership transfer.');
        }

        $oldAgentId = $enquiry->agentId;

        $this->enquiryRepository->createHistory($enquiry, ['agentId' => $agentId, 'isOpenGame' => false]);

        $updatedEnquiry = $this->enquiryRepository->update($enquiry, [
            'agentId' => $agentId,
            'isOpenGame' => 0,
        ]);

        $enquiryNameOrType = $updatedEnquiry->contact?->masterOrSelf()->name ?? $updatedEnquiry->enquiryType;
        $agentName = User::find($agentId)?->name ?? 'Agent';
        $notificationService = app(NotificationService::class);
        $actorId = user()->id;

        notifyModule(Constants::MODULE_ENQUIRY, $updatedEnquiry->id, Constants::NOTIFICATION_TYPES['enquiry_update'], 'ownership_taken', $enquiryNameOrType);

        if ($oldAgentId && $oldAgentId != $actorId) {
            $notificationService->notifyUser(
                userId: $oldAgentId,
                actorId: $actorId,
                type: Constants::NOTIFICATION_TYPES['enquiry_update'],
                title: "Enquiry ownership transferred: {$enquiryNameOrType}",
                message: $agentName." took ownership of enquiry {$enquiryNameOrType}",
                module: Constants::MODULE_ENQUIRY,
                moduleId: $updatedEnquiry->id,
                extraData: [
                    'moduleTitle' => $enquiryNameOrType,
                    'moduleId' => $updatedEnquiry->id,
                ]
            );
        }

        return $updatedEnquiry;
    }

    public function getEnquiriesByAgent($agentId, Request $request)
    {
        return $this->enquiryRepository->getByAssignedUser($agentId, $request);
    }
}
