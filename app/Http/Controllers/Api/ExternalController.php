<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Jobs\SyncEnquiryToSalesforceJob;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\PortalWebhook;
use App\Models\Property;
use App\Models\User;
use App\Services\EnquiryService;
use App\Services\PropertyFinderService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ExternalController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected EnquiryService $enquiryService,
        protected PropertyFinderService $propertyFinderService
    ) {}

    public function getProperty(string $identifier)
    {
        try {
            $property = Property::select([
                'id',
                'title',
                'referenceNumber',
                'category',
                'listingType',
                'sizeArea',
                'plotSize',
                'bedrooms',
                'bathrooms',
                'price',
                'agentId',
            ])
                ->with([
                    'agent:id,name,phone',
                    'mediaThumbnails' => fn ($q) => $q->select('id', 'propertyId', 'compressedPath', 'originalPath', 'filePath', 'ordering')->limit(1),
                ])
                ->where('id', $identifier)
                ->orWhere('referenceNumber', $identifier)
                ->first();

            if (! $property) {
                return $this->error('Property not found.', 404);
            }

            $primaryMedia = $property->mediaThumbnails->first();
            $property->primaryImage = $primaryMedia
                ? getImageUrl($primaryMedia->compressedPath ?? $primaryMedia->originalPath ?? $primaryMedia->filePath)
                : null;
            $property->propertyDetailUrl = $property->referenceNumber
                ? 'https://exclusive-links.vercel.app/listing/'.$property->referenceNumber
                : null;

            unset($property->mediaThumbnails);

            return $this->success($property, 'Property retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ExternalController : getProperty', $e);
        }
    }

    public function createEnquiry(Request $request)
    {
        // Workflow/automation tools (n8n, Make, etc.) sometimes POST a JSON body
        // without a `Content-Type: application/json` header, so Laravel never
        // parses it and $request->all() comes back empty — making the only
        // required field (contactPhone) wrongly report as missing. Fall back to
        // decoding the raw body so a valid JSON payload still goes through.
        $input = $request->all();
        if (empty($input)) {
            $decoded = json_decode($request->getContent(), true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }

        $validator = Validator::make($input, [
            'propertyReferenceNo' => 'nullable|string',
            'contactPhone' => 'required|string',
            'contactName' => 'nullable|string',
            'agentId' => 'nullable|exists:users,id',
            'agentPhone' => 'nullable|string',
            'agentEmail' => 'nullable|email',
            'leadSource' => 'nullable|string',
            // PF's own lead origin (listing | project | developer | agent | company |
            // seller). Optional: when the caller doesn't send it we read the origin off
            // the technicalSource sub-channel instead.
            'leadType' => 'nullable|string',
            'technicalSource' => 'nullable|string',
            'contactNote' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $validated = $validator->validated();

            // Callers send the client's number in whatever shape their integration holds
            // it ("00971…", "+971…", "0501234567"). Normalise up front so the lookup
            // below compares numbers rather than spellings, and so a contact created
            // here is stored in the same E.164 shape as the rest of the CRM.
            $validated['contactPhone'] = normalizePhoneE164($validated['contactPhone'])
                ?: $validated['contactPhone'];

            // Resolve the agent by phone/email when no explicit agentId was sent.
            // agentId always wins; phone takes precedence over email.
            if (empty($validated['agentId'])) {
                // Normalise the incoming phone to its last 9 digits (drops country
                // code / leading zero variations) and match the same suffix on the
                // stored phone so "971505583904", "0505583904" and "505583904" all hit.
                $agentPhoneSuffix = ! empty($validated['agentPhone'])
                    ? substr(preg_replace('/\D/', '', $validated['agentPhone']), -9)
                    : null;

                $agent = ! empty($agentPhoneSuffix)
                    ? User::where('status', Constants::USER_STATUS_ACTIVE)
                        ->whereRaw("RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 9) = ?", [$agentPhoneSuffix])->first()
                    : null;

                $agent = $agent ?? (! empty($validated['agentEmail'])
                    ? User::where('status', Constants::USER_STATUS_ACTIVE)
                        ->where('email', $validated['agentEmail'])->first()
                    : null);

                $validated['agentId'] = $agent?->id;
            }

            // Callers have sent the reference with their own UI label glued to the front
            // and stray inner whitespace ("Listing  KTRBB-S-63724"), which matched nothing
            // and threw the lead away. Strip a leading listing/property label and collapse
            // the gaps so the lookup sees the bare reference. The label must be followed by
            // whitespace, so a real reference is never eaten.
            if (! empty($validated['propertyReferenceNo'])) {
                $validated['propertyReferenceNo'] = trim(preg_replace(
                    ['/^\s*(?:listing|property)\s*[:\-]?\s+/i', '/\s+/'],
                    ['', ' '],
                    $validated['propertyReferenceNo']
                ));
            }

            $property = ! empty($validated['propertyReferenceNo'])
                ? Property::where('referenceNumber', $validated['propertyReferenceNo'])->first()
                : null;

            // propertyReferenceNo is nullable: an absent reference is allowed and simply
            // creates an enquiry with no linked property. A reference we don't recognise
            // now does the same instead of 404ing the whole call: the client and their
            // enquiry are real whether or not we can resolve the listing they came in on,
            // and rejecting the request discarded the lead outright with nothing kept. The
            // unknown reference is logged so a broken integration is still visible.
            if (! empty($validated['propertyReferenceNo']) && ! $property) {
                Log::channel('enquiry')->warning('External enquiry kept without a listing — unknown property reference.', [
                    'propertyReferenceNo' => $validated['propertyReferenceNo'],
                    'contactPhone' => $validated['contactPhone'],
                    'leadSource' => $validated['leadSource'] ?? null,
                    'technicalSource' => $validated['technicalSource'] ?? null,
                ]);

                // Nothing downstream (Salesforce included) should carry a reference the
                // CRM could not resolve — treat it as the absent reference it behaves like.
                $validated['propertyReferenceNo'] = null;
            }

            // Normalize incoming source values (e.g. "Property Finder", "WhatsApp")
            // to their canonical camelCase keys so they match the Constants maps.
            $leadSource = Constants::leadSourceKey($validated['leadSource'] ?? null);
            $technicalSource = Constants::normalizeSourceKey(Constants::TECHNICAL_SOURCE, $validated['technicalSource'] ?? null);

            // Property Finder leads are classified from PF's own lead origin, not from
            // the source the caller typed: only a PROJECT lead is Primary Plus. The
            // WhatsApp automation stamps every PF lead it forwards as Primary Plus and
            // sends the real origin as the technicalSource sub-channel ("WhatsApp -
            // project" / "- agent" / "- Listing"), which put secondary agents' profile
            // and listing leads under the off-plan product. Scoped to the PF family so
            // Bayut and website callers keep the source they send.
            $leadType = $this->propertyFinderService->isPropertyFinderSource($leadSource)
                ? $this->propertyFinderService->resolveLeadType($validated['leadType'] ?? null, $validated['technicalSource'] ?? null)
                : null;

            if ($leadType) {
                $leadSource = $this->propertyFinderService->leadSourceForLeadType($leadType);
            }

            // A project or seller lead states its own type; anything else takes the
            // type from the listing it came in on.
            $contactType = $this->propertyFinderService->enquiryTypeForLeadType($leadType)
                ?? ($property?->listingType === 'rent'
                    ? Constants::CONTACT_TYPE_TENANT
                    : Constants::CONTACT_TYPE_BUYER);

            // Match on the last 9 digits, so however the caller spelled the number it
            // finds the existing record. An exact-only lookup misses it and silently
            // creates a duplicate contact — which then defeats the contact + listing
            // de-dup in createEnquiry, because the new enquiry hangs off a contact id
            // that has no history.
            $contact = Contact::resolveMasterByPhone($validated['contactPhone']);

            if (! $contact) {
                $contact = Contact::create([
                    'name' => $validated['contactName'] ?? 'Lead - '.$validated['contactPhone'],
                    'phone' => $validated['contactPhone'],
                    'contactType' => [$contactType],
                    'contactOwner' => $validated['agentId'] ?? user()->id,
                    'source' => Constants::LEAD_SOURCE_WHATSAPP,
                    'createdBy' => user()->id,
                ]);
            } else {
                // Merge — keep any existing roles this contact already has.
                $contact->addContactTypes($contactType);
            }

            $data = $this->buildPropertyEnquiryData(
                $property,
                $contact,
                $validated['agentId'] ?? null,
                $leadSource,
                $technicalSource,
                null,
                $leadType,
            );

            $enquiry = $this->enquiryService->createEnquiry($data);

            // Duplicate (same contact + property): EnquiryService notified the
            // original agent and returned the existing enquiry without creating a
            // new one. Skip the "new enquiry" side effects below.
            if (! $enquiry->wasRecentlyCreated) {
                return $this->success([
                    'enquiryId' => $enquiry->id,
                    'referenceNo' => $enquiry->referenceNo,
                ], 'The assigned agent has been notified about the enquiry request.');
            }

            logUserActivity([
                'action' => 'created',
                'module' => Constants::MODULE_ENQUIRY,
                'moduleId' => $enquiry->id,
                'description' => "Created enquiry via external API: #{$enquiry->id}",
            ]);

            logEnquiryHistory([
                'enquiryId' => $enquiry->id,
                'field' => 'created',
                'originalValue' => null,
                'newValue' => json_encode(['message' => "Enquiry created via external API: #{$enquiry->id}"]),
            ]);

            if (! empty($validated['contactNote'])) {
                ContactNote::create([
                    'contactId' => $contact->id,
                    'content' => $validated['contactNote'],
                    'type' => 'note',
                    'createdBy' => user()->id,
                ]);
            }

            // Salesforce sync is non-critical and slow; push it to the queue so
            // it never blocks or fails the enquiry creation response.
            //
            // Forward the owner the enquiry actually has, not the one the caller sent:
            // that is the only value that includes the house-agent fallback, so the two
            // systems can't disagree about who a lead belongs to.
            $validated['agentId'] = $enquiry->agentId;

            // propertyReferenceNo is nullable now; don't forward an empty
            // reference to Salesforce (no blank Reference_Number__c, and the
            // property-meta enrichment in createRequest is skipped).
            if (empty($validated['propertyReferenceNo'])) {
                unset($validated['propertyReferenceNo']);
            }

            $validated['referenceNo'] = $enquiry->referenceNo ?? '';

            // Forward what we actually stored, not what the caller sent: a PF lead
            // re-classified from its origin must reach Salesforce under the same source
            // and type it has in the CRM, or the two disagree on every project lead.
            $validated['leadSource'] = $enquiry->leadSource;
            $validated['enquiryType'] = $enquiry->enquiryType;

            SyncEnquiryToSalesforceJob::dispatch($enquiry->id, $validated);

            return $this->success([
                'enquiryId' => $enquiry->id,
                'referenceNo' => $enquiry->referenceNo,
            ], 'Enquiry created successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ExternalController : createEnquiry', $e);
        }
    }

    public function capturePropertyFinderWebhook(Request $request)
    {
        // Persist every received webhook up front — before signature verification
        // or processing — so even rejected/forged or unprocessable calls are
        // captured. Storage is idempotent on redelivery (keyed by provider event
        // id) and the same row is enriched with the resolved property below.
        $webhook = $this->recordPortalWebhook('propertyFinder', $request->all());

        // Reject forged calls: when a webhook secret is configured PF signs the
        // raw payload with HMAC-SHA256 and sends the hex digest in X-Signature.
        $secret = env('PROPERTY_FINDER_WEBHOOK_SECRET', '');
        if (! $this->propertyFinderService->verifyWebhookSignature($request->getContent(), $request->header('X-Signature'), $secret)) {
            Log::channel('property_finder_webhook')->warning('Rejected PropertyFinder webhook — invalid or missing X-Signature.');

            return $this->error('Invalid webhook signature', 401);
        }

        // Reflect the action on the linked listing where one can be resolved. The
        // merge is idempotent and this never throws back to PropertyFinder (always
        // 200) to avoid endless retries.
        try {
            $this->handlePropertyFinderWebhook($request->all(), $webhook);
        } catch (\Throwable $e) {
            Log::channel('property_finder_webhook')->error(
                'Failed to handle PropertyFinder webhook: '.$e->getMessage()
            );
        }

        return $this->success(null, 'Webhook received.');
    }

    /**
     * Receive a Bayut webhook. Every event is persisted to portal_webhooks at the
     * point of receipt (idempotent on redelivery, keyed by portal + event id) so
     * even unprocessable calls are captured. Always returns 200 so Bayut does not
     * retry endlessly.
     */
    public function captureBayutWebhook(Request $request)
    {
        $this->recordPortalWebhook('bayut', $request->all());

        return $this->success(null, 'Webhook received.');
    }

    /**
     * Store a PropertyFinder webhook and map it onto the linked property's
     * pfPortalListingData meta (status / portalStatus / reason). The raw event is
     * always persisted; the status merge runs only for events we track that resolve
     * to a CRM property.
     */
    private function handlePropertyFinderWebhook(array $payload, ?PortalWebhook $webhook = null): void
    {
        $type = $payload['type'] ?? null;
        $entity = $payload['entity'] ?? [];
        $action = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];

        // A new buyer/tenant enquiry generated on PF — turn it into a CRM enquiry.
        if ($type === 'lead.created') {
            $this->handlePropertyFinderLead($action, $webhook);

            return;
        }

        // Listing reference PF assigns to the listing — matches externalListingId
        // stored in the property's pfPortalListingData meta. listing.published /
        // listing.unpublished carry an empty payload, so fall back to entity.id.
        $listingId = $action['listing']['id'] ?? $entity['id'] ?? null;
        $listingId = is_scalar($listingId) && (string) $listingId !== '' ? (string) $listingId : null;

        $changes = $listingId ? $this->mapPropertyFinderEvent($type, $action) : null;

        $propertyId = ($listingId && $changes !== null)
            ? $this->propertyFinderService->applyWebhookListingStatus($listingId, $changes)
            : null;

        // Enrich the row recorded at receipt with the property we acted on.
        if ($webhook && $propertyId !== null) {
            $webhook->update(['propertyId' => $propertyId]);
        }
    }

    /**
     * Turn a PropertyFinder lead.created event into a CRM enquiry. The lead's
     * sender block carries the contact's name and phone; the listing it came in
     * on resolves to the CRM property (and its agent). The webhook is
     * unauthenticated, so we act as the listing's agent to satisfy the enquiry
     * flow — without a resolvable agent or phone there is nothing to attribute the
     * enquiry to, so we log and skip. The responseLink (reply-to URL PF gives us)
     * is stored on the enquiry as its webUrl.
     */
    private function handlePropertyFinderLead(array $action, ?PortalWebhook $webhook): void
    {
        // Resolve the CRM property by its reference number — PF echoes the agency
        // reference we assigned the listing in `listing.reference`.
        $reference = $action['listing']['reference'] ?? null;
        $reference = is_scalar($reference) && (string) $reference !== '' ? (string) $reference : null;

        $property = $reference
            ? Property::where('referenceNumber', $reference)->first()
            : null;

        // The acting user: a lead webhook carries no auth, so attribute the enquiry
        // to the listing's agent and prime the auth context the enquiry flow reads.
        // When the listing has no agent (or no listing matched), fall back to the
        // configured default agent so the lead is still captured rather than dropped.
        $agent = $property?->agent ?? User::find((int) env('CRM_DEFAULT_AGENT_ID', 5));

        if (! $agent) {
            Log::channel('property_finder_webhook')->warning(
                'PropertyFinder lead.created skipped — no CRM agent (and no default agent) resolved for listing reference '.($reference ?? 'unknown').'.'
            );

            return;
        }

        // Extract the contact details from the sender block; phone is required.
        $sender = is_array($action['sender'] ?? null) ? $action['sender'] : [];
        $contactName = is_string($sender['name'] ?? null) ? trim($sender['name']) : null;

        $phone = null;
        foreach ($sender['contacts'] ?? [] as $c) {
            if (is_array($c) && ($c['type'] ?? null) === 'phone' && ! empty($c['value'])) {
                // PF sends E.164 with a leading + ("+971582128994"); normalise so the
                // lookup below compares numbers rather than spellings.
                $phone = normalizePhoneE164((string) $c['value']) ?: (string) $c['value'];
                break;
            }
        }

        if (! $phone) {
            Log::channel('property_finder_webhook')->warning('PropertyFinder lead.created skipped — no contact phone in payload.');

            return;
        }

        auth('api')->setUser($agent);

        // PF states the lead's origin in `type` — only a project lead is Primary Plus.
        $leadType = $this->propertyFinderService->resolveLeadType($action['type'] ?? null);

        $contactType = $this->propertyFinderService->enquiryTypeForLeadType($leadType)
            ?? ($property?->listingType === 'rent'
                ? Constants::CONTACT_TYPE_TENANT
                : Constants::CONTACT_TYPE_BUYER);

        $contact = Contact::resolveMasterByPhone($phone);

        if (! $contact) {
            $contact = Contact::create([
                'name' => $contactName ?: 'Lead - '.$phone,
                'phone' => $phone,
                'contactType' => [$contactType],
                'contactOwner' => $agent->id,
                'source' => Constants::LEAD_SOURCE_PROPERTY_FINDER,
                'createdBy' => $agent->id,
            ]);
        } else {
            $contact->addContactTypes($contactType);
        }

        $responseLink = is_string($action['responseLink'] ?? null) ? $action['responseLink'] : null;

        // PF tells us how the lead reached us (e.g. "whatsapp") in `channel`.
        $channel = is_string($action['channel'] ?? null) ? $action['channel'] : null;

        $data = $this->buildPropertyEnquiryData(
            $property,
            $contact,
            $agent->id,
            $this->propertyFinderService->leadSourceForLeadType($leadType),
            Constants::normalizeSourceKey(Constants::TECHNICAL_SOURCE, $channel),
            $responseLink,
            $leadType,
        );

        $enquiry = $this->enquiryService->createEnquiry($data);

        if ($webhook) {
            $webhook->update(['propertyId' => $property?->id]);
        }

        if ($enquiry->wasRecentlyCreated) {
            logEnquiryHistory([
                'enquiryId' => $enquiry->id,
                'field' => 'created',
                'originalValue' => null,
                'newValue' => json_encode(['message' => "Enquiry created via Property Finder lead webhook: #{$enquiry->id}"]),
            ]);
        }
    }

    /**
     * Build the enquiry payload from a (possibly null) linked property: copies the
     * property's type/community onto the enquiry and derives the price / size /
     * bedroom / bathroom search bands around the listing's own values. Shared by
     * the external enquiry API and the PropertyFinder lead webhook. Reads the
     * acting user via user(), so an auth context must be set by the caller.
     *
     * The enquiry always leaves here with an owner — see the agentId note below.
     */
    private function buildPropertyEnquiryData(?Property $property, Contact $contact, ?int $agentId, ?string $leadSource, ?string $technicalSource, ?string $webUrl = null, ?string $leadType = null): array
    {
        $data = [
            'contactId' => $contact->id,
            'phone' => $contact->phone,
            'listingId' => $property?->id,
            'listingType' => $property?->listingType,
            'enquiryPropertyType' => $property?->category,
            'enquiryPropertySubType' => $property?->pfPropertyType ?? $property?->bayutPropertyType,
            'community' => $property?->pfCommunity,
            // A PF project lead is an off-plan investor and a seller lead is a seller,
            // whatever (if anything) it came in on; every other lead takes its type from
            // the listing. listingType is deliberately left as the listing's own, so an
            // investor enquiry keeps matching exactly the stock a buyer would.
            'enquiryType' => $this->propertyFinderService->enquiryTypeForLeadType($leadType)
                ?? match ($property?->listingType) {
                    'sale' => 'buyer',
                    'rent' => 'tenant',
                    'offPlan' => 'offPlanInvestor',
                    default => 'buyer',
                },
            'leadSource' => $leadSource,
            'technicalSource' => $technicalSource,
            'webUrl' => $webUrl,
            // Never leave a lead unowned. When the caller sent no resolvable agent AND
            // there is no listing to inherit one from — a profile lead whose agentPhone
            // matched nobody — the enquiry used to be created with agentId NULL, which
            // means it belongs to no one, appears on no agent's board and is chased by
            // nobody. Fall back to the house agent, the same last resort the Bayut
            // importer and the PF lead webhook already use.
            'agentId' => $agentId ?? $property?->agentId ?? (int) env('CRM_DEFAULT_AGENT_ID', 5),
            'status' => Constants::ENQUIRY_NEW_LEAD,
            'createdBy' => user()->id,
        ];

        if ($property?->price) {
            $data['priceMin'] = round($property->price * 0.95);
            $data['priceMax'] = round($property->price * 1.05);
        }

        if ($property?->sizeArea) {
            $data['sizeMin'] = $property->sizeArea;
            $data['sizeMax'] = round($property->sizeArea * 1.15);
        }

        if ($property?->bedrooms) {
            $data['bedroomsMin'] = $property->bedrooms;
            $data['bedroomsMax'] = round($property->bedrooms * 1.15);
            if ($data['bedroomsMax'] == $data['bedroomsMin']) {
                $data['bedroomsMax'] = $data['bedroomsMin'] + 1;
            }
        }

        if ($property?->bathrooms) {
            $data['bathroomsMin'] = $property->bathrooms;
            $data['bathroomsMax'] = round($property->bathrooms * 1.15);
            if ($data['bathroomsMax'] == $data['bathroomsMin']) {
                $data['bathroomsMax'] = $data['bathroomsMin'] + 1;
            }
        }

        return $data;
    }

    /**
     * Persist a raw portal webhook to portal_webhooks (shared by propertyFinder /
     * bayut) at the point of receipt. Idempotent on redelivery (keyed by portal +
     * provider event id). Genuinely empty probe bodies are skipped (returns null).
     */
    private function recordPortalWebhook(string $portal, array $payload): ?PortalWebhook
    {
        $type = $payload['type'] ?? null;
        $entity = $payload['entity'] ?? [];
        $action = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];

        $listingId = $action['listing']['id'] ?? $entity['id'] ?? null;
        $listingId = is_scalar($listingId) && (string) $listingId !== '' ? (string) $listingId : null;

        $eventId = $payload['id'] ?? null;
        $eventId = is_scalar($eventId) && (string) $eventId !== '' ? (string) $eventId : null;

        if ($eventId === null && $type === null && $listingId === null) {
            return null;
        }

        $attributes = [
            'type' => $type,
            'listingId' => $listingId,
            'actionType' => $action['actionType'] ?? null,
            'status' => $action['status'] ?? null,
            'reason' => $action['reason'] ?? ($action['message']['en'] ?? null),
            'eventTimestamp' => $payload['timestamp'] ?? null,
            'payload' => $payload,
        ];

        if ($eventId !== null) {
            return PortalWebhook::updateOrCreate(['portal' => $portal, 'eventId' => $eventId], $attributes);
        }

        return PortalWebhook::create(['portal' => $portal, 'eventId' => null] + $attributes);
    }

    /**
     * Translate a PropertyFinder event into the pfPortalListingData fields to
     * merge, or null when the event carries no status meaning we track.
     *
     * listing.published / listing.unpublished are the authoritative publish-state
     * changes. listing.action events are grace-period warnings: a pending action
     * flags the listing (portalStatus = action_required) with the human-readable
     * reason, and an expired action clears the flag back to live (reason cleared
     * when the action RESOLVED). The authoritative lifecycle events still override
     * the flag if PF actually unpublishes the listing.
     */
    private function mapPropertyFinderEvent(?string $type, array $action): ?array
    {
        if ($type === 'listing.published') {
            return ['status' => 'published', 'portalStatus' => 'live', 'reason' => null];
        }

        if ($type === 'listing.unpublished') {
            return ['status' => 'unpublished', 'portalStatus' => 'inactive', 'reason' => 'Unpublished on Property Finder'];
        }

        if ($type === 'listing.action') {
            $status = $action['status'] ?? null;

            if ($status === 'pending') {
                return [
                    'portalStatus' => 'action_required',
                    'reason' => $action['message']['en']
                        ?? $action['message']['ar']
                        ?? $action['actionType']
                        ?? null,
                ];
            }

            if ($status === 'expired') {
                $reason = $action['reason'] ?? null;

                return [
                    'portalStatus' => 'live',
                    'reason' => $reason === 'RESOLVED' ? null : $reason,
                ];
            }
        }

        return null;
    }
}
