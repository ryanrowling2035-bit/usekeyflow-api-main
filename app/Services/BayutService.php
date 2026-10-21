<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\Contact;
use App\Models\Enquiry;
use App\Models\PortalSetting;
use App\Models\Property;
use App\Models\PropertyMeta;
use App\Models\User;
use App\Models\UserMeta;
use App\Services\Concerns\FiltersPortalPayload;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BayutService
{
    use FiltersPortalPayload;

    /**
     * Maps a changed CRM field/meta key (or the 'media' sentinel) to the Bayut
     * payload top-level keys it feeds. Drives partial updates — see
     * {@see FiltersPortalPayload::partialPayload()}.
     */
    protected const UPDATE_FIELD_MAP = [
        'referenceNumber' => ['reference'],
        'title' => ['title'],
        'description' => ['description'],
        'listingType' => ['purpose', 'price', 'rental_details', 'sale_details'],
        'bayutPropertyType' => ['category'],
        'bayutLocationId' => ['location_id'],
        'salePrice' => ['price', 'sale_details'],
        'price' => ['price'],
        'annualRent' => ['price'],
        'sizeArea' => ['area'],
        'plotSize' => ['plot_area'],
        'bedrooms' => ['bedrooms'],
        'bathrooms' => ['bathrooms'],
        'reraPermitNo' => ['permit_number', 'permitNumber'],
        'agentId' => ['user_email', 'agentId'],
        'completionStatus' => ['construction_status', 'sale_details'],
        'ownershipTerm' => ['ownership_status'],
        'bayutAmenities' => ['amenities'],
        'pfPriceFrequency' => ['rental_details'],
        'bayutOccupancyStatus' => ['rental_details'],
        'bayutMinimumContractPeriod' => ['rental_details'],
        'bayutVacatingNoticePeriod' => ['rental_details'],
        'bayutMaintenanceFee' => ['rental_details'],
        'bayutMaintenanceFeePaidBy' => ['rental_details'],

        // Meta keys (1:1 request → metaKey)
        'bayutFurnishingStatus' => ['furnishing_status'],
        'bayutConstructionStatus' => ['construction_status'],
        'bayutOwnershipStatus' => ['ownership_status'],

        // Media sentinel
        'media' => ['images'],
    ];

    protected ?string $baseUrl;

    protected ?string $token;

    protected array $allowedLocationParams = [
        'title', 'per_page', 'page',
        'level_1', 'level_2', 'level_3', 'level_4',
        'level_5', 'level_6', 'level_7', 'level_8',
    ];

    public function __construct()
    {
        $this->baseUrl = env('BAYUT_API_URL');
        $this->token = env('BAYUT_API_TOKEN');
    }

    // ==================== LOCATION LOOKUP ====================

    public function searchLocations(array $params = []): array
    {
        if (! $this->token) {
            Log::error('[Bayut API] Missing API token');

            return [
                'success' => false,
                'status' => 500,
                'error' => 'Bayut API token not configured',
            ];
        }

        if (isset($params['search']) && ! isset($params['title'])) {
            $params['title'] = $params['search'];
        }

        $query = collect($params)
            ->only($this->allowedLocationParams)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();

        $url = "{$this->baseUrl}/locations";

        Log::info('[Bayut API] Calling locations', ['url' => $url, 'query' => $query]);

        $response = Http::withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->get($url, $query);

        if (! $response->successful()) {
            Log::error('[Bayut API] Error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'success' => false,
                'status' => $response->status(),
                'error' => 'Failed to fetch locations from Bayut',
                'details' => $response->body(),
            ];
        }

        $data = $response->json();

        Log::info('[Bayut API] Success', ['count' => count($data['data'] ?? [])]);

        return [
            'success' => true,
            'status' => 200,
            'data' => $this->transformLocations($data['data'] ?? []),
        ];
    }

    protected function transformLocations(array $locations): array
    {
        return array_map(function ($location) {
            $breadcrumb = $location['breadcrumb']['en'] ?? null;

            if (is_array($breadcrumb) && isset($breadcrumb[0]) && $breadcrumb[0] === 'UAE') {
                array_shift($breadcrumb);
            }

            return [
                'id' => $location['id'] ?? null,
                'location' => is_array($breadcrumb) ? implode(' > ', $breadcrumb) : null,
                'latitude' => $location['coordinates']['latitude'] ?? null,
                'longitude' => $location['coordinates']['longitude'] ?? null,
            ];
        }, $locations);
    }

    // ==================== AMENITIES LOOKUP ====================

    public function getAmenities(): array
    {
        if (! $this->token) {
            return ['success' => false, 'status' => 500, 'error' => 'Bayut API token not configured'];
        }

        return Cache::remember('bayut_amenities_index', now()->addHours(24), function () {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->asJson()
                ->get("{$this->baseUrl}/amenities");

            if (! $response->successful()) {
                Log::error('[Bayut API] amenities error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return ['success' => false, 'status' => $response->status(), 'error' => 'Failed to fetch amenities'];
            }

            return [
                'success' => true,
                'status' => 200,
                'data' => $response->json('data', []),
            ];
        });
    }

    // ==================== PUBLISH FLOW ====================

    /**
     * Publish a property to Bayut via the 3-step API chain:
     *   1. POST /properties                              → creates draft, returns Bayut property id
     *   2. POST /properties/{id}/permit/validate         → only when a permit is present (RERA, not DTCM)
     *   3. POST /properties/{id}/products/attach         → publishes to the bayut portal
     *
     * Options:
     *   - product:    "hot" | "signature" | "featured" | "premium"  (omit for standard)
     *   - dryRun:     true → returns the built payload without hitting Bayut (preview)
     */
    public function publishProperty(Property $property, PortalSetting $portalSetting, array $options = []): array
    {
        $payload = $this->buildPayload($property);

        if (isset($payload['error'])) {
            return ['success' => false, 'status' => 422, 'error' => $payload['error']];
        }

        if (! empty($options['dryRun'])) {
            return ['success' => true, 'status' => 200, 'payload' => $payload];
        }

        $this->token = $portalSetting->bayutSecretToken;

        if (! $this->token) {
            return ['success' => false, 'status' => 422, 'error' => "Portal account '{$portalSetting->slug}' is missing Bayut secret token"];
        }

        if (! $this->baseUrl) {
            return ['success' => false, 'status' => 500, 'error' => 'Bayut API URL not configured'];
        }

        Log::info('[Bayut API] Create property payload', $payload);

        // Step 1: Create property (draft)
        $createResp = $this->http()->post('/properties', $payload);

        if (! $createResp->successful()) {
            return $this->bayutError('create', $createResp);
        }

        $bayutPropertyId = $createResp->json('data.id') ?? $createResp->json('id');

        if (! $bayutPropertyId) {
            Log::error('[Bayut API] Create succeeded but response missing property id', [
                'body' => $createResp->body(),
            ]);

            return ['success' => false, 'status' => 502, 'error' => 'Bayut create response missing property id'];
        }

        $listingData = [
            'account' => $portalSetting->slug,
            'externalListingId' => (string) $bayutPropertyId,
            'status' => 'draft',
        ];
        $this->saveListingData($property, $listingData, merge: false);

        // Step 2: Validate permit — only when a real RERA permit is present.
        // Skip for vacationHomes: those carry DTCM codes in reraPermitNo, and the
        // /permit/validate endpoint is RERA/DLD-specific and would reject them.
        $shouldValidatePermit = ! empty($payload['permit_number'])
            && $property->listingType !== 'vacationHomes';
        $permitValidated = false;

        if ($shouldValidatePermit) {
            $validateResp = $this->http()->post(
                "/properties/{$bayutPropertyId}/permit/validate",
                ['permit_number' => $payload['permit_number']]
            );

            if (! $validateResp->successful()) {
                return $this->bayutError('permit_validate', $validateResp, $bayutPropertyId);
            }

            $permitValidated = true;
        }

        // Product attach (publish to the bayut portal) is intentionally NOT done
        // here — the listing is left in 'draft'. Publishing is a separate, explicit
        // step: POST property/publish-draft/bayut → publishDraftListing().
        logPropertyHistory([
            'propertyId' => $property->id,
            'field' => 'drafted_on_bayut',
            'newValue' => json_encode($listingData + [
                'message' => "{$portalSetting->branchName} — listing ID {$bayutPropertyId}",
            ]),
        ]);

        Log::info('[Bayut API] Property draft created', [
            'crm_property_id' => $property->id,
            'bayut_property_id' => $bayutPropertyId,
            'permit_validated' => $permitValidated,
            'account' => $portalSetting->slug,
        ]);

        return [
            'success' => true,
            'status' => 200,
            'bayutPropertyId' => $bayutPropertyId,
            'permitValidated' => $permitValidated,
            'message' => 'Property draft created on Bayut',
        ];
    }

    /**
     * Publish a Bayut draft (created by {@see publishProperty()}) to the bayut
     * portal by attaching a product. Updates the stored listing meta status and
     * logs property history on success. This is the explicit second step of the
     * two-step publish flow — call it once the listing exists in 'draft'.
     *
     * @param  array  $options  optional ['product' => 'hot'|'signature'|'featured'|'premium']
     */
    public function publishDraftListing(Property $property, PortalSetting $portalSetting, string $externalListingId, array $options = []): array
    {
        $this->token = $portalSetting->bayutSecretToken;

        if (! $this->token) {
            return ['success' => false, 'status' => 422, 'error' => "Portal account '{$portalSetting->slug}' is missing Bayut secret token"];
        }

        if (! $this->baseUrl) {
            return ['success' => false, 'status' => 500, 'error' => 'Bayut API URL not configured'];
        }

        $attachResp = $this->publishListing($externalListingId, $options);

        if (! $attachResp->successful()) {
            return $this->bayutError('attach', $attachResp, $externalListingId);
        }

        Log::info('[Bayut API] Listing published', $attachResp->json());

        // Status is per-portal under data.portals[]; read the 'bayut' portal state.
        $attachData = $attachResp->json('data') ?? $attachResp->json();
        ['state' => $portalState, 'reasons' => $reasons] = $this->bayutPortalState($attachData);

        // Bayut answers 200 for an attach that leaves the listing OFF the portal (permit or
        // price mismatch, compliance pending, …) with the real outcome in the portal state —
        // the rejection is not an HTTP error. Record what actually happened instead of
        // logging a publish that never went live.
        if ($portalState !== null && in_array(strtolower($portalState), self::OFF_PORTAL_STATES, true)) {
            $this->saveListingData($property, [
                'status' => $portalState,
                'portalStatus' => $portalState,
                'reason' => $reasons ? implode(' | ', $reasons) : null,
            ]);

            Log::warning('[Bayut API] Attach succeeded but listing is not on the portal', [
                'crm_property_id' => $property->id,
                'bayut_property_id' => $externalListingId,
                'account' => $portalSetting->slug,
                'portal_state' => $portalState,
                'reasons' => $reasons,
            ]);

            return [
                'success' => false,
                'status' => 422,
                'bayutPropertyId' => $externalListingId,
                'state' => $portalState,
                'error' => $reasons
                    ? 'Bayut did not publish this listing: '.implode(' | ', $reasons)
                    : "Bayut did not publish this listing — it is currently '{$portalState}'.",
            ];
        }

        $listingData = [
            'status' => $portalState,
            'reason' => $reasons ? implode(' | ', $reasons) : null,
        ];
        $this->saveListingData($property, $listingData);
        logPropertyHistory([
            'propertyId' => $property->id,
            'field' => 'published_to_bayut',
            'newValue' => json_encode($listingData + [
                'message' => "{$portalSetting->branchName} — listing ID {$externalListingId}",
            ]),
        ]);

        Log::info('[Bayut API] Property published', [
            'crm_property_id' => $property->id,
            'bayut_property_id' => $externalListingId,
            'account' => $portalSetting->slug,
        ]);

        return [
            'success' => true,
            'status' => 200,
            'bayutPropertyId' => $externalListingId,
            'message' => 'Property published to Bayut',
        ];
    }

    /**
     * Publish a draft Bayut property to the bayut portal via
     * POST /properties/{id}/products/attach. Returns the raw HTTP response so the
     * caller can branch on success / build bayutError.
     *
     * @param  array  $options  optional ['product' => 'hot'|'signature'|'featured'|'premium']
     */
    protected function publishListing($bayutPropertyId, array $options = [])
    {
        $attachBody = ['portal' => 'bayut'];

        if (! empty($options['product'])) {
            $attachBody['product'] = $options['product'];
        }

        return $this->http()->post("/properties/{$bayutPropertyId}/products/attach", $attachBody);
    }

    /**
     * Push CRM updates to an already-published Bayut property. Permit validation
     * and product attach are intentionally skipped — those ran at publish time.
     *
     * $changedFields is used only to decide whether anything portal-relevant
     * changed (skip the call if not); the full payload is always PUT, since Bayut
     * does not support PATCH.
     */
    public function updateProperty(Property $property, PortalSetting $portalSetting, string $externalListingId, array $changedFields = []): array
    {
        $payload = $this->buildPayload($property);

        if (isset($payload['error'])) {
            return ['success' => false, 'status' => 422, 'error' => $payload['error']];
        }

        $this->token = $portalSetting->bayutSecretToken;

        if (! $this->token) {
            return ['success' => false, 'status' => 422, 'error' => "Portal account '{$portalSetting->slug}' is missing Bayut secret token"];
        }

        if (! $this->baseUrl) {
            return ['success' => false, 'status' => 500, 'error' => 'Bayut API URL not configured'];
        }

        // Bayut only supports PUT (full replace) on /properties/{id} — PATCH is
        // rejected (405), and like Property Finder a pruned partial body fails
        // schema validation / wipes omitted fields. Use the partial filter solely
        // to decide whether anything portal-relevant changed; skip the call if not.
        if (empty($this->partialPayload($payload, $changedFields))) {
            Log::info('[Bayut API] Update skipped — no portal-relevant fields changed', [
                'crm_property_id' => $property->id,
                'bayut_property_id' => $externalListingId,
                'changedFields' => $changedFields,
            ]);

            return ['success' => true, 'status' => 200, 'skipped' => true, 'message' => 'No portal-relevant fields changed'];
        }

        Log::info('[Bayut API] Property update payload', $payload);

        // Always PUT the full payload — a pruned partial body would wipe untouched fields.
        $updateResp = $this->http()->put("/properties/{$externalListingId}", $payload);

        if (! $updateResp->successful()) {
            $error = $this->bayutError('update', $updateResp, $externalListingId);

            // Failed even after http()'s timeout retries — record it on the listing
            // timeline, otherwise a background sync failure is invisible in the CRM.
            logPropertyHistory([
                'propertyId' => $property->id,
                'field' => 'bayut_update_failed',
                'newValue' => json_encode([
                    'account' => $portalSetting->slug,
                    'externalListingId' => $externalListingId,
                    'status' => $error['status'] ?? null,
                    'message' => "{$portalSetting->branchName} — listing ID {$externalListingId}: ".($error['error'] ?? 'Unknown error'),
                ]),
            ]);

            return $error;
        }

        Log::info('[Bayut API] Property updated response', $updateResp->json());

        logPropertyHistory([
            'propertyId' => $property->id,
            'field' => 'updated_on_bayut',
            'newValue' => json_encode([
                'account' => $portalSetting->slug,
                'externalListingId' => $externalListingId,
                'message' => "{$portalSetting->branchName} — listing ID {$externalListingId}",
            ]),
        ]);

        Log::info('[Bayut API] Property updated', [
            'crm_property_id' => $property->id,
            'bayut_property_id' => $externalListingId,
            'account' => $portalSetting->slug,
        ]);

        return [
            'success' => true,
            'status' => 200,
            'bayutPropertyId' => $externalListingId,
            'message' => 'Property updated on Bayut',
        ];
    }

    /**
     * Find an already-live Bayut property id by our reference number — used to
     * reconcile imported listings that have no externalListingId in meta yet.
     *
     * NOTE: confirm the Bayut lookup endpoint/param with their integrations team.
     * Assuming GET /properties?reference={ref} returns matching listings.
     */
    public function findExternalIdByReference(Property $property, PortalSetting $portalSetting): ?string
    {
        $reference = (string) ($property->referenceNumber ?? $property->id);

        if ($reference === '') {
            return null;
        }

        $this->token = $portalSetting->bayutSecretToken;

        if (! $this->token || ! $this->baseUrl) {
            return null;
        }

        $resp = $this->http()->get('/properties', ['reference' => $reference]);

        if (! $resp->successful()) {
            Log::warning('[Bayut API] reference lookup failed', [
                'crm_property_id' => $property->id,
                'reference' => $reference,
                'status' => $resp->status(),
            ]);

            return null;
        }

        $items = $resp->json('data') ?? $resp->json('results') ?? [];

        foreach ($items as $item) {
            if ((string) ($item['reference'] ?? '') === $reference) {
                return isset($item['id']) ? (string) $item['id'] : null;
            }
        }

        return null;
    }

    /**
     * The single PropertyMeta row that links a property to its Bayut listing
     * (account, externalListingId, status/reason). One source of truth for the
     * propertyId + bayutPortalListingData lookup reused across publish / update /
     * fetch / unpublish. Returns an unsaved instance when none exists yet, so
     * callers can mutate + save() uniformly.
     *
     * Keyed by $property (not cached in __construct) because this service is
     * container-resolved and handles many properties per request/job.
     */
    /**
     * Bayut portal states that mean the listing is NOT on the portal. Used to judge an
     * attach from the returned state rather than the HTTP status. An unknown or absent
     * state counts as live on purpose — the attach call itself succeeded, and failing on a
     * state we don't recognise would block a publish that actually worked.
     */
    protected const OFF_PORTAL_STATES = ['inactive', 'draft', 'unpublished', 'pending', 'rejected', 'expired', 'delete', 'deleted'];

    /**
     * The 'bayut' portal entry of a listing / attach response: its state plus any blocking
     * reasons flattened to strings. Bayut reports status per-portal under data.portals[]
     * (bayut / dubizzle), never as a top-level field, and carries the permit compliance
     * note (e.g. pending RERA review) separately under permit.comment.
     *
     * @return array{state: string|null, reasons: array<int, string>}
     */
    protected function bayutPortalState(array $listing): array
    {
        $portal = collect($listing['portals'] ?? [])->firstWhere('name', 'bayut') ?? [];

        $reasons = array_filter(array_map(
            fn ($r) => is_array($r) ? ($r['en'] ?? null) : $r,
            $portal['reasons'] ?? []
        ));

        if (! empty($listing['permit']['comment'])) {
            $reasons[] = $listing['permit']['comment'];
        }

        return ['state' => $portal['state'] ?? null, 'reasons' => array_values($reasons)];
    }

    protected function listingMeta(Property $property): PropertyMeta
    {
        return PropertyMeta::firstOrNew([
            'propertyId' => $property->id,
            'metaKey' => 'bayutPortalListingData',
        ]);
    }

    /**
     * Persist Bayut listing data onto {@see listingMeta()} and return the stored
     * array. Replaces the repeated decode → mutate → json_encode → save dance.
     * $merge=true layers $changes over the existing JSON (status touch-ups);
     * $merge=false replaces it wholesale (fresh publish/draft snapshots).
     */
    protected function saveListingData(Property $property, array $changes, bool $merge = true): array
    {
        $meta = $this->listingMeta($property);

        $data = $merge && $meta->metaValue
            ? array_merge(json_decode((string) $meta->metaValue, true) ?: [], $changes)
            : $changes;

        $meta->metaValue = json_encode($data);
        $meta->save();

        return $data;
    }

    /**
     * Revert a live Bayut property back to draft.
     * TODO: confirm endpoint with Bayut integrations team — using POST /properties/{id}/products/detach
     * as the conventional reverse of POST /properties/{id}/products/attach.
     */
    public function unpublishProperty(Property $property, PortalSetting $portalSetting, string $externalListingId): array
    {
        $this->token = $portalSetting->bayutSecretToken;

        if (! $this->token) {
            return ['success' => false, 'status' => 422, 'error' => "Portal account '{$portalSetting->slug}' is missing Bayut secret token"];
        }

        if (! $this->baseUrl) {
            return ['success' => false, 'status' => 500, 'error' => 'Bayut API URL not configured'];
        }

        $detachResp = $this->http()->delete(
            "/properties/{$externalListingId}",
            ['portals' => ['bayut', 'dubizzle']]
        );

        if (! $detachResp->successful()) {
            $this->saveListingData($property, [
                'reason' => "We couldn't remove this listing from the portal. Please try again later.",
            ]);

            return $this->bayutError('delete', $detachResp, $externalListingId);
        }

        logPropertyHistory([
            'propertyId' => $property->id,
            'field' => 'reverted_to_draft_on_bayut',
            'newValue' => json_encode([
                'account' => $portalSetting->slug,
                'externalListingId' => $externalListingId,
                'message' => "{$portalSetting->branchName} — property ID {$externalListingId} reverted to draft",
            ]),
        ]);

        Log::info('[Bayut API] Property reverted to draft', [
            'crm_property_id' => $property->id,
            'bayut_property_id' => $externalListingId,
            'account' => $portalSetting->slug,
        ]);

        $this->saveListingData($property, [
            'portalStatus' => 'inactive',
            'status' => 'unpublished',
            'reason' => 'Deleted successfully.',
        ]);

        return [
            'success' => true,
            'status' => 200,
            'bayutPropertyId' => $externalListingId,
            'message' => 'Bayut property reverted to draft',
        ];
    }

    /**
     * Fetch a single Bayut property by its id (GET /properties/{id}) to read the
     * current live status. Returns the raw listing payload so the caller can sync
     * the state back into property meta.
     */
    public function fetchListingById(Property $property, string $externalListingId, PortalSetting $portalSetting): array
    {
        $this->token = $portalSetting->bayutSecretToken;

        if (! $this->token) {
            return ['success' => false, 'status' => 422, 'error' => "Portal account '{$portalSetting->slug}' is missing Bayut secret token"];
        }

        if (! $this->baseUrl) {
            return ['success' => false, 'status' => 500, 'error' => 'Bayut API URL not configured'];
        }

        $resp = $this->http()->get("/properties/{$externalListingId}");

        if (! $resp->successful()) {
            return $this->bayutError('fetch', $resp, $externalListingId);
        }

        $listing = $resp->json('data') ?? $resp->json();

        ['state' => $portalState, 'reasons' => $reasons] = $this->bayutPortalState($listing);

        Log::info('[Bayut API] fetchListingById', [
            'bayut_property_id' => $externalListingId,
            'account' => $portalSetting->slug,
            'portal_state' => $portalState,
        ]);

        // Sync the live Bayut state back into the property's listing meta. Persist the
        // link (account + externalListingId) alongside the status so a listing found by
        // reference — which had no meta row — becomes addressable by id next time.
        $listingData = $this->saveListingData($property, [
            'account' => $portalSetting->slug,
            'externalListingId' => $externalListingId,
            'status' => $portalState,
            'portalStatus' => $portalState,
            'reason' => $reasons ? implode(' | ', $reasons) : null,
        ]);

        return [
            'success' => true,
            'status' => 200,
            'listing' => $listing,
            'portalState' => $portalState,
            'listingData' => $listingData,
        ];
    }

    protected function http()
    {
        // Retry timeouts up to 3 attempts — Bayut intermittently answers 422 "timed out"
        // (property 13750's price update was lost this way). POST is excluded: a create
        // that timed out may still have landed, and a retry would duplicate the listing.
        return Http::withToken($this->token)
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->retry(3, 2000, fn (\Throwable $e, $request, ?string $method = null) => $method !== 'POST' && (
                $e instanceof ConnectionException
                || ($e instanceof RequestException && (
                    in_array($e->response->status(), [408, 504], true)
                    || str_contains(strtolower($e->response->body()), 'timed out')
                ))
            ), throw: false);
    }

    /**
     * Normalise a failed Bayut HTTP response into our error shape. Maps the
     * statuses Bayut documents (401, 403, 409, 422) to actionable messages and
     * surfaces the structured validation body (message + errors) when present.
     */
    protected function bayutError(string $stage, $response, $bayutPropertyId = null): array
    {
        $status = $response->status();
        $body = $response->json();

        Log::error("[Bayut API] {$stage} failed", [
            'status' => $status,
            'body' => $response->body(),
            'bayut_property_id' => $bayutPropertyId,
        ]);

        // Bayut's 422 carries { message, errors{} }; prefer its message verbatim.
        $apiMessage = is_array($body) ? ($body['message'] ?? null) : null;

        $error = $apiMessage ?? match ($status) {
            401 => 'Bayut authentication failed — secret token is invalid or expired',
            403 => 'Bayut authorization failed — this account is not permitted to perform this action',
            409 => 'Bayut idempotency conflict — this request was already processed',
            422 => "Bayut rejected the {$stage} — validation failed",
            default => "Bayut {$stage} failed",
        };

        return array_filter([
            'success' => false,
            'status' => $status,
            'stage' => $stage,
            'error' => $error,
            'errors' => is_array($body) ? ($body['errors'] ?? null) : null,
            'details' => $body ?? $response->body(),
            'bayutPropertyId' => $bayutPropertyId,
        ], fn ($v) => $v !== null);
    }

    // ==================== PAYLOAD BUILDER ====================

    /**
     * Bayut rewrites every <p>/</p> to <br>, so a rich-text description (one <p>
     * per line) renders double-spaced — each paragraph contributes two breaks.
     * Normalise the markup the same way Property Finder does: unwrap nested
     * <li><p>, prefix list items with a dash bullet, convert every block-level
     * boundary to a single newline, strip the remaining tags, and collapse runs
     * to at most one blank line. Then emit <br> (Bayut's HTML line break) so the
     * spacing matches the Property Finder output exactly.
     */
    protected function formatDescriptionForBayut(?string $html): string
    {
        $html = (string) $html;

        if (trim($html) === '') {
            return '';
        }

        // Rich-text editors wrap each list item's text in its own <p>
        // (<li><p>…</p></li>). Unwrap so each list item yields a single line.
        $html = preg_replace('~<li([^>]*)>\s*<p[^>]*>~i', '<li$1>', $html);
        $html = preg_replace('~</p>\s*</li>~i', '</li>', $html);

        // Prefix each list item with a dash bullet (matches Property Finder).
        $html = preg_replace('~<li[^>]*>~i', '- ', $html);

        // Convert block-level boundaries to a single newline before stripping so
        // paragraphs/lists keep their structure without Bayut's <p>→<br> doubling.
        $html = preg_replace('#<\s*(br|/p|/div|/li|/h[1-6])\s*/?>#i', "\n", $html);
        $text = strip_tags($html);
        $text = preg_replace('/[ \t]+/u', ' ', $text);   // collapse spaces, keep newlines
        $text = preg_replace('/ *\n */', "\n", $text);    // trim spaces around breaks
        $text = preg_replace('/\n{3,}/', "\n\n", $text);  // at most one blank line
        $text = trim($text);

        return str_replace("\n", '<br>', $text);
    }

    protected function buildPayload(Property $property): array
    {
        $purpose = $this->mapPurpose($property->listingType);

        if (! $purpose) {
            return ['error' => "Unsupported listingType '{$property->listingType}' for Bayut publishing"];
        }

        if (! $property->bayutPropertyType) {
            return ['error' => 'Property is missing bayutPropertyType'];
        }

        if (! $property->bayutLocationId) {
            return ['error' => 'Property is missing bayutLocationId'];
        }

        $meta = $property->loadMissing('meta')->meta->pluck('metaValue', 'metaKey');

        $agent = $this->resolveAgent($property);

        if (! $agent) {
            return ['error' => 'Cannot resolve Bayut agent (no _brokerId or email on agent user)'];
        }

        $images = $this->mapImages($property);

        if (empty($images)) {
            return ['error' => 'Property has no portal-flagged images'];
        }

        $payload = [
            'reference' => (string) ($property->referenceNumber ?? $property->id),
            'title' => ['en' => $property->title],
            'description' => ['en' => $this->formatDescriptionForBayut($property->description)],
            'purpose' => $purpose,
            'category' => $property->bayutPropertyType,
            'location_id' => (int) $property->bayutLocationId,
            // Bayut schema types price as integer; a whole-number float json_encodes
            // as "N.0" and is rejected — cast to int (mirrors PropertyFinderService).
            'price' => (int) round($this->resolvePrice($property)),
            'area' => (float) $property->sizeArea,
            'images' => $images,
        ];

        // Agent identity

        $publicProfileId = UserMeta::where('userId', $property->agentId)
            ->where('metaKey', '_pfPublicProfileId')
            ->value('metaValue');

        $payload['user_email'] = $publicProfileId ?: $agent['user_email'];

        $brokerId = UserMeta::where('userId', $property->agentId)
            ->where('metaKey', '_brokerId')
            ->value('metaValue');

        if ($brokerId) {
            $payload['agentId'] = $brokerId;
        }

        if ($property->reraPermitNo) {
            $payload['permitNumber'] = $property->reraPermitNo;
        }

        // Optional scalar fields
        if ($property->bedrooms !== null) {
            $payload['bedrooms'] = (int) $property->bedrooms === 0 ? -1 : (int) $property->bedrooms;
        }
        if ($property->bathrooms !== null) {
            $payload['bathrooms'] = (int) $property->bathrooms;
        }
        if ($property->plotSize) {
            $payload['plot_area'] = (float) $property->plotSize;
        }
        if (! empty($meta['bayutFurnishingStatus'])
            && ($furnishing = Constants::PROPERTY_FURNISHING_TYPE_BAYUT[$meta['bayutFurnishingStatus']] ?? null)
        ) {
            $payload['furnishing_status'] = $furnishing;
        }
        // Bayut accepts only 'completed' or 'under-construction', which mapCompletionStatus emits;
        // an unmappable status is omitted rather than sent (any other value is rejected with a 422).
        if ($construction = $this->mapCompletionStatus($property->completionStatus)) {
            $payload['construction_status'] = $construction;
        }
        if ($property->reraPermitNo) {
            $payload['permit_number'] = $property->reraPermitNo;
        }
        if (! empty($meta['bayutOwnershipStatus'])) {
            $payload['ownership_status'] = (string) $meta['bayutOwnershipStatus'];
        } elseif (! empty($property->ownershipTerm)) {
            $payload['ownership_status'] = $property->ownershipTerm;
        }

        // Purpose-specific
        if ($purpose === 'rent') {
            $payload['rental_details'] = $this->buildRentalDetails($property);
        } else {
            $saleDetails = $this->buildSaleDetails($property);
            if (! empty($saleDetails)) {
                $payload['sale_details'] = $saleDetails;
            }
        }

        // Amenities
        $amenities = $this->mapAmenities($property);
        if (! empty($amenities)) {
            $payload['amenities'] = $amenities;
        }

        // Videos
        $videos = $this->mapVideos($property);
        if (! empty($videos)) {
            $payload['videos'] = $videos;
        }

        return $payload;
    }

    protected function mapPurpose(?string $listingType): ?string
    {
        return match ($listingType) {
            'rent', 'vacationHomes' => 'rent',
            'sale', 'offPlan' => 'sale',
            default => null,
        };
    }

    protected function mapCompletionStatus(?string $status): ?string
    {
        return Constants::COMPLETION_STATUS_BAYUT[$status] ?? null;
    }

    protected function resolvePrice(Property $property): float
    {
        if ($property->listingType === 'sale' || $property->listingType === 'offPlan') {
            return (float) $property->price;
        }

        return (float) ($property->annualRent ?? $property->price ?? 0);
    }

    protected function buildRentalDetails(Property $property): array
    {
        $details = [
            'rent_frequency' => $property->listingType === 'vacationHomes'
                ? 'daily'
                : ($property->pfPriceFrequency ?? 'yearly'),
        ];

        if ($property->bayutOccupancyStatus) {
            $details['occupancy_status'] = $property->bayutOccupancyStatus;
        }
        if ($property->bayutMinimumContractPeriod) {
            $details['minimum_contract_period'] = (int) $property->bayutMinimumContractPeriod;
        }
        if ($property->bayutVacatingNoticePeriod) {
            $details['vacating_notice_period'] = (int) $property->bayutVacatingNoticePeriod;
        }
        if ($property->bayutMaintenanceFee !== null) {
            $details['maintenance_fee'] = (float) $property->bayutMaintenanceFee;
        }
        if ($property->bayutMaintenanceFeePaidBy) {
            $details['maintenance_fee_paid_by'] = $property->bayutMaintenanceFeePaidBy;
        }

        return $details;
    }

    protected function buildSaleDetails(Property $property): array
    {
        $details = [];

        if (in_array($property->completionStatus, ['offPlan', 'offPlanPrimary'], true)) {
            $details['sale_type'] = 'new';
        }

        return $details;
    }

    protected function mapImages(Property $property): array
    {
        $media = $property->relationLoaded('media')
            ? $property->media
            : $property->media()
                ->where('mediaType', 'uploadPhotos')
                ->where('isPortals', 1)
                ->orderBy('ordering')
                ->get();

        $main = true;
        $images = [];

        foreach ($media as $file) {
            $path = $file->compressedPath ?: $file->filePath;

            if (! $path) {
                continue;
            }

            $images[] = [
                'path' => $this->absoluteMediaUrl($path),
                'is_main' => $main,
                'title' => $file->category ?? '',
            ];

            $main = false;

            if (count($images) >= 50) {
                break;
            }
        }

        return $images;
    }

    protected function absoluteMediaUrl(string $path): string
    {
        // Already an absolute URL (e.g. imported/CDN media) — don't prefix APP_URL.
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return rtrim((string) env('APP_URL'), '/').'/'.ltrim($path, '/');
    }

    protected function mapVideos(Property $property): array
    {
        $videos = $property->relationLoaded('propertyVideos')
            ? $property->propertyVideos
            : $property->propertyVideos()->get();

        $hostMap = [
            'youtube' => 'youtube',
            'vimeo' => 'vimeo',
            'dailymotion' => 'dailymotion',
            '3d_view' => '3d_view',
            'matterport' => '3d_view',
        ];

        $out = [];

        foreach ($videos as $v) {
            if (! $v->videoUrl) {
                continue;
            }

            $host = $hostMap[strtolower((string) $v->platform)] ?? 'youtube';

            $out[] = [
                'host' => $host,
                'link' => $v->videoUrl,
                'title' => $v->title ?? '',
            ];

            if (count($out) >= 10) {
                break;
            }
        }

        return $out;
    }

    /**
     * Map our bayutAmenities keys → Bayut amenity IDs (resolved via /amenities cache).
     */
    protected function mapAmenities(Property $property): array
    {
        $keys = $property->bayutAmenities;

        if (empty($keys) || ! is_array($keys)) {
            return [];
        }

        $catalog = $this->getAmenities();

        if (empty($catalog['success']) || empty($catalog['data'])) {
            Log::warning('[Bayut API] Amenities catalog unavailable, sending raw keys');

            return [];
        }

        // Build slug → id map from Bayut catalog
        $slugToId = [];
        foreach ($catalog['data'] as $a) {
            if (! empty($a['slug']) && ! empty($a['id'])) {
                $slugToId[$a['slug']] = $a['id'];
            }
        }

        $out = [];

        foreach ($keys as $key => $value) {
            // Support both ['poolElevators' => 4] and ['swimmingPool', 'gym']
            if (is_int($key)) {
                $crmKey = $value;
                $crmValue = 'true';
            } else {
                $crmKey = $key;
                $crmValue = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }

            $slug = $this->crmKeyToBayutSlug($crmKey);
            $id = $slugToId[$slug] ?? null;

            if (! $id) {
                continue;
            }

            $out[] = [
                'id' => (string) $id,
                'value' => $crmValue,
            ];

            if (count($out) >= 100) {
                break;
            }
        }

        return $out;
    }

    /**
     * Convert CRM camelCase amenity key (e.g. swimmingPool) to Bayut slug (e.g. swimming-pool).
     */
    protected function crmKeyToBayutSlug(string $key): string
    {
        if (isset(Constants::PROPERTY_BAYUT_AMENITY_SLUG_OVERRIDES[$key])) {
            return Constants::PROPERTY_BAYUT_AMENITY_SLUG_OVERRIDES[$key];
        }

        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $key));
    }

    /**
     * Resolve Bayut agent identity for a property.
     * Priority: user_meta._brokerId → user.email
     */
    protected function resolveAgent(Property $property): ?array
    {
        if (! $property->agentId) {
            return null;
        }

        $brokerId = UserMeta::where('userId', $property->agentId)
            ->where('metaKey', '_brokerId')
            ->value('metaValue');

        if ($brokerId) {
            return ['user_id' => $brokerId];
        }

        $email = User::where('id', $property->agentId)->value('email');

        if ($email) {
            return ['user_email' => $email];
        }

        return null;
    }

    // ==================== LEAD IMPORT ====================

    /**
     * Start of the window the lead import asks Bayut for, as a local date (YYYY-MM-DD).
     *
     * Bayut's created_from is date-granular, so unlike Property Finder it cannot express
     * a 2-hour window — the tightest it accepts is a whole day. Anchoring the date two
     * hours back is still worth doing: it rolls over to yesterday until 02:00 local,
     * which closes the midnight hole. With a plain "today", a lead arriving after the
     * last run of the day fell outside every window and was never imported.
     *
     * Public so the import command can log the window it ran with.
     */
    public function leadWindowStart(): string
    {
        return now(appTimezone())->subHours(2)->toDateString();
    }

    /**
     * The lead targets we pull, each fetched as its own pass.
     *
     * Bayut accepts one target per request (listing | project | agent | agency) and only
     * fills in the `agent_details` / `listing_details` blocks when the request NAMES one.
     * Dropping the filter to get everything in a single sweep is a trap: the same leads
     * come back stripped to `lead` + `inquirer_details`, with no agent and no listing
     * reference left to attribute them to. So the targets are polled one at a time.
     *
     * - listing: an enquiry raised on one of our listings.
     * - agent:   an enquiry raised on an agent's own Bayut profile — a client searches an
     *            area, picks a broker out of the results and contacts them directly, so
     *            there is no listing involved at all. These carry agent_details and nothing
     *            else, and were previously never fetched: a profile enquiry reached the CRM
     *            only when the client happened to use WhatsApp (which arrives by a separate
     *            route, the automation POSTing /external/enquiry/create). A call or an email
     *            to the same profile was dropped on the floor.
     *
     * `agency` and `project` are deliberately left out: Bayut sends neither with an agent
     * block, so every one of them would land on the house agent rather than a real owner.
     */
    private const LEAD_TARGETS = ['listing', 'agent'];

    /**
     * Fetch and import every Bayut lead for one account (identified by its secret token).
     *
     * Pulls the leadWindowStart() window for each target in LEAD_TARGETS, paging until a
     * short page signals the end. Returns the raw lead payloads that were fetched.
     */
    public function getLeads(string $token): array
    {
        $this->token = $token;

        $allLeads = [];
        $perPage = 100;

        $createdFrom = $this->leadWindowStart();

        foreach (self::LEAD_TARGETS as $target) {
            $page = 1;

            do {
                $response = $this->getLeadsFromBayut([
                    'channel' => ['phone', 'email'],
                    'is_lead' => 1,
                    'target' => $target,
                    'created_from' => $createdFrom,
                    'per_page' => $perPage,
                    'page' => $page,
                ]);

                $leads = $response['data'] ?? [];
                $fetched = count($leads);

                // created_from is date-granular, so Bayut always hands back the whole day
                // however tight a window we ask for. Trim it here to the same 2 hours
                // Property Finder uses — otherwise every lead stays visible to every run
                // until midnight (~190 runs at the five-minute schedule) and is re-examined
                // by all of them. importLead de-dups whatever slips through, listing or
                // profile lead.
                // A lead with no usable timestamp is kept rather than silently dropped.
                $cutoff = now(appTimezone())->subHours(2);
                $leads = array_values(array_filter($leads, function ($lead) use ($cutoff) {
                    $createdAt = $lead['lead']['date_time'] ?? null;

                    return ! $createdAt || Carbon::parse($createdAt, appTimezone())->gte($cutoff);
                }));

                foreach ($leads as $lead) {
                    $this->importLead($lead);
                }
                $allLeads = array_merge($allLeads, $leads);

                // Pagination follows what Bayut returned, not what survived the trim.
                $hasMore = $fetched === $perPage;
                $page++;
            } while ($hasMore);
        }

        return $allLeads;
    }

    /**
     * Low-level GET against Bayut's /leads endpoint. Array params (e.g. channel) are
     * expanded into repeated key=value pairs, matching the Property Finder importer.
     */
    public function getLeadsFromBayut(array $params): array
    {
        if (! $this->token) {
            return [];
        }

        $queryParts = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $queryParts[] = $key.'='.urlencode($v);
                }
            } else {
                $queryParts[] = $key.'='.urlencode($value);
            }
        }
        $queryString = implode('&', $queryParts);

        $response = Http::withToken($this->token)
            ->acceptJson()
            ->timeout(30)
            ->get(rtrim($this->baseUrl, '/').'/leads?'.$queryString);

        if ($response->failed()) {
            throw new \Exception('Bayut API error: '.$response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Import a single inbound Bayut lead into our CRM as an enquiry.
     *
     * Mirrors {@see PropertyFinderService::importLead()}: de-dupes on contact + listing (or
     * on contact + agent for a profile lead, which carries no listing), creates the
     * contact/agent when missing, and opens the enquiry even when the listing isn't in our
     * system. Safe to run without an auth context (cron).
     *
     * @param  array  $lead  A Bayut lead payload with `lead`, `agent_details`,
     *                       `listing_details` and `inquirer_details` sections.
     * @return array{status: string, enquiryId?: int, reason?: string}
     */
    public function importLead(array $lead): array
    {
        // 1. Pull the lead contact (the client) from inquirer_details.
        $inquirer = $lead['inquirer_details'] ?? [];
        // Bayut sends E.164 with a leading + ("+971559153422"); normalise so the contact
        // lookup below matches the same number stored in any other shape.
        $phone = ! empty($inquirer['cell'])
            ? (normalizePhoneE164((string) $inquirer['cell']) ?: (string) $inquirer['cell'])
            : null;
        $email = ! empty($inquirer['email']) ? (string) $inquirer['email'] : null;

        if (! $phone && ! $email) {
            return ['status' => 'skipped', 'reason' => 'no contact phone or email given'];
        }

        // 2. Match the Bayut listing reference to one of our properties — optional.
        $reference = (string) ($lead['listing_details']['reference'] ?? '');
        $property = $reference !== ''
            ? Property::where('referenceNumber', $reference)->first()
            : null;

        $listingType = $property?->listingType;
        $contactType = $listingType === 'rent'
            ? Constants::CONTACT_TYPE_TENANT
            : Constants::CONTACT_TYPE_BUYER;

        // 3. Resolve the owning agent. Only ever route a lead to an ACTIVE agent:
        //    match agent_details.email to an active user; else fall back to the
        //    matched listing's agent if it's still active; else the default house
        //    agent. Without the active checks a lead can land on an inactive
        //    ex-agent (e.g. a listing/profile still carrying a departed agent).
        $agentEmail = $lead['agent_details']['email'] ?? null;
        $agentId = $agentEmail
            ? User::where('email', $agentEmail)
                ->where('status', Constants::USER_STATUS_ACTIVE)
                ->value('id')
            : null;

        if (! $agentId
            && $property?->agentId
            && User::whereKey($property->agentId)
                ->where('status', Constants::USER_STATUS_ACTIVE)
                ->exists()
        ) {
            $agentId = $property->agentId;
        }

        $agentId = $agentId ?? env('CRM_DEFAULT_AGENT_ID', 5); // Final fallback: the default house agent.

        // 4. Find the contact — phone first, email only as a fallback. Matching on the
        //    last 9 digits finds the record however its number is written; an
        //    exact-only lookup misses it and silently creates a duplicate, which then
        //    defeats the enquiry de-dup below.
        $contact = null;
        if ($phone) {
            $contact = Contact::resolveMasterByPhone($phone);
        }
        if (! $contact && $email) {
            $contact = Contact::where('email', $email)->first()?->masterOrSelf();
        }

        // 5. De-dup: skip only when this contact already has a live enquiry from the same
        //    origin that is LESS than 24 hours old.
        //
        //    - A listing lead compares on the listing it came in on. A PROFILE lead
        //      carries none, so it compares on the agent it was routed to instead. That
        //      still makes a re-pull a no-op — without it the importer, which re-reads its
        //      whole window every five minutes, would recreate the same profile lead on
        //      every run for two hours — while leaving room for a second, genuine enquiry
        //      the same client sent to a DIFFERENT broker's profile. Scoped to Bayut so an
        //      unrelated website or portal enquiry can never absorb one.
        //    - The 24-hour window is what makes a re-pull a no-op (the importer re-reads
        //      its whole window every run) without blocking a genuine re-enquiry later.
        //      The previous unbounded check blocked that forever.
        //    - Soft-deleted enquiries are ignored: a deleted enquiry is meant to be gone,
        //      so the next lead from that client rebuilds it instead of silently skipping.
        if ($contact) {
            $existing = Enquiry::where('contactId', $contact->id)
                ->where('created_at', '>=', now()->subDay())
                ->when(
                    $property,
                    fn ($q) => $q->where('listingId', $property->id),
                    fn ($q) => $q->whereNull('listingId')
                        ->where('agentId', $agentId)
                        ->where('leadSource', Constants::LEAD_SOURCE_BAYUT),
                )
                ->latest('id')
                ->first();

            if ($existing) {
                Log::channel('enquiry')->info('Enquiry skipped because it already exists.', [
                    'source' => 'bayut',
                    'channel' => $lead['lead']['channel'] ?? null,
                    'referenceNo' => $existing->referenceNo,
                ]);

                return ['status' => 'skipped', 'reason' => 'already exists', 'enquiryId' => $existing->id];
            }
        }

        $name = trim((string) ($inquirer['name'] ?? ''));

        if (empty($name)) {
            $name = $phone ? "Contact - {$phone}" : 'Contact Bayut';
        }

        // 6. Create the contact if it's new.
        if (! $contact) {
            $contact = Contact::create([
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'contactType' => [$contactType],
                'contactOwner' => $agentId,
                'source' => Constants::LEAD_SOURCE_BAYUT,
                'createdBy' => $agentId,
            ]);
        } else {
            // Merge — keep any existing roles this contact already has.
            $contact->addContactTypes($contactType);
        }

        // 7. Map the Bayut channel to our technicalSource key (whatsApp | email | call).
        $channel = strtolower((string) ($lead['lead']['channel'] ?? ''));
        $technicalSource = match ($channel) {
            'whatsapp' => 'whatsApp',
            'email' => 'email',
            'phone', 'call' => 'call',
            default => null,
        };

        $referenceNo = generateEnquiryReferenceNo();

        // 8. Open the enquiry. Reference number comes from the centralized generator.
        $enquiry = Enquiry::create([
            'agentId' => $agentId,
            'contactId' => $contact->id,
            'listingId' => $property?->id,
            'referenceNo' => $referenceNo,
            'phone' => $phone,
            'status' => Constants::ENQUIRY_NEW_LEAD,
            'leadSource' => Constants::LEAD_SOURCE_BAYUT,
            'technicalSource' => $technicalSource,
            'listingType' => $listingType,
            'enquiryType' => $contactType,
            'createdBy' => $agentId,
        ]);

        Log::channel('enquiry')->info('New Enquiry created.', [
            'agent_id' => $agentId,
            'source' => 'bayut',
            'channel' => $lead['lead']['channel'] ?? null,
            'referenceNo' => $referenceNo,
        ]);

        return ['status' => 'created', 'enquiryId' => $enquiry->id];
    }
}
