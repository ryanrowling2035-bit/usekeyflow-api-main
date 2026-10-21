<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\Contact;
use App\Models\Enquiry;
use App\Models\PortalSetting;
use App\Models\Property;
use App\Models\PropertyCommunity;
use App\Models\PropertyLocation;
use App\Models\PropertyMeta;
use App\Models\PropertySubCommunity;
use App\Models\PropertyTower;
use App\Models\Role;
use App\Models\User;
use App\Models\UserMeta;
use App\Services\Concerns\FiltersPortalPayload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PropertyFinderService
{
    use FiltersPortalPayload;

    /**
     * Maps a changed CRM field/meta key (or the 'media' sentinel) to the Property
     * Finder payload top-level keys it feeds. Drives partial updates — see
     * {@see FiltersPortalPayload::partialPayload()}.
     */
    protected const UPDATE_FIELD_MAP = [
        'referenceNumber' => ['reference'],
        'title' => ['title'],
        'description' => ['description'],
        'sizeArea' => ['size'],
        'plotSize' => ['plotSize'],
        'bedrooms' => ['bedrooms'],
        'bathrooms' => ['bathrooms'],
        'parkingSpaces' => ['parkingSlots', 'hasParkingOnSite'],
        'pfPropertyType' => ['category', 'type', 'bathrooms', 'hasParkingOnSite'],
        'pfCity' => ['uaeEmirate', 'location', 'compliance'],
        'pfBuilding' => ['location'],
        'pfSubCommunity' => ['location'],
        'pfCommunity' => ['location'],
        'listingType' => ['price', 'projectStatus', 'compliance'],
        'salePrice' => ['price'],
        'price' => ['price'],
        'annualRent' => ['price'],
        'pfPriceFrequency' => ['price'],
        'pfCheques' => ['price'],
        'completionStatus' => ['projectStatus'],
        'reraPermitNo' => ['compliance'],
        'pfAmenities' => ['amenities'],
        'agentId' => ['createdBy', 'assignedTo', 'compliance'],

        // Meta keys (1:1 request → metaKey)
        'pfFurnishingType' => ['furnishingType'],
        'pfBuiltUpArea' => ['builtUpArea'],
        'pfProjectStatus' => ['projectStatus'],
        'pfUnitNumber' => ['unitNumber'],
        'pfDeveloper' => ['developer'],
        'pfNumberOfFloors' => ['numberOfFloors'],
        'pfAvailableFrom' => ['availableFrom'],
        'pfDownPayment' => ['price'],
        'pfPaymentMethods' => ['price'],
        'pfPriceOnRequest' => ['price'],

        // Media sentinel
        'media' => ['media'],
    ];

    protected ?string $baseUrl;

    protected ?string $apiKey;

    protected ?string $apiSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('PROPERTY_FINDER_API_URL', 'https://atlas.propertyfinder.com/v1'), '/');
        $this->apiKey = env('PROPERTY_FINDER_API_KEY');
        $this->apiSecret = env('PROPERTY_FINDER_API_SECRET');
    }

    public function ARCHIVE_getPFLocations(array $params = []): array
    {
        $search = $params['search'] ?? $params['title'] ?? null;

        if (! $search) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'search parameter is required',
            ];
        }

        $nodes = array_values(array_filter(array_map('trim', explode('>', $search)), fn ($n) => $n !== ''));

        if (empty($nodes)) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'search parameter is required',
            ];
        }

        $result = $this->searchLocations(['search' => end($nodes)]);

        if ($result['success'] && empty($result['data']) && count($nodes) >= 2) {
            $result = $this->searchLocations(['search' => $nodes[count($nodes) - 2]]);
        }

        return $result;
    }

    public function searchLocations(array $params = []): array
    {
        $search = $params['search'] ?? $params['title'] ?? null;

        if (! $search) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'search parameter is required',
            ];
        }

        $token = $this->getAccessToken();

        if (! $token) {
            return [
                'success' => false,
                'status' => 500,
                'error' => 'Failed to obtain Property Finder access token',
            ];
        }

        $url = "{$this->baseUrl}/locations";

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->get($url, ['search' => $search]);

        if ($response->status() === 401) {
            $token = $this->getAccessToken(true);

            if ($token) {
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->asJson()
                    ->get($url, ['search' => $search]);
            }
        }

        if (! $response->successful()) {
            return [
                'success' => false,
                'status' => $response->status(),
                'error' => 'Failed to fetch locations from Property Finder',
                'details' => $response->body(),
            ];
        }

        $data = $response->json();

        $this->storeLocations($data['data'] ?? []);

        return [
            'success' => true,
            'status' => 200,
            // transformLocations() shapes a single PF location; searchLocations
            // returns a list, so map each result (mirrors BayutService).
            'data' => array_map(
                fn ($location) => $this->transformLocations($location),
                $data['data'] ?? []
            ),
        ];
    }

    protected function getAccessToken(bool $forceRefresh = false): ?string
    {
        if (! $this->apiKey || ! $this->apiSecret) {
            Log::error('[PropertyFinder API] Missing API credentials');

            return null;
        }

        $response = Http::acceptJson()
            ->asJson()
            ->post("{$this->baseUrl}/auth/token", [
                'apiKey' => $this->apiKey,
                'apiSecret' => $this->apiSecret,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $token = $response->json('accessToken')
            ?? $response->json('access_token')
            ?? $response->json('token');

        if (! $token) {
            Log::error('[PropertyFinder API] Token missing from response', ['body' => $response->json()]);

            return null;
        }

        return $token;
    }

    /**
     * Search PF locations by Bayut location breadcrumb string (top-down approach).
     *
     * @param  array  $params  ['search' => 'Dubai > Jumeirah Lake Towers (JLT) > JLT Cluster L > Me Do Re']
     */
    public function getPFLocations(array $params = []): array
    {
        $search = $params['search'] ?? $params['title'] ?? null;

        if (! $search) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'search parameter is required',
            ];
        }

        // Step 1: Parse Bayut location string into parts
        $nodes = array_values(array_filter(array_map('trim', explode('>', $search)), fn ($n) => $n !== ''));

        if (empty($nodes)) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'search parameter is required',
            ];
        }

        // Step 2: Clean names — strip parenthetical text like "(JLT)"
        $cleanedNodes = array_map(function ($node) {
            return trim(preg_replace('/\s*\([^)]*\)\s*/', '', $node));
        }, $nodes);

        // City is parts[0] — used as starting parent
        $parentChain = $cleanedNodes[0];
        $deepestMatch = null;

        // Step 3: Top-down search — iterate from community onwards
        for ($i = 1; $i < count($cleanedNodes); $i++) {
            // Try full cleaned name first — preserves discriminators like "L" in "JLT Cluster L"
            $result = $this->searchLocationsWithParent($cleanedNodes[$i], $parentChain);

            // Fall back to first 2 words if full name returns nothing (handles Bayut suffixes PF doesn't have)
            if (! $result['success'] || empty($result['data'])) {
                $words = explode(' ', $cleanedNodes[$i]);
                if (count($words) > 2) {
                    $shortSearch = implode(' ', array_slice($words, 0, 2));
                    $result = $this->searchLocationsWithParent($shortSearch, $parentChain);
                }
            }

            if ($result['success'] && ! empty($result['data'])) {
                $match = $result['data'][0];
                $deepestMatch = $match;

                // Use PF's exact name for the next parent chain
                $pfName = $match['name'] ?? '';
                if ($pfName) {
                    $parentChain .= ', '.$pfName;
                }
            }
            // If no match, skip this level — continue with same parentChain
        }

        if (! $deepestMatch) {
            return [
                'success' => true,
                'status' => 200,
                'message' => 'No matching PF location found',
                'data' => [],
            ];
        }

        // Step 4: Build response using PF's tree
        return [
            'success' => true,
            'status' => 200,
            'message' => 'PF location found',
            'data' => [$this->transformLocations($deepestMatch)],
        ];
    }

    /**
     * Search PF locations with filter[parent] for disambiguation.
     */
    protected function searchLocationsWithParent(string $search, string $parent): array
    {
        $token = $this->getAccessToken();

        if (! $token) {
            return [
                'success' => false,
                'status' => 500,
                'error' => 'Failed to obtain Property Finder access token',
            ];
        }

        $url = "{$this->baseUrl}/locations";

        $queryParams = [
            'search' => $search,
            'filter[parent]' => $parent,
            'perPage' => 5,
        ];

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->get($url, $queryParams);

        // Retry on 401
        if ($response->status() === 401) {
            $token = $this->getAccessToken(true);

            if ($token) {
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->asJson()
                    ->get($url, $queryParams);
            }
        }

        if (! $response->successful()) {
            return [
                'success' => false,
                'status' => $response->status(),
                'error' => 'Failed to fetch locations from Property Finder',
            ];
        }

        $data = $response->json('data', []);

        $this->storeLocations($data);

        return [
            'success' => true,
            'status' => 200,
            'data' => $data,
        ];
    }

    /**
     * Transform a single PF location into response format with tree.
     */
    protected function transformLocations(array $location): array
    {
        $tree = $location['tree'] ?? [];
        $responseTree = [];
        $locationParts = [];

        foreach ($tree as $node) {
            $type = strtolower($node['type'] ?? '');
            $entry = ['id' => $node['id'], 'name' => $node['name']];
            $locationParts[] = $node['name'];

            match ($type) {
                'city' => $responseTree['city'] = $entry,
                'community' => $responseTree['community'] = $entry,
                default => ! isset($responseTree['subcommunity'])
                    ? $responseTree['subcommunity'] = $entry
                    : $responseTree['tower'] = $entry,
            };
        }

        return [
            'id' => $location['id'] ?? null,
            'location' => implode(' > ', $locationParts),
            'latitude' => $location['coordinates']['lat'] ?? null,
            'longitude' => $location['coordinates']['lng'] ?? null,
            'tree' => $responseTree,
        ];
    }

    protected function storeLocations(array $locations): void
    {
        if (empty($locations)) {
            return;
        }

        $communities = [];
        $subCommunities = [];
        $towers = [];
        $now = now();

        // When the same ID appears as both a parent (from `tree`, no coords) and a
        // leaf (with coords) in the same batch, keep the coord-bearing version.
        $mergeRow = static function (array &$bucket, array $row, string $idKey): void {
            $id = $row[$idKey];
            $existing = $bucket[$id] ?? null;

            if (! $existing || ($row['latitude'] !== null && $existing['latitude'] === null)) {
                $bucket[$id] = $row;
            }
        };

        foreach ($locations as $location) {
            $type = strtoupper($location['type'] ?? '');
            $tree = $location['tree'] ?? [];

            $cityNode = null;
            $communityNode = null;
            $subCommunityNode = null;
            $towerNode = null;

            foreach ($tree as $node) {
                switch (strtoupper($node['type'] ?? '')) {
                    case 'CITY':
                        $cityNode = $node;
                        break;
                    case 'COMMUNITY':
                        $communityNode = $node;
                        break;
                    case 'SUBCOMMUNITY':
                        $subCommunityNode = $node;
                        break;
                    case 'TOWER':
                    case 'BUILDINGTOWER':
                        $towerNode = $node;
                        break;
                }
            }

            if ($type === 'COMMUNITY' && empty($communityNode)) {
                $communityNode = $location;
            } elseif ($type === 'SUBCOMMUNITY' && empty($subCommunityNode)) {
                $subCommunityNode = $location;
            } elseif (($type === 'TOWER' || $type === 'BUILDINGTOWER') && empty($towerNode)) {
                $towerNode = $location;
            }

            if (! empty($communityNode['id'])) {
                $mergeRow($communities, [
                    'communityId' => $communityNode['id'],
                    'cityId' => $cityNode['id'] ?? null,
                    'name' => $communityNode['name'] ?? null,
                    'type' => 'community',
                    'communityCode' => $communityNode['communityCode'] ?? null,
                    'latitude' => $communityNode['coordinates']['lat'] ?? null,
                    'longitude' => $communityNode['coordinates']['lng'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], 'communityId');
            }

            if (! empty($subCommunityNode['id'])) {
                $mergeRow($subCommunities, [
                    'propertySubCommunityId' => $subCommunityNode['id'],
                    'communityId' => $communityNode['id'] ?? null,
                    'name' => $subCommunityNode['name'] ?? null,
                    'type' => 'subCommunity',
                    'latitude' => $subCommunityNode['coordinates']['lat'] ?? null,
                    'longitude' => $subCommunityNode['coordinates']['lng'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], 'propertySubCommunityId');
            }

            if (! empty($towerNode['id'])) {
                $mergeRow($towers, [
                    'propertyTowerId' => $towerNode['id'],
                    'communityId' => $communityNode['id'] ?? null,
                    'subCommunityId' => $subCommunityNode['id'] ?? null,
                    'name' => $towerNode['name'] ?? null,
                    'type' => 'tower',
                    'latitude' => $towerNode['coordinates']['lat'] ?? null,
                    'longitude' => $towerNode['coordinates']['lng'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], 'propertyTowerId');
            }
        }

        DB::transaction(function () use ($communities, $subCommunities, $towers) {
            $this->writeLocationRows(
                PropertyCommunity::class,
                array_values($communities),
                'communityId',
                ['cityId', 'name', 'type', 'communityCode', 'latitude', 'longitude', 'updated_at']
            );
            $this->writeLocationRows(
                PropertySubCommunity::class,
                array_values($subCommunities),
                'propertySubCommunityId',
                ['communityId', 'name', 'type', 'latitude', 'longitude', 'updated_at']
            );
            $this->writeLocationRows(
                PropertyTower::class,
                array_values($towers),
                'propertyTowerId',
                ['communityId', 'subCommunityId', 'name', 'type', 'latitude', 'longitude', 'updated_at']
            );
        });
    }

    /**
     * Rows that have coords go through upsert so existing null-coord rows get
     * backfilled. Rows without coords go through insertOrIgnore so a parent stub
     * never overwrites real coords already stored.
     */
    protected function writeLocationRows(string $modelClass, array $rows, string $uniqueKey, array $updateColumns): void
    {
        if (empty($rows)) {
            return;
        }

        $withCoords = [];
        $withoutCoords = [];

        foreach ($rows as $row) {
            if ($row['latitude'] !== null && $row['longitude'] !== null) {
                $withCoords[] = $row;
            } else {
                $withoutCoords[] = $row;
            }
        }

        if (! empty($withoutCoords)) {
            $modelClass::insertOrIgnore($withoutCoords);
        }
        if (! empty($withCoords)) {
            $modelClass::upsert($withCoords, [$uniqueKey], $updateColumns);
        }
    }

    // ==================== PUBLISH FLOW ====================

    /**
     * Publish a property to Property Finder via the 2-step API chain:
     *   1. POST /listings              → creates draft, returns PF listing id
     *   2. POST /listings/{id}/publish → publishes to Property Finder
     *
     * Options:
     *   - dryRun: true → returns the built payload without hitting PF (preview)
     */
    public function publishProperty(Property $property, PortalSetting $portalSetting, array $options = []): array
    {
        $this->apiKey = $portalSetting->pfApiKey;
        $this->apiSecret = $portalSetting->pfApiSecret;

        Log::error('[PropertyFinder API] publishProperty start', [
            'crm_property_id' => $property->id,
            'account' => $portalSetting->slug,
            'branch' => $portalSetting->branchName,
            'dryRun' => ! empty($options['dryRun']),
            'hasCreds' => (bool) ($this->apiKey && $this->apiSecret),
        ]);

        $token = ($this->apiKey && $this->apiSecret) ? $this->getAccessToken() : null;

        $payload = $this->buildPayload($property, $token);

        if (isset($payload['error'])) {
            Log::error('[PropertyFinder API] publishProperty payload build failed', [
                'crm_property_id' => $property->id,
                'account' => $portalSetting->slug,
                'error' => $payload['error'],
            ]);

            return ['success' => false, 'status' => 422, 'error' => $payload['error']];
        }

        Log::error('[PropertyFinder API] publishProperty payload built', [
            'crm_property_id' => $property->id,
            'account' => $portalSetting->slug,
            'payloadKeys' => array_keys($payload),
            'payload' => $payload,
        ]);

        if (! empty($options['dryRun'])) {
            Log::error('[PropertyFinder API] publishProperty dry run — returning payload without API call', [
                'crm_property_id' => $property->id,
            ]);

            return ['success' => true, 'status' => 200, 'payload' => $payload];
        }

        if (! $this->apiKey || ! $this->apiSecret) {
            return ['success' => false, 'status' => 422, 'error' => "Portal account '{$portalSetting->slug}' is missing Property Finder API credentials"];
        }

        if (! $token) {
            return ['success' => false, 'status' => 500, 'error' => 'Failed to obtain Property Finder access token'];
        }

        // Step 1: Create listing (draft)
        Log::error('[PropertyFinder API] publishProperty POST /listings (create draft)', [
            'crm_property_id' => $property->id,
            'reference' => $payload['reference'] ?? null,
        ]);

        $createResp = $this->http($token)->post('/listings', $payload);

        if ($createResp->status() === 401) {
            Log::error('[PropertyFinder API] publishProperty create got 401 — refreshing token and retrying', [
                'crm_property_id' => $property->id,
            ]);
            $token = $this->getAccessToken(true);
            if ($token) {
                $createResp = $this->http($token)->post('/listings', $payload);
            }
        }

        if (! $createResp->successful()) {
            Log::error('[PropertyFinder API] publishProperty create failed', [
                'crm_property_id' => $property->id,
                'status' => $createResp->status(),
                'body' => $createResp->body(),
            ]);

            // PF returns 422 ReferenceInUseByAnotherListingOfClient when a listing with this
            // reference already exists for the client — e.g. a draft/archived listing the
            // pre-publish reference lookup missed, or an orphan left after a stale id was
            // cleared. Rather than surface a duplicate-create error, reconcile by reference
            // and adopt the existing listing into meta (fetchListingById syncs its id + state).
            if ($this->isReferenceInUse($createResp)
                && ($existingId = $this->findExternalIdByReference($property, $portalSetting))) {
                Log::warning('[PropertyFinder API] create rejected as duplicate reference — adopting existing PF listing', [
                    'crm_property_id' => $property->id,
                    'reference' => $payload['reference'] ?? null,
                    'pf_listing_id' => $existingId,
                    'account' => $portalSetting->slug,
                ]);

                $adopted = $this->fetchListingById($property, (string) $existingId, $portalSetting);

                if ($adopted['success'] ?? false) {
                    return [
                        'success' => true,
                        'status' => 200,
                        'pfListingId' => (string) $existingId,
                        'state' => $adopted['listingData']['status'] ?? null,
                        'message' => 'Property already existed on Property Finder — adopted existing listing',
                    ];
                }
            }

            return $this->pfError('create', $createResp);
        }

        Log::error('[PropertyFinder API] Listing Created', $createResp->json());

        $pfListingId = $createResp->json('data.id') ?? $createResp->json('id');

        if (! $pfListingId) {
            Log::error('[PropertyFinder API] Create succeeded but response missing listing id', [
                'body' => $createResp->body(),
            ]);

            return ['success' => false, 'status' => 502, 'error' => 'PropertyFinder create response missing listing id'];
        }

        // Publishing (flip draft → live) is intentionally NOT done here — the
        // listing is left in 'draft'. Publishing is a separate, explicit step:
        // POST property/publish-draft/property-finder → publishDraftListing().
        $listingData = [
            'account' => $portalSetting->slug,
            'externalListingId' => (string) $pfListingId,
            'status' => 'draft',
            'portalStatus' => 'draft',
        ];

        $this->saveListingData($property, $listingData, merge: false);

        logPropertyHistory([
            'propertyId' => $property->id,
            'field' => 'drafted_on_property_finder',
            'newValue' => json_encode($listingData + [
                'message' => "{$portalSetting->branchName} — listing ID {$pfListingId}",
            ]),
        ]);

        Log::error('[PropertyFinder API] Listing draft created', [
            'crm_property_id' => $property->id,
            'pf_listing_id' => $pfListingId,
            'account' => $portalSetting->slug,
        ]);

        return [
            'success' => true,
            'status' => 200,
            'pfListingId' => $pfListingId,
            'state' => 'draft',
            'message' => 'Property draft created on Property Finder',
        ];
    }

    /**
     * Publish a Property Finder draft (created by {@see publishProperty()}) by
     * flipping it from draft → live. Updates the stored listing meta status and
     * logs property history on success. This is the explicit second step of the
     * two-step publish flow — call it once the listing exists in 'draft'.
     */
    public function publishDraftListing(Property $property, PortalSetting $portalSetting, string $externalListingId, array $options = []): array
    {
        $this->apiKey = $portalSetting->pfApiKey;
        $this->apiSecret = $portalSetting->pfApiSecret;

        if (! $this->apiKey || ! $this->apiSecret) {
            return ['success' => false, 'status' => 422, 'error' => "Portal account '{$portalSetting->slug}' is missing Property Finder API credentials"];
        }

        $token = $this->getAccessToken();
        if (! $token) {
            return ['success' => false, 'status' => 500, 'error' => 'Failed to obtain Property Finder access token'];
        }

        $publishResp = $this->publishListing($token, $externalListingId);
        Log::error('[PropertyFinder API] Listing Published', $publishResp->json());

        // PF leaves the listing in draft with a "check failed" reason when a standalone type
        // is published at a unit-only location, or a Primary sale type at a non-primary-project
        // location. Re-send the payload with PF's expected type / sale type, then publish again.
        if (($retryResp = $this->resendWithPfCorrections($property, $token, $externalListingId, $publishResp)) && $retryResp->successful()) {
            $publishResp = $this->publishListing($token, $externalListingId);
            Log::error('[PropertyFinder API] Listing re-published after PF correction', $publishResp->json());
        }

        // PF returns HTTP 200 for /publish even when it leaves the listing in draft with the
        // blocking reason(s) in state.reasons — judge "live" from the body, not the HTTP status.
        if (! $this->publishWentLive($publishResp)) {
            // Price rejected ("Price check failed. Expected price within ±N AED of X") — the
            // agent's price does not match the registered permit price, so it can never
            // publish as-is. Rather than leave the draft stuck, re-send with the price PF
            // accepts so the listing goes LIVE, and surface that the entered price wasn't used.
            // (Same handling as the price-amendment path — see updateViaDraftCycle().)
            if (($expectedPrice = $this->rejectedPriceExpected($publishResp)) !== null) {
                $payload = $this->buildPayload($property, $token);

                if (! isset($payload['error'])) {
                    return $this->restoreToAcceptedPrice(
                        $property, $portalSetting, $token, $externalListingId, $payload, $expectedPrice, $publishResp
                    );
                }
            }

            // Any other blocking reason (type/compliance/…) — report it with PF's reason
            // instead of recording a drafted listing as published.
            return $this->pfError('listing publish', $publishResp, $externalListingId);
        }

        // Publish response: { "state": { "type", "stage", "reasons" }, "id" }.
        // status = state.stage, portalStatus = state.type (mirrors fetchListingById).
        $listingMetaData = [
            'status' => $publishResp->json('state.stage'),
            'portalStatus' => $publishResp->json('state.type'),
        ];

        $this->saveListingData($property, $listingMetaData);

        logPropertyHistory([
            'propertyId' => $property->id,
            'field' => 'published_to_property_finder',
            'newValue' => json_encode($listingMetaData + [
                'message' => "{$portalSetting->branchName} — listing ID {$externalListingId}",
            ]),
        ]);

        Log::error('[PropertyFinder API] Listing published', [
            'crm_property_id' => $property->id,
            'pf_listing_id' => $externalListingId,
            'account' => $portalSetting->slug,
        ]);

        return [
            'success' => true,
            'status' => 200,
            'pfListingId' => $externalListingId,
            'state' => $publishResp->json('state.stage'),
            'message' => 'Property published to Property Finder',
        ];
    }

    /**
     * Flip a PF listing from draft to live via POST /listings/{id}/publish.
     * Retries once on a 401 by force-refreshing the access token. Returns the
     * raw HTTP response so the caller can branch on success / build pfError.
     */
    protected function publishListing(string $token, $pfListingId)
    {
        $resp = $this->http($token)->post("/listings/{$pfListingId}/publish");

        if ($resp->status() === 401) {
            $token = $this->getAccessToken(true);
            if ($token) {
                $resp = $this->http($token)->post("/listings/{$pfListingId}/publish");
            }
        }

        return $resp;
    }

    /**
     * Push CRM updates to an already-published Property Finder listing. The publish
     * step is intentionally skipped — the listing was already published at create time.
     *
     * $changedFields is used only to decide whether anything portal-relevant
     * changed (skip the call if not); the full payload is always PUT, since
     * Property Finder does not support PATCH.
     */
    public function updateProperty(Property $property, PortalSetting $portalSetting, string $externalListingId, array $changedFields = []): array
    {
        $this->apiKey = $portalSetting->pfApiKey;
        $this->apiSecret = $portalSetting->pfApiSecret;

        if (! $this->apiKey || ! $this->apiSecret) {
            Log::error('[PropertyFinder API] updateProperty missing credentials', [
                'crm_property_id' => $property->id,
                'account' => $portalSetting->slug,
            ]);

            return ['success' => false, 'status' => 422, 'error' => "Portal account '{$portalSetting->slug}' is missing Property Finder API credentials"];
        }

        $token = $this->getAccessToken();
        if (! $token) {
            Log::error('[PropertyFinder API] updateProperty failed to obtain access token', [
                'crm_property_id' => $property->id,
                'pf_listing_id' => $externalListingId,
            ]);

            return ['success' => false, 'status' => 500, 'error' => 'Failed to obtain Property Finder access token'];
        }

        $payload = $this->buildPayload($property, $token);

        if (isset($payload['error'])) {
            Log::error('[PropertyFinder API] updateProperty payload build failed', [
                'crm_property_id' => $property->id,
                'pf_listing_id' => $externalListingId,
                'error' => $payload['error'],
            ]);

            return ['success' => false, 'status' => 422, 'error' => $payload['error']];
        }

        // Property Finder's PUT /listings/{id} is a full replace that re-validates
        // the whole schema — a partial body fails (400 "category/type required")
        // and would wipe omitted fields. So the partial filter is used ONLY to
        // decide whether anything portal-relevant changed; skip the call if not.
        if (empty($this->partialPayload($payload, $changedFields))) {
            Log::error('[PropertyFinder API] Update skipped — no portal-relevant fields changed', [
                'crm_property_id' => $property->id,
                'pf_listing_id' => $externalListingId,
                'changedFields' => $changedFields,
            ]);

            return ['success' => true, 'status' => 200, 'skipped' => true, 'message' => 'No portal-relevant fields changed'];
        }

        Log::error('[PropertyFinder API] updateProperty sending request', [
            'crm_property_id' => $property->id,
            'pf_listing_id' => $externalListingId,
            'method' => 'PUT',
            'endpoint' => "/listings/{$externalListingId}",
            'changedFields' => $changedFields,

            // The body as sent, with the two fields that swamp the line cut down:
            // description runs to ~1.3k characters and the image list to 20+ URLs.
            // Left whole they push everything after them (location, price, compliance)
            // past the point where the line gets truncated when it is read back.
            'sentPayload' => array_replace($payload, array_filter([
                'media' => isset($payload['media'])
                    ? ['imageCount' => count($payload['media']['images'] ?? [])]
                    : null,
                'description' => isset($payload['description']['en'])
                    ? ['en' => Str::limit((string) $payload['description']['en'], 120)]
                    : null,
            ])),
        ]);

        // PF does NOT allow a reference-number or price change on a LIVE listing — those
        // two edits are rejected unless the listing is in draft. So for reference/price
        // the listing must be cycled: unpublish (draft) → PUT → publish. Every other edit
        // (beds, title, description, …) PF applies in place with a plain PUT. A price
        // change can arrive as any of salePrice / annualRent / price / pfPriceFrequency /
        // pfCheques / etc., all of which feed the 'price' payload key, so detect it by
        // what the change touches — not a single hard-coded field name.
        $referenceChanged = in_array('referenceNumber', $changedFields, true) && ! empty($property->referenceNumber);
        $priceChanged = $this->changedFieldsTouch($changedFields, 'price');

        if ($referenceChanged || $priceChanged) {
            // Draft → update → publish, guarded so a failure at any step restores the
            // listing to live instead of stranding it in draft.
            $result = $this->updateViaDraftCycle($property, $portalSetting, $token, $externalListingId, $payload);

            // A failed cycle, or a price-adjusted restore (which already logged its own
            // history + stored the reason), returns directly — don't fall through to the
            // generic "updated" history log.
            if (! ($result['success'] ?? false)) {
                // Failed even after http()'s timeout retries — record it on the timeline.
                logPropertyHistory([
                    'propertyId' => $property->id,
                    'field' => 'pf_update_failed',
                    'newValue' => json_encode([
                        'account' => $portalSetting->slug,
                        'externalListingId' => $externalListingId,
                        'status' => $result['status'] ?? null,
                        'message' => "{$portalSetting->branchName} — listing ID {$externalListingId}: ".($result['error'] ?? 'Unknown error'),
                    ]),
                ]);

                return $result;
            }

            if (! empty($result['priceAdjusted'])) {
                return $result;
            }
        } else {
            // Non-price/reference edit — PF applies this in place on the live listing.
            $updateResp = $this->http($token)->put("/listings/{$externalListingId}", $payload);

            // PF rejects the stored standalone type (unit-only location) or the Primary sale type
            // (non-primary-project location); retry the update with PF's expected type / sale type
            // before surfacing the error.
            if (! $updateResp->successful()
                && ($retryResp = $this->resendWithPfCorrections($property, $token, $externalListingId, $updateResp))) {
                $updateResp = $retryResp;
            }

            if (! $updateResp->successful()) {
                Log::error('[PropertyFinder API] updateProperty request failed', [
                    'crm_property_id' => $property->id,
                    'pf_listing_id' => $externalListingId,
                    'method' => 'PUT',
                    'status' => $updateResp->status(),
                    'body' => $updateResp->body(),
                ]);

                // pfError() maps the HTTP status → agent-friendly message and flattens
                // PF's error array; no per-status switch needed here.
                $error = $this->pfError('update', $updateResp, $externalListingId);

                // Failed even after http()'s timeout retries — record it on the timeline.
                logPropertyHistory([
                    'propertyId' => $property->id,
                    'field' => 'pf_update_failed',
                    'newValue' => json_encode([
                        'account' => $portalSetting->slug,
                        'externalListingId' => $externalListingId,
                        'status' => $error['status'] ?? null,
                        'message' => "{$portalSetting->branchName} — listing ID {$externalListingId}: ".($error['error'] ?? 'Unknown error'),
                    ]),
                ]);

                return $error;
            }
        }

        logPropertyHistory([
            'propertyId' => $property->id,
            'field' => 'updated_on_property_finder',
            'newValue' => json_encode([
                'account' => $portalSetting->slug,
                'externalListingId' => $externalListingId,
                'message' => "{$portalSetting->branchName} — listing ID {$externalListingId}",
            ]),
        ]);

        Log::error('[PropertyFinder API] Listing updated', [
            'crm_property_id' => $property->id,
            'pf_listing_id' => $externalListingId,
            'account' => $portalSetting->slug,
        ]);

        return [
            'success' => true,
            'status' => 200,
            'pfListingId' => $externalListingId,
            'message' => 'Property updated on Property Finder',
        ];
    }

    /**
     * True when any of the given changed CRM fields feeds the given top-level PF
     * payload key (per {@see UPDATE_FIELD_MAP}). Lets the update flow ask "did the
     * price change?" without hard-coding a single field name — a sale/rent price can
     * arrive as salePrice, annualRent, price, pfPriceFrequency, pfCheques, etc., all
     * of which map to the 'price' payload key.
     */
    protected function changedFieldsTouch(array $changedFields, string $payloadKey): bool
    {
        foreach ($changedFields as $field) {
            if (in_array($payloadKey, static::UPDATE_FIELD_MAP[$field] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Update a PF listing whose reference number or price changed. PF rejects those two
     * edits on a LIVE listing, so the listing must be cycled through draft:
     *   1. unpublish (draft)   2. PUT the full payload   3. publish (re-live)
     *
     * Every step is guarded so a failure never strands the listing in draft:
     *   - unpublish 404s → the stored id is dead on PF; self-heal by re-creating.
     *   - unpublish otherwise fails → best-effort: log and continue, because the PUT
     *     below still needs the listing drafted. A listing already in draft (e.g. left
     *     there by an earlier failure) rejects the unpublish but must still be updated —
     *     and this path RE-LIVES it, self-healing listings stuck in draft.
     *   - PUT fails → the listing is in draft with its OLD (unchanged) data; re-publish
     *     to restore it live, then surface the error. The failed edit did not go live and
     *     the listing is not left in draft.
     *   - publish fails → the new data is saved but the listing is momentarily in draft;
     *     retry once. If it still fails, surface the error WITHOUT persisting a draft
     *     status locally, so the CRM keeps showing the listing live until PF resettles.
     *
     * Returns a result array ({success:true,…} on success, or a pfError() / publishProperty() array).
     */
    protected function updateViaDraftCycle(Property $property, PortalSetting $portalSetting, string $token, string $externalListingId, array $payload): array
    {
        Log::error('[PropertyFinder API] Price/reference changed — cycling listing through draft to update', [
            'crm_property_id' => $property->id,
            'pf_listing_id' => $externalListingId,
            'referenceNumber' => $property->referenceNumber,
        ]);

        // Step 1: unpublish (draft) — PF won't accept a reference/price change while live.
        $unpublishResp = $this->http($token)->post("/listings/{$externalListingId}/unpublish");
        if ($unpublishResp->status() === 401) {
            $token = $this->getAccessToken(true);
            if ($token) {
                $unpublishResp = $this->http($token)->post("/listings/{$externalListingId}/unpublish");
            }
        }

        // Stored id is gone on PF — drop it and re-create from scratch.
        if ($unpublishResp->status() === 404) {
            Log::warning('[PropertyFinder API] updateViaDraftCycle unpublish got 404 — listing missing on PF; clearing stale id and re-creating', [
                'crm_property_id' => $property->id,
                'pf_listing_id' => $externalListingId,
                'account' => $portalSetting->slug,
                'body' => $unpublishResp->body(),
            ]);

            $this->forgetListingId($property);

            return $this->recreateAndPublish($property, $portalSetting);
        }

        // Best-effort: an already-draft listing rejects the unpublish, but the PUT below
        // still needs it drafted — log and continue rather than bail.
        if (! $unpublishResp->successful()) {
            Log::warning('[PropertyFinder API] updateViaDraftCycle — unpublish did not succeed; continuing (listing may already be draft)', [
                'crm_property_id' => $property->id,
                'pf_listing_id' => $externalListingId,
                'status' => $unpublishResp->status(),
                'body' => $unpublishResp->body(),
            ]);
        }

        // The price PF holds right now, read before the PUT overwrites it. If PF then blocks
        // the publish on the price check without naming a price it would take, this is the
        // rollback target — it is the price the listing was live at, so PF accepts it.
        $previousPrice = $this->fetchListingPriceAmount($token, $externalListingId);

        // Give PF a moment to settle the unpublish before mutating the draft listing.
        sleep(3);

        // Step 2: PUT the full payload while the listing is drafted.
        $updateResp = $this->http($token)->put("/listings/{$externalListingId}", $payload);
        if ($updateResp->status() === 401) {
            $token = $this->getAccessToken(true);
            if ($token) {
                $updateResp = $this->http($token)->put("/listings/{$externalListingId}", $payload);
            }
        }

        // Type / sale-type rejection → retry the PUT with PF's expected values.
        if (! $updateResp->successful()
            && ($retryResp = $this->resendWithPfCorrections($property, $token, $externalListingId, $updateResp))) {
            $updateResp = $retryResp;
        }

        if (! $updateResp->successful()) {
            // The edit was rejected. The listing is in draft with its OLD data (the PUT
            // did not apply) — re-publish to put it back live so the failure doesn't
            // strand it, then surface the error.
            Log::error('[PropertyFinder API] updateViaDraftCycle — update PUT failed; restoring listing to live', [
                'crm_property_id' => $property->id,
                'pf_listing_id' => $externalListingId,
                'status' => $updateResp->status(),
                'body' => $updateResp->body(),
            ]);

            $this->restoreLive($token, $externalListingId);

            return $this->pfError('update', $updateResp, $externalListingId);
        }

        // Give PF a moment to settle the update before re-publishing.
        sleep(3);

        // Step 3: publish (re-live) so the new value goes live.
        $publishResp = $this->publishListing($token, $externalListingId);

        // Type / sale-type rejection at publish → corrected re-PUT then publish again.
        if (($retryResp = $this->resendWithPfCorrections($property, $token, $externalListingId, $publishResp)) && $retryResp->successful()) {
            $publishResp = $this->publishListing($token, $externalListingId);
        }

        // IMPORTANT: PF's /publish returns HTTP 200 even when it leaves the listing in
        // DRAFT with the blocking reason(s) in state.reasons — the failure does NOT surface
        // as an HTTP error. So decide on whether the listing actually went live from the
        // response body (publishWentLive), never from the HTTP status.
        if (! $this->publishWentLive($publishResp)) {
            // PF rejected the PRICE ("Price check failed …") — a permit-price mismatch that
            // retrying the same price can never clear. Roll the price back so the listing goes
            // back LIVE keeping every other edit, and surface why the new price didn't take.
            // Prefer the price PF named as acceptable; when it names none, fall back to the
            // price the listing was live at before this edit.
            if ($this->priceCheckFailed($publishResp)) {
                $expectedPrice = $this->rejectedPriceExpected($publishResp);
                $revertedToPrevious = $expectedPrice === null;

                if ($revertedToPrevious) {
                    $expectedPrice = $previousPrice;
                }

                if ($expectedPrice !== null) {
                    return $this->restoreToAcceptedPrice(
                        $property, $portalSetting, $token, $externalListingId, $payload,
                        (float) $expectedPrice, $publishResp, $revertedToPrevious
                    );
                }

                // Neither source yielded a price — the listing would be left in draft, so make
                // the reason visible instead of letting it disappear into the generic retry.
                Log::error('[PropertyFinder API] Price rejected but no restorable price found — cannot roll back', [
                    'crm_property_id' => $property->id,
                    'pf_listing_id' => $externalListingId,
                    'previousPrice' => $previousPrice,
                    'reasons' => $publishResp->json('state.reasons'),
                    'body' => $publishResp->body(),
                ]);
            }

            // Otherwise treat it as a transient miss and retry the publish once.
            Log::error('[PropertyFinder API] updateViaDraftCycle — listing did not go live after publish; retrying once', [
                'crm_property_id' => $property->id,
                'pf_listing_id' => $externalListingId,
                'status' => $publishResp->status(),
                'stage' => $publishResp->json('state.stage'),
                'reasons' => $publishResp->json('state.reasons'),
                'body' => $publishResp->body(),
            ]);

            sleep(3);
            $publishResp = $this->publishListing($token, $externalListingId);

            if (! $this->publishWentLive($publishResp)) {
                // Still not live. Do NOT persist a draft status — leave the stored state as
                // published so the CRM keeps showing the listing live; PF resettles on the
                // next successful sync / status webhook.
                Log::error('[PropertyFinder API] updateViaDraftCycle — listing still not live after retry; not persisting draft', [
                    'crm_property_id' => $property->id,
                    'pf_listing_id' => $externalListingId,
                    'status' => $publishResp->status(),
                    'stage' => $publishResp->json('state.stage'),
                    'reasons' => $publishResp->json('state.reasons'),
                    'body' => $publishResp->body(),
                ]);

                return $this->pfError('publish', $publishResp, $externalListingId);
            }
        }

        // Persist the fresh live state from the publish response so meta stays in sync.
        $this->saveListingData($property, [
            'status' => $publishResp->json('state.stage'),
            'portalStatus' => $publishResp->json('state.type'),
        ]);

        return [
            'success' => true,
            'status' => 200,
            'pfListingId' => $externalListingId,
        ];
    }

    /**
     * Whether a PF /publish response means the listing actually went LIVE.
     *
     * PF returns HTTP 200 for /publish even when it leaves the listing in draft with the
     * blocking reason(s) in state.reasons (price/type/sale-type checks, compliance, …) —
     * the rejection is NOT an HTTP error. So a publish only counts as live when the call
     * succeeded AND PF returned no blocking reasons. Callers must branch on this, not on
     * $response->successful(), or a drafted listing is silently recorded as published.
     */
    protected function publishWentLive($response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        return empty($response->json('state.reasons'));
    }

    /**
     * Best-effort re-publish of a listing that was drafted for an update which then
     * failed, so it goes back live with its existing data instead of being left in
     * draft. Retries once. The caller is already returning the original error; this only
     * tries to avoid stranding the listing offline, so its own outcome is logged, not returned.
     */
    protected function restoreLive(string $token, string $externalListingId): void
    {
        $resp = $this->publishListing($token, $externalListingId);

        if (! $resp->successful()) {
            sleep(3);
            $resp = $this->publishListing($token, $externalListingId);
        }

        if (! $resp->successful()) {
            Log::error('[PropertyFinder API] restoreLive — could not re-publish listing after a failed update', [
                'pf_listing_id' => $externalListingId,
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);
        }
    }

    /**
     * Re-create a listing whose stored PF id went stale (404 on update) AND publish it, so
     * it returns to LIVE rather than being left in the 'draft' state publishProperty()
     * stops at. Used by the update self-heal: a listing that was published before its id
     * went stale must come back published — an agent editing price/beds/photos must not
     * silently drop the portal listing to draft.
     */
    protected function recreateAndPublish(Property $property, PortalSetting $portalSetting): array
    {
        $created = $this->publishProperty($property, $portalSetting);

        $pfListingId = $created['pfListingId'] ?? null;
        if (! ($created['success'] ?? false) || ! $pfListingId) {
            return $created;
        }

        // publishProperty() leaves the fresh listing in draft — publish it so the
        // re-created listing goes live, matching the state it had before its id went stale.
        return $this->publishDraftListing($property, $portalSetting, (string) $pfListingId);
    }

    /**
     * PF rejected the advertised price ("Price check failed. Expected price within ±N AED
     * of X") — a permit-price mismatch that retrying the same price can never clear.
     * Re-send the FULL payload (every field the agent changed stays) with ONLY the price
     * swapped to the value PF named as acceptable, then publish — so the listing goes back
     * LIVE with all other edits applied and only the non-compliant price reverted. A clear
     * reason is stored so the agent sees their price did not take.
     *
     * Returns a failure result (the price change did not apply) even though the listing is
     * live, so the outcome is logged and surfaced rather than silently swallowed.
     */
    protected function restoreToAcceptedPrice(Property $property, PortalSetting $portalSetting, string $token, string $externalListingId, array $payload, float $expectedPrice, $rejectionResp, bool $revertedToPrevious = false): array
    {
        $attemptedPrice = $this->payloadPriceAmount($payload);

        Log::warning('[PropertyFinder API] Price rejected by PF — restoring listing to the accepted price', [
            'crm_property_id' => $property->id,
            'pf_listing_id' => $externalListingId,
            'attemptedPrice' => $attemptedPrice,
            'expectedPrice' => $expectedPrice,
            'source' => $revertedToPrevious ? 'previous live price' : 'price named by PF',
        ]);

        // Full payload (all fields) with only the price overridden to what PF accepts.
        $restoredPayload = $this->withPriceAmount($payload, $expectedPrice);

        $putResp = $this->http($token)->put("/listings/{$externalListingId}", $restoredPayload);
        if ($putResp->status() === 401) {
            $token = $this->getAccessToken(true);
            if ($token) {
                $putResp = $this->http($token)->put("/listings/{$externalListingId}", $restoredPayload);
            }
        }

        $publishResp = $putResp->successful()
            ? $this->publishListing($token, $externalListingId)
            : $putResp;

        // Give the restore one retry before accepting we could not re-live it. Judge "live"
        // from the body (publishWentLive), since PF returns 200 for a drafted publish too.
        if (! $this->publishWentLive($publishResp)) {
            sleep(3);
            $publishResp = $this->publishListing($token, $externalListingId);
        }

        $reason = $revertedToPrevious
            ? sprintf(
                'Property Finder rejected the entered price (AED %s), so the listing is live at its previous price (AED %s). The price must match the registered permit price — update the permit, then set the new price again.',
                $this->formatAmount($attemptedPrice),
                $this->formatAmount($expectedPrice)
            )
            : sprintf(
                'Property Finder rejected the entered price (AED %s). It must match the registered permit price (about AED %s), so the listing is live at AED %s — update the permit to change the price.',
                $this->formatAmount($attemptedPrice),
                $this->formatAmount($expectedPrice),
                $this->formatAmount($expectedPrice)
            );

        if (! $this->publishWentLive($publishResp)) {
            // Could not restore to live either. Do NOT persist a draft status; surface the
            // original price rejection so the agent is notified.
            Log::error('[PropertyFinder API] Price restore — could not re-publish at the accepted price; not persisting draft', [
                'crm_property_id' => $property->id,
                'pf_listing_id' => $externalListingId,
                'status' => $publishResp->status(),
                'reasons' => $publishResp->json('state.reasons'),
                'body' => $publishResp->body(),
            ]);

            return $this->pfError('publish', $rejectionResp, $externalListingId);
        }

        // Live again — all other edits applied, price reverted to the compliant value.
        // Persist the live state plus the reason so the agent sees the price was not taken.
        $this->saveListingData($property, [
            'status' => $publishResp->json('state.stage'),
            'portalStatus' => $publishResp->json('state.type'),
            'reason' => $reason,
        ]);

        logPropertyHistory([
            'propertyId' => $property->id,
            'field' => 'price_rejected_kept_live_on_property_finder',
            'newValue' => json_encode([
                'account' => $portalSetting->slug,
                'externalListingId' => $externalListingId,
                'attemptedPrice' => $attemptedPrice,
                'expectedPrice' => $expectedPrice,
                'message' => $reason,
            ]),
        ]);

        // The listing IS live, so report success — the price adjustment is carried as the
        // message + priceAdjusted flag (and stored on the meta reason) rather than as an
        // error, so callers (e.g. the publish-draft API) don't show a failure for a live listing.
        return [
            'success' => true,
            'status' => 200,
            'pfListingId' => $externalListingId,
            'state' => $publishResp->json('state.stage'),
            'priceAdjusted' => true,
            'attemptedPrice' => $attemptedPrice,
            'expectedPrice' => $expectedPrice,
            'message' => $reason,
        ];
    }

    /**
     * True when PF blocked the listing on the PRICE check, whatever wording it used. Kept
     * separate from {@see rejectedPriceExpected()} because PF does not always name a price
     * it would accept — and a price rejection we can't parse a number out of still has to
     * be recognised, or the listing is left stranded in draft.
     */
    protected function priceCheckFailed($response): bool
    {
        foreach ($this->responseMessages($response) as $message) {
            if (stripos($message, 'Price check failed') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse the price PF says it expects out of a price-check rejection (error detail or
     * publish state reason). PF words this inconsistently across checks, so both known
     * shapes are handled:
     *   "Price check failed. Expected price within ±50000 AED of 1200000, got 1500000"
     *   "Price check failed. Expected 1200000, got 1500000"   (the "Expected X, got Y"
     *                                                          template its sibling type /
     *                                                          sale-type checks use)
     * "of X" wins when present, because in the first shape the number right after
     * "Expected" is the TOLERANCE (±50000), not the price. Any ±N term is skipped for the
     * same reason. Returns null when no price can be read out of the message.
     */
    protected function rejectedPriceExpected($response): ?float
    {
        $number = '([0-9][0-9,]*(?:\.[0-9]+)?)';

        foreach ($this->responseMessages($response) as $message) {
            if (stripos($message, 'Price check failed') === false) {
                continue;
            }

            if (preg_match('/\bof\s+(?:AED\s*)?'.$number.'/i', $message, $m)) {
                return (float) str_replace(',', '', $m[1]);
            }

            // "Expected [price] [to be] [AED] X" — drop a leading ±tolerance token first so
            // it can't be mistaken for the price.
            $withoutTolerance = preg_replace('/[±+]\s*[0-9][0-9,]*(?:\.[0-9]+)?\s*(?:AED)?/iu', '', $message);
            if (preg_match('/Expected\s+(?:price\s+)?(?:to\s+be\s+)?(?:within\s+)?(?:AED\s*)?'.$number.'/i', $withoutTolerance, $m)) {
                return (float) str_replace(',', '', $m[1]);
            }
        }

        return null;
    }

    /**
     * The price amount PF currently holds for a listing. Captured BEFORE the draft cycle
     * mutates it, so a publish blocked on the price check can be rolled back to a value PF
     * is known to accept — it is, by definition, the price the listing was live at.
     * Walks live then draft scope. Null when the listing or its price can't be read.
     */
    protected function fetchListingPriceAmount(string $token, string $externalListingId): ?float
    {
        foreach ([[], ['draft' => 'true']] as $extra) {
            $query = ['filter' => ['ids' => $externalListingId]] + $extra;

            $resp = $this->http($token)->get('/listings', $query);
            if ($resp->status() === 401) {
                $token = $this->getAccessToken(true);
                if ($token) {
                    $resp = $this->http($token)->get('/listings', $query);
                }
            }

            if (! $resp->successful()) {
                continue;
            }

            $listing = ($resp->json('results') ?? [])[0] ?? null;
            if ($listing === null) {
                continue;
            }

            // Same shape as a built payload — reuse its reader.
            $amount = $this->payloadPriceAmount(['price' => $listing['price'] ?? []]);
            if ($amount !== null) {
                return $amount;
            }
        }

        return null;
    }

    /**
     * The price amount from a built payload — the value under price.amounts keyed by
     * price.type (buildPrice() stores a single amount matching the type). Falls back to the
     * first amount present. Null when the payload carries no price.
     */
    protected function payloadPriceAmount(array $payload): ?float
    {
        $amounts = $payload['price']['amounts'] ?? [];
        if (! is_array($amounts) || empty($amounts)) {
            return null;
        }

        $type = $payload['price']['type'] ?? null;
        if ($type !== null && isset($amounts[$type])) {
            return (float) $amounts[$type];
        }

        return (float) reset($amounts);
    }

    /**
     * Return the payload with its price amount overridden (every other field untouched),
     * so the full listing can be re-sent with only the price changed.
     */
    protected function withPriceAmount(array $payload, float $amount): array
    {
        $amounts = $payload['price']['amounts'] ?? [];
        if (! is_array($amounts) || empty($amounts)) {
            return $payload;
        }

        $type = $payload['price']['type'] ?? array_key_first($amounts);
        // PF schema types amounts as integer; cast so a whole-number float isn't
        // json_encoded as "N.0" and rejected on update.
        $payload['price']['amounts'][$type] = (int) round($amount);

        return $payload;
    }

    protected function formatAmount($amount): string
    {
        return number_format((float) $amount, 0);
    }

    /**
     * Find an existing Property Finder listing id by our reference number — used to
     * reconcile a property to a listing that has no externalListingId in meta yet
     * (imported listings, or an orphaned listing PF still holds the reference for).
     *
     * PF's GET /listings defaults to the live scope only, so a reference held by a
     * draft or archived listing is invisible unless draft=true / archived=true is
     * passed. This walks all three scopes (mirroring fetchListingById) and filters
     * server-side with PF's filter[<field>] convention — a top-level ?reference= is
     * not honoured. Returns the id of the first listing whose reference matches exactly.
     */
    public function findExternalIdByReference(Property $property, PortalSetting $portalSetting): ?string
    {
        if (! $property->referenceNumber) {
            return null;
        }

        $this->apiKey = $portalSetting->pfApiKey;
        $this->apiSecret = $portalSetting->pfApiSecret;

        if (! $this->apiKey || ! $this->apiSecret) {
            return null;
        }

        $token = $this->getAccessToken();
        if (! $token) {
            return null;
        }

        $reference = (string) $property->referenceNumber;

        $scopes = [
            'live' => [],
            'draft' => ['draft' => 'true'],
            'archived' => ['archived' => 'true'],
        ];

        foreach ($scopes as $scope => $extra) {
            $query = ['filter' => ['reference' => $reference]] + $extra;

            $resp = $this->http($token)->get('/listings', $query);
            if ($resp->status() === 401) {
                $token = $this->getAccessToken(true);
                if ($token) {
                    $resp = $this->http($token)->get('/listings', $query);
                }
            }

            // Don't bail on a failed scope — log and keep walking; the listing may
            // still surface under a later scope.
            if (! $resp->successful()) {
                Log::error('[PropertyFinder API] reference lookup failed — trying next scope', [
                    'crm_property_id' => $property->id,
                    'reference' => $reference,
                    'scope' => $scope,
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);

                continue;
            }

            foreach ($resp->json('results') ?? [] as $listing) {
                if ((string) ($listing['reference'] ?? '') === $reference && isset($listing['id'])) {
                    Log::warning('[PropertyFinder API] reference lookup matched existing listing', [
                        'crm_property_id' => $property->id,
                        'reference' => $reference,
                        'scope' => $scope,
                        'pf_listing_id' => $listing['id'],
                    ]);

                    return (string) $listing['id'];
                }
            }
        }

        return null;
    }

    /**
     * The single PropertyMeta row that links a property to its PF listing
     * (account, externalListingId, status/reason). One source of truth for the
     * propertyId + pfPortalListingData lookup reused across publish / update /
     * fetch / unpublish. Returns an unsaved instance when none exists yet, so
     * callers can mutate + save() uniformly.
     *
     * Keyed by $property (not cached in __construct) because this service is
     * container-resolved and handles many properties per request/job.
     */
    protected function listingMeta(Property $property): PropertyMeta
    {
        return PropertyMeta::firstOrNew([
            'propertyId' => $property->id,
            'metaKey' => 'pfPortalListingData',
        ]);
    }

    /**
     * Persist PF listing data onto {@see listingMeta()} and return the stored
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
     * Drop the stored PF listing link for a property by deleting its
     * pfPortalListingData meta row. Used when PF's catalog reports the stored
     * listing id is gone (404 on update) — the dead id must be cleared so the
     * property is treated as unpublished and re-created from scratch instead of
     * being PUT against a listing that no longer exists. A no-op when no row
     * exists (listingMeta() returns an unsaved instance whose delete() early-returns).
     */
    protected function forgetListingId(Property $property): void
    {
        $this->listingMeta($property)->delete();
    }

    /**
     * Apply an inbound PropertyFinder "listing.action" webhook to the linked
     * property's pfPortalListingData meta. Resolves the property by the listing
     * id PF assigns (stored as externalListingId) and merges the status fields
     * (status / portalStatus / reason) in. Returns the updated property id, or
     * null when no CRM listing maps to the id.
     */
    public function applyWebhookListingStatus(string $externalListingId, array $changes): ?int
    {
        $property = $this->findPropertyByExternalListingId($externalListingId);
        if (! $property) {
            return null;
        }

        $this->saveListingData($property, $changes);

        return $property->id;
    }

    /**
     * Resolve the CRM property that owns a given PF listing id. The PF listing id
     * is stored as externalListingId inside the property's pfPortalListingData
     * meta. Returns null when no property is linked to that listing.
     */
    public function findPropertyByExternalListingId(string $externalListingId): ?Property
    {
        $meta = PropertyMeta::where('metaKey', 'pfPortalListingData')
            ->where('metaValue', 'like', '%"externalListingId":"'.$externalListingId.'"%')
            ->first();

        return $meta ? Property::find($meta->propertyId) : null;
    }

    /**
     * Register Property Finder Atlas webhook subscriptions so PF posts listing
     * lifecycle events to our callback. PF subscribes a single eventId per call,
     * so we POST /webhooks once per event and report each result independently.
     * Credentials come from the given PortalSetting account (same creds used to
     * publish). The matching receiver is ExternalController@capturePropertyFinderWebhook.
     *
     * @param  string[]  $eventIds  e.g. ['listing.published', 'listing.unpublished', 'listing.action']
     * @return array{success: bool, status: int, subscriptions?: array, error?: string}
     */
    public function subscribeWebhooks(PortalSetting $portalSetting, array $eventIds, string $callbackUrl, string $secret): array
    {
        $this->apiKey = $portalSetting->pfApiKey;
        $this->apiSecret = $portalSetting->pfApiSecret;

        if (! $this->apiKey || ! $this->apiSecret) {
            return ['success' => false, 'status' => 422, 'error' => "Portal account '{$portalSetting->slug}' is missing Property Finder API credentials"];
        }

        $token = $this->getAccessToken();
        if (! $token) {
            return ['success' => false, 'status' => 500, 'error' => 'Failed to obtain Property Finder access token'];
        }

        $subscriptions = [];

        foreach ($eventIds as $eventId) {
            $payload = [
                'eventId' => $eventId,
                'callbackUrl' => $callbackUrl,
                'secret' => $secret,
            ];

            $resp = $this->http($token)->post('/webhooks', $payload);

            if ($resp->status() === 401) {
                $token = $this->getAccessToken(true);
                if ($token) {
                    $resp = $this->http($token)->post('/webhooks', $payload);
                }
            }

            if (! $resp->successful()) {
                $error = $this->pfError("webhook subscribe ({$eventId})", $resp);
                $subscriptions[] = [
                    'eventId' => $eventId,
                    'success' => false,
                    'status' => $error['status'] ?? $resp->status(),
                    'error' => $error['error'] ?? null,
                ];

                continue;
            }

            Log::error('[PropertyFinder API] Webhook subscribed', ['eventId' => $eventId, 'body' => $resp->json()]);

            $subscriptions[] = [
                'eventId' => $eventId,
                'success' => true,
                'status' => $resp->status(),
                'webhookId' => $resp->json('data.id') ?? $resp->json('id'),
            ];
        }

        $allOk = $subscriptions !== [] && collect($subscriptions)->every(fn ($s) => $s['success'] === true);

        return [
            'success' => $allOk,
            'status' => $allOk ? 200 : 207,
            'subscriptions' => $subscriptions,
        ];
    }

    /**
     * Verify the HMAC signature PropertyFinder attaches to an inbound webhook.
     * When a secret was set on the subscription, PF signs the full raw event
     * payload with HMAC-SHA256 (keyed by the secret) and sends the hex digest in
     * the X-Signature header. With no secret configured PF omits the header, so
     * there is nothing to verify. Returns false when a secret is set but the
     * signature is missing or does not match.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature, ?string $secret): bool
    {
        if (! $secret) {
            return true;
        }

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Revert a live PF listing back to draft via POST /listings/{id}/unpublish.
     */
    public function unpublishProperty(Property $property, PortalSetting $portalSetting, string $externalListingId): array
    {
        $this->apiKey = $portalSetting->pfApiKey;
        $this->apiSecret = $portalSetting->pfApiSecret;

        if (! $this->apiKey || ! $this->apiSecret) {
            return ['success' => false, 'status' => 422, 'error' => "Portal account '{$portalSetting->slug}' is missing Property Finder API credentials"];
        }

        $token = $this->getAccessToken();
        if (! $token) {
            return ['success' => false, 'status' => 500, 'error' => 'Failed to obtain Property Finder access token'];
        }

        $unpublishResp = $this->http($token)->post("/listings/{$externalListingId}/unpublish");

        if ($unpublishResp->status() === 401) {
            $token = $this->getAccessToken(true);
            if ($token) {
                $unpublishResp = $this->http($token)->post("/listings/{$externalListingId}/unpublish");
            }
        }

        if (! $unpublishResp->successful()) {
            $this->saveListingData($property, [
                'reason' => "We couldn't remove this listing from the portal. Please try again later.",
            ]);

            return $this->pfError('unpublish', $unpublishResp, $externalListingId);
        }

        logPropertyHistory([
            'propertyId' => $property->id,
            'field' => 'reverted_to_draft_on_property_finder',
            'newValue' => json_encode([
                'account' => $portalSetting->slug,
                'externalListingId' => $externalListingId,
                'message' => "{$portalSetting->branchName} — listing ID {$externalListingId} reverted to draft",
            ]),
        ]);

        Log::error('[PropertyFinder API] Listing reverted to draft', [
            'crm_property_id' => $property->id,
            'pf_listing_id' => $externalListingId,
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
            'pfListingId' => $externalListingId,
            'message' => 'Property Finder listing reverted to draft',
        ];
    }

    protected function sanitizeText(string $value, bool $keepLineBreaks = false): string
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($keepLineBreaks) {
            // Rich-text editors wrap each list item's text in its own <p>
            // (<li><p>…</p></li>). Without this, both </p> and </li> become newlines
            // and every bullet is double-spaced on PF. Unwrap the nested <p> so each
            // list item yields a single line break.
            $decoded = preg_replace('#<li[^>]*>\s*<p[^>]*>#i', '<li>', $decoded);
            $decoded = preg_replace('#</p>\s*</li>#i', '</li>', $decoded);

            // Prefix each list item with a dash bullet. PF descriptions are plain
            // text and a real "•" is non-ASCII (unsupported), so use "- ". The
            // preceding </li>/</p> supplies the line break, so no newline is added
            // here.
            $decoded = preg_replace('#<li[^>]*>#i', '- ', $decoded);

            // Convert block-level tag boundaries to newlines before stripping so PF
            // (plain-text description, but renders \n) keeps paragraph/line structure.
            $decoded = preg_replace('#<\s*(br|/p|/div|/li|/h[1-6])\s*/?>#i', "\n", $decoded);
            $stripped = $this->toAscii(strip_tags($decoded));
            $stripped = preg_replace('/[ \t]+/u', ' ', $stripped);   // collapse spaces, keep newlines
            $stripped = preg_replace('/ *\n */', "\n", $stripped);    // trim spaces around breaks
            $stripped = preg_replace('/\n{3,}/', "\n\n", $stripped);  // at most one blank line

            return trim($stripped);
        }

        $stripped = $this->toAscii(strip_tags($decoded));

        return trim(preg_replace('/\s+/u', ' ', $stripped));
    }

    /**
     * Property Finder does not support non-ASCII characters (emojis, symbols,
     * HTML tags, etc.) in title/description and rejects them. Transliterate the
     * common typographic characters that leak in from rich-text editors to their
     * ASCII equivalents, then drop anything still outside printable ASCII.
     */
    protected function toAscii(string $value): string
    {
        $value = strtr($value, [
            "\u{00A0}" => ' ',                                                   // non-breaking space
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{2032}" => "'", // single quotes / prime
            "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{2033}" => '"', // double quotes / prime
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{2015}" => '-', "\u{2212}" => '-', // dashes / minus
            "\u{2022}" => '-', "\u{00B7}" => '-',                                // bullets
            "\u{2026}" => '...',                                                 // ellipsis
        ]);

        // Fold accented Latin letters to their base form (é -> e, ñ -> n) by
        // decomposing to NFD and dropping the combining marks, so names survive
        // instead of losing the whole letter. Degrades to plain removal below if
        // ext-intl is unavailable.
        if (class_exists(\Normalizer::class)) {
            $value = preg_replace('/\p{M}+/u', '', \Normalizer::normalize($value, \Normalizer::FORM_D));
        }

        // Remove any remaining non-ASCII (emojis, currency/symbol glyphs, …),
        // keeping tab/newline/carriage-return and printable ASCII.
        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/u', '', $value);
    }

    public function fetchListings(array $filters = []): array
    {
        $token = $this->getAccessToken();

        if (! $token) {
            return ['success' => false, 'status' => 500, 'error' => 'Failed to obtain Property Finder access token'];
        }

        $status = strtolower((string) ($filters['status'] ?? ''));
        $baseQuery = array_filter([
            'page' => $filters['page'] ?? null,
            'perPage' => $filters['perPage'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($status === 'live') {
            return $this->fetchListingsCall($token, $baseQuery);
        }

        // status != live → drafts/pending/rejected/etc. + archived, merged.
        $draftResult = $this->fetchListingsCall($token, $baseQuery + ['draft' => 'true']);
        if (! ($draftResult['success'] ?? false)) {
            return $draftResult;
        }

        $archivedResult = $this->fetchListingsCall($token, $baseQuery + ['archived' => 'true']);
        if (! ($archivedResult['success'] ?? false)) {
            return $archivedResult;
        }

        return [
            'success' => true,
            'status' => 200,
            'pagination' => [
                'total' => ($draftResult['pagination']['total'] ?? 0) + ($archivedResult['pagination']['total'] ?? 0),
                'draft' => $draftResult['pagination'] ?? null,
                'archived' => $archivedResult['pagination'] ?? null,
            ],
            'listings' => array_merge($draftResult['listings'], $archivedResult['listings']),
        ];
    }

    protected function fetchListingsCall(string $token, array $query): array
    {
        $resp = $this->http($token)->get('/listings', $query);
        if ($resp->status() === 401) {
            $token = $this->getAccessToken(true);
            if ($token) {
                $resp = $this->http($token)->get('/listings', $query);
            }
        }

        if (! $resp->successful()) {
            return $this->pfError('fetch', $resp);
        }

        return [
            'success' => true,
            'status' => 200,
            'pagination' => $resp->json('pagination'),
            'listings' => $resp->json('results') ?? [],
        ];
    }

    /**
     * Fetch a single PF listing by its external id, scoped to the given portal
     * account's credentials. Used to surface the live PF record for a CRM property.
     */
    public function fetchListingById(Property $property, string $externalListingId, PortalSetting $portalSetting): array
    {
        $this->apiKey = $portalSetting->pfApiKey;
        $this->apiSecret = $portalSetting->pfApiSecret;

        Log::error('[PropertyFinder API] fetchListingById start', [
            'pf_listing_id' => $externalListingId,
            'account' => $portalSetting->slug,
        ]);

        if (! $this->apiKey || ! $this->apiSecret) {
            return ['success' => false, 'status' => 422, 'error' => "Portal account '{$portalSetting->slug}' is missing Property Finder API credentials"];
        }

        $token = $this->getAccessToken();
        if (! $token) {
            return ['success' => false, 'status' => 500, 'error' => 'Failed to obtain Property Finder access token'];
        }

        // PF surfaces a listing only under the right collection scope: live by
        // default, then draft, then archived. Walk the scopes (adding draft=true /
        // archived=true) until one returns the listing.
        $scopes = [
            'live' => [],
            'draft' => ['draft' => 'true'],
            'archived' => ['archived' => 'true'],
        ];

        foreach ($scopes as $scope => $extra) {
            $query = ['filter' => ['ids' => $externalListingId]] + $extra;

            $resp = $this->http($token)->get('/listings', $query);

            if ($resp->status() === 401) {
                $token = $this->getAccessToken(true);
                if ($token) {
                    $resp = $this->http($token)->get('/listings', $query);
                }
            }

            // Don't bail on a failed scope — log it and keep trying the remaining
            // scopes; the listing may still be reachable under a later one.
            if (! $resp->successful()) {
                Log::error('[PropertyFinder API] fetchListingById request failed — trying next scope', [
                    'pf_listing_id' => $externalListingId,
                    'account' => $portalSetting->slug,
                    'scope' => $scope,
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);

                continue;
            }

            $results = $resp->json('results') ?? [];

            if (! empty($results)) {
                Log::error('[PropertyFinder API] fetchListingById found', [
                    'pf_listing_id' => $externalListingId,
                    'account' => $portalSetting->slug,
                    'scope' => $scope,
                ]);

                $listing = $results[0];

                // Sync the live PF state back into the property's listing meta.
                $state = $listing['state'] ?? [];
                $reasons = array_filter(array_map(
                    fn ($r) => $r['en'] ?? null,
                    $state['reasons'] ?? []
                ));

                // Persist the link (account + externalListingId) alongside the status so
                // a listing found by reference — which had no meta row — becomes
                // addressable by id next time.
                $listingData = $this->saveListingData($property, [
                    'account' => $portalSetting->slug,
                    'externalListingId' => $externalListingId,
                    'status' => $state['stage'] ?? null,
                    'portalStatus' => $state['type'] ?? null,
                    'reason' => $reasons ? implode('; ', $reasons) : null,
                ]);

                return [
                    'success' => true,
                    'status' => 200,
                    'scope' => $scope,
                    'listing' => $listing,
                    'listingData' => $listingData,
                ];
            }

            Log::error('[PropertyFinder API] fetchListingById no data in scope — trying next', [
                'pf_listing_id' => $externalListingId,
                'account' => $portalSetting->slug,
                'scope' => $scope,
            ]);
        }

        return ['success' => false, 'status' => 404, 'error' => "Property Finder listing '{$externalListingId}' not found"];
    }

    protected function http(string $token)
    {
        return Http::withToken($token)
            ->withHeaders([
                'User-Agent' => 'AppendixCRM/1.0',
            ])
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            // Retry timeouts up to 3 attempts. POST is excluded: a create/publish that
            // timed out may still have landed, and a retry would duplicate it.
            ->retry(3, 2000, fn (\Throwable $e, $request, ?string $method = null) => $method !== 'POST' && (
                $e instanceof ConnectionException
                || ($e instanceof RequestException && (
                    in_array($e->response->status(), [408, 504], true)
                    || str_contains(strtolower($e->response->body()), 'timed out')
                ))
            ), throw: false);
    }

    /**
     * Collect every human-readable message PF puts in a response — error details
     * (create/update PUT) and state reasons (publish) — into a flat string list, so
     * the rejection detectors can scan one place. Returns [] when the body isn't JSON.
     */
    protected function responseMessages($response): array
    {
        $body = $response->json();
        if (! is_array($body)) {
            return [];
        }

        $messages = [];
        foreach ($body['errors'] ?? [] as $e) {
            $messages[] = (string) ($e['detail'] ?? '');
        }
        $messages[] = (string) ($body['detail'] ?? '');
        foreach ($body['state']['reasons'] ?? [] as $reason) {
            $messages[] = is_array($reason) ? (string) ($reason['en'] ?? '') : (string) $reason;
        }

        return $messages;
    }

    /**
     * PF rejects standalone types (townhouse/villa/land) when the resolved location only accepts
     * units, answering with:
     *   Property type check failed. "townhouse" is not allowed. Expected one of [Apartment, Duplex, …].
     * — either as an error detail (create/update PUT) or a state reason (publish). This returns the
     * FIRST accepted type from that "Expected one of […]" list as a PF type slug (e.g. "apartment",
     * "hotel-apartment", "office-space"), or null when the response is not a type rejection. PF prints
     * display labels, so the label is lower-cased and spaces hyphenated. Only when PF rejected the type
     * but named no parseable alternative do we fall back to a best-guess "apartment".
     */
    protected function rejectedTypeFallback($response): ?string
    {
        $sawTypeRejection = false;

        foreach ($this->responseMessages($response) as $message) {
            if (stripos($message, 'Property type check failed') === false) {
                continue;
            }
            $sawTypeRejection = true;
            if (preg_match('/Expected one of \[(.*?)\]/i', $message, $m)) {
                $first = trim(explode(',', $m[1])[0]);
                if ($first !== '') {
                    return strtolower(str_replace(' ', '-', $first));
                }
            }
        }

        // No type rejection → nothing to correct. (Previously this returned 'apartment'
        // unconditionally, which re-sent every published listing as an apartment.)
        return $sawTypeRejection ? 'apartment' : null;
    }

    /**
     * PF encodes sale type in projectStatus: the _primary variants (completed_primary,
     * off_plan_primary) are Primary (developer/primary market); completed / off_plan are
     * Secondary (resale). PF only allows Primary at locations it has registered as primary
     * projects, otherwise it rejects with "Sale type check failed. Expected Secondary, got
     * Primary" (or the reverse). This returns the projectStatus matching the sale type PF
     * expects — derived from the property's own status: Secondary strips the _primary suffix,
     * Primary adds it — or null when the response is not a sale-type rejection or the property
     * has no mappable projectStatus.
     */
    protected function rejectedSaleTypeStatus(Property $property, $response): ?string
    {
        $expected = null;
        foreach ($this->responseMessages($response) as $message) {
            if (stripos($message, 'Sale type check failed') !== false
                && preg_match('/Expected\s+(Secondary|Primary)/i', $message, $m)) {
                $expected = strtolower($m[1]);
                break;
            }
        }

        if ($expected === null) {
            return null;
        }

        $current = $this->mapProjectStatus($property);
        if (! $current) {
            return null;
        }

        return $expected === 'secondary'
            ? str_replace('_primary', '', $current)
            : (str_ends_with($current, '_primary') ? $current : $current.'_primary');
    }

    /**
     * When PF rejects a listing with a "check failed" reason — as an error detail on a
     * create/update PUT, or a state reason on publish — rebuild the payload with the
     * corrections PF asks for and re-PUT once (a full-payload replace). Handles:
     *   - "Property type check failed …"  → fall back to the first accepted type.
     *   - "Sale type check failed. Expected Secondary/Primary" → flip projectStatus to the
     *     expected Primary/Secondary variant.
     * Both corrections are applied together when PF raises both. Returns the corrective PUT
     * response so the caller can re-publish, or null when the response names no such reason
     * (nothing to correct) or the corrected payload can't be built. The CRM record is untouched.
     */
    protected function resendWithPfCorrections(Property $property, string $token, string $externalListingId, $response)
    {
        $forceType = $this->rejectedTypeFallback($response);
        $forceProjectStatus = $this->rejectedSaleTypeStatus($property, $response);

        if ($forceType === null && $forceProjectStatus === null) {
            return null;
        }

        Log::warning('[PropertyFinder API] PF rejection — resending listing with corrections', [
            'crm_property_id' => $property->id,
            'pf_listing_id' => $externalListingId,
            'storedType' => $property->pfPropertyType,
            'forceType' => $forceType,
            'forceProjectStatus' => $forceProjectStatus,
        ]);

        $payload = $this->buildPayload($property, $token, $forceType, $forceProjectStatus);
        if (isset($payload['error'])) {
            Log::error('[PropertyFinder API] correction retry — payload build failed', [
                'crm_property_id' => $property->id,
                'pf_listing_id' => $externalListingId,
                'forceType' => $forceType,
                'forceProjectStatus' => $forceProjectStatus,
                'error' => $payload['error'],
            ]);

            return null;
        }

        $resp = $this->http($token)->put("/listings/{$externalListingId}", $payload);
        if ($resp->status() === 401) {
            $token = $this->getAccessToken(true);
            if ($token) {
                $resp = $this->http($token)->put("/listings/{$externalListingId}", $payload);
            }
        }

        return $resp;
    }

    /**
     * True when a PF create/update response is the 422 meaning "a listing with this
     * reference already exists for the client". Atlas wraps PF's structured error
     * inside a stringified `detail` (the real error type is buried in that string,
     * not a top-level field), so match the stable PF type code against the raw body.
     */
    protected function isReferenceInUse($response): bool
    {
        if ($response->status() !== 422) {
            return false;
        }

        return str_contains((string) $response->body(), 'ReferenceInUseByAnotherListingOfClient');
    }

    protected function pfError(string $stage, $response, $pfListingId = null): array
    {
        $status = $response->status();
        $body = $response->json();

        // Flatten PF's error array → "detail (field: pointer) | detail (field: pointer)".
        $errorDetails = collect($body['errors'] ?? [])
            ->map(fn ($e) => trim(($e['detail'] ?? '').(! empty($e['pointer']) ? " (field: {$e['pointer']})" : '')))
            ->filter()
            ->join(' | ');

        // PF returns HTTP 200 for /publish but reports blocking problems (price/type/sale-type
        // checks, compliance, …) in state.reasons — fold those in so a drafted-on-publish
        // rejection carries PF's actual reason instead of a generic message.
        $stateReasons = collect($body['state']['reasons'] ?? [])
            ->map(fn ($r) => is_array($r) ? ($r['en'] ?? '') : (string) $r)
            ->filter()
            ->join(' | ');

        $errorMessage = $errorDetails ?: $stateReasons ?: ($body['detail'] ?? $body['title'] ?? 'Unknown error');

        // A 2xx response that still carries blocking reasons is a business-validation failure
        // (the listing stayed in draft), so map it like a 422 rather than "unexpected 200".
        if ($response->successful() && $stateReasons !== '') {
            $status = 422;
        }

        // Map HTTP status → agent-friendly explanation.
        $message = match ($status) {
            400 => "Invalid data format: {$errorMessage}",                       // schema validation — wrong field type/format
            401 => 'Authentication failed. Please check API credentials.',       // still unauthorized after token refresh
            403 => 'Access denied. Missing required API scope or permission.',   // missing scope/permission
            409 => "Conflict: {$errorMessage}",                                  // duplicate / listing already exists
            422 => "Validation failed: {$errorMessage}",                         // business validation — permit/type mismatch
            429 => 'Rate limit exceeded. Retry after '.$response->header('Retry-After', 60).' seconds.',
            500, 502, 503 => 'Property Finder server error. Please try again later.',
            default => "Unexpected error ({$status}): {$errorMessage}",
        };

        // Short label per status for the log line; 409/429 are recoverable → warning, rest → error.
        $label = match ($status) {
            400 => 'Bad Schema',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            409 => 'Conflict',
            422 => 'Business Validation',
            429 => 'Rate Limited',
            500, 502, 503 => 'Server Error',
            default => 'Unknown Error',
        };
        $level = in_array($status, [409, 429], true) ? 'warning' : 'error';

        Log::{$level}("[PropertyFinder API] {$stage} failed — {$status} {$label}", [
            'status' => $status,
            'pf_listing_id' => $pfListingId,
            'error' => $message,
            'body' => $body ?? $response->body(),
        ]);

        return array_filter([
            'success' => false,
            'status' => $status,
            'stage' => $stage,
            'error' => $message,
            'details' => $body ?? $response->body(),
            'pfListingId' => $pfListingId,
        ], fn ($v) => $v !== null);
    }

    // ==================== PAYLOAD BUILDER ====================

    protected function buildPayload(Property $property, ?string $token = null, ?string $forceType = null, ?string $forceProjectStatus = null): array
    {
        if (! $property->referenceNumber) {
            return ['error' => 'Property is missing referenceNumber'];
        }

        if (! $property->pfPropertyType) {
            return ['error' => 'Property is missing pfPropertyType'];
        }

        // PF rejects standalone types (townhouse/villa/land) at unit-only locations with a
        // "Property type check failed" reason. When that happens the publish/update flow rebuilds
        // with $forceType set to the first type PF says it accepts (parsed from its "Expected one
        // of […]" list), so the listing goes live as a unit. The override affects only the PF
        // payload — the CRM record is left untouched.
        $pfType = $forceType ?? $property->pfPropertyType;

        $description = $this->sanitizeText((string) ($property->description ?? ''), keepLineBreaks: true);
        if ($description === '') {
            return ['error' => 'Property is missing description'];
        }

        $title = $this->sanitizeText((string) ($property->title ?? ''));
        if ($title === '') {
            return ['error' => 'Property is missing title'];
        }

        $meta = $property->loadMissing('meta')->meta->pluck('metaValue', 'metaKey');

        $category = $this->resolveCategory($pfType);
        if (! $category) {
            return ['error' => "Cannot resolve category for pfPropertyType '{$pfType}'"];
        }

        $locationId = $this->resolveLocationId($property, $pfType);
        if (! $locationId) {
            return ['error' => 'Property is missing PF location (pfBuilding/pfSubCommunity/pfCommunity/pfCity)'];
        }

        $emirate = $this->resolveEmirate($property);
        if (! $emirate) {
            return ['error' => 'Cannot resolve uaeEmirate from pfCity'];
        }

        $images = $this->mapImages($property);
        if (empty($images)) {
            return ['error' => 'Property has no portal-flagged images'];
        }

        $priceObject = $this->buildPrice($property, $meta->all());
        if (isset($priceObject['error'])) {
            return ['error' => $priceObject['error']];
        }

        $payload = [
            'reference' => (string) $property->referenceNumber,
            'uaeEmirate' => $emirate,
            'category' => $category,
            'type' => $pfType,
            'furnishingType' => $this->mapFurnishing($meta['pfFurnishingType'] ?? null),
            'title' => ['en' => $title],
            'description' => ['en' => $description],
            'size' => (float) $property->sizeArea,
            'location' => ['id' => (int) $locationId],
            'price' => $priceObject,
            'media' => ['images' => $images],
        ];

        // Bathrooms — required for everything except land/farm
        if (! in_array($property->pfPropertyType, ['land', 'farm'], true)) {
            $payload['bathrooms'] = $this->mapBathrooms($property->bathrooms);
        }

        // Bedrooms — string enum, "studio" for 0
        if ($property->bedrooms !== null) {
            $payload['bedrooms'] = $this->mapBedrooms($property->bedrooms);
        }

        // Plot/built-up area
        if ($property->plotSize) {
            // PropertyFinder's catalog expects plotSize as a whole number (its struct field is a
            // uint); a decimal like 3412.81 fails to unmarshal, so round to the nearest integer.
            $payload['plotSize'] = (int) round((float) $property->plotSize);
        }
        if (! empty($meta['pfBuiltUpArea'])) {
            $payload['builtUpArea'] = (float) $meta['pfBuiltUpArea'];
        }

        // Project status (sale type / off-plan). $forceProjectStatus overrides the mapped
        // value when PF rejects the sale type and we retry with the Primary/Secondary variant
        // PF expects (see resendWithPfCorrections()).
        if ($projectStatus = ($forceProjectStatus ?? $this->mapProjectStatus($property))) {
            $payload['projectStatus'] = $projectStatus;
        }

        // Compliance — required for Dubai/Abu Dhabi
        if (in_array($emirate, ['dubai', 'abu_dhabi'], true)) {
            if (! $property->reraPermitNo) {
                return ['error' => 'Property is missing permit number for Dubai/Abu Dhabi listing'];
            }
            $payload['compliance'] = $this->buildCompliance($property, $emirate);
        }

        // Amenities — pfAmenities is already in PF slug format, but may include
        // CRM-only slugs (squash-court, shops, …) that PF rejects. PF's accepted
        // amenity enum is category-specific: land/farm accept none, commercial and
        // residential each have their own set. Filtering against the wrong set
        // yields a SCHEMA_VALIDATION 400 on /amenities/{n}.
        if (in_array($property->pfPropertyType, ['land', 'farm'], true)) {
            $allowedAmenities = [];
        } elseif ($category === Constants::PROPERTY_CATEGORY_COMMERCIAL) {
            $allowedAmenities = Constants::PF_COMMERCIAL_AMENITIES;
        } else {
            $allowedAmenities = Constants::PF_NATIVE_AMENITIES;
        }
        $payload['amenities'] = array_values(array_intersect(
            array_unique($property->pfAmenities ?? []),
            array_keys($allowedAmenities)
        ));

        // Videos
        $videos = $this->mapVideos($property);
        if (! empty($videos)) {
            $payload['media']['videos'] = $videos;
        }

        // Optional scalars
        if (! empty($meta['pfUnitNumber'])) {
            $payload['unitNumber'] = (string) $meta['pfUnitNumber'];
        }
        if (! empty($meta['pfDeveloper'])) {
            $payload['developer'] = (string) $meta['pfDeveloper'];
        }
        if ($property->parkingSpaces) {
            $payload['parkingSlots'] = (int) $property->parkingSpaces;
        }
        if (! empty($meta['pfNumberOfFloors'])) {
            $payload['numberOfFloors'] = (int) $meta['pfNumberOfFloors'];
        }
        if (! empty($meta['pfAvailableFrom'])) {
            $payload['availableFrom'] = (string) $meta['pfAvailableFrom'];
        }

        // Co-working space requires hasParkingSpace
        if ($property->pfPropertyType === 'co-working-space') {
            $payload['hasParkingOnSite'] = (bool) $property->parkingSpaces;
        }

        // Agent identity (createdBy / assignedTo) — PF requires both ids. PF
        // identifies users by their account-scoped id, so resolve it from the
        // agent's email via the /users endpoint, falling back to the stored
        // public profile id.
        $agentPf = $this->resolveAgentPfUserId($property, $token);
        if (isset($agentPf['error'])) {
            Log::error('[PropertyFinder API] user not found', $agentPf);

            return ['error' => $agentPf['error']];
        }
        $payload['createdBy'] = ['id' => $agentPf['id']];
        $payload['assignedTo'] = ['id' => $agentPf['id']];

        return $payload;
    }

    protected function resolveCategory(string $pfType): ?string
    {
        foreach (Constants::PROPERTY_TYPE_MAPPING as $row) {
            if (($row['pf'] ?? null) === $pfType) {
                return $row['category'] ?? null;
            }
        }

        return null;
    }

    /**
     * Pick the deepest available PF location id: tower > subCommunity > community > city.
     * Returns null when the property carries no location at all — buildPayload() turns that
     * into an error, so the listing refuses to publish rather than landing somewhere wrong.
     */
    protected function resolveLocationId(Property $property, ?string $pfType = null): ?int
    {
        $pfType ??= $property->pfPropertyType;
        // Variant: standalone types start at sub-community (skip tower only).
        // $fields = in_array($pfType, Constants::PF_STANDALONE_TYPES, true)
        //     ? ['pfSubCommunity', 'pfCommunity', 'pfCity']
        //     : ['pfBuilding', 'pfSubCommunity', 'pfCommunity', 'pfCity'];
        $fields = ['pfBuilding', 'pfSubCommunity', 'pfCommunity', 'pfCity'];

        foreach ($fields as $field) {
            if ($property->{$field}) {
                return (int) $property->{$field};
            }
        }

        return null;
    }

    /**
     * Resolve PF uaeEmirate from the property's city. Falls back to "northern_emirates".
     */
    protected function resolveEmirate(Property $property): ?string
    {
        if (! $property->pfCity) {
            return null;
        }

        $cityName = PropertyLocation::where('locationId', $property->pfCity)->value('name');

        if (! $cityName) {
            return 'northern_emirates';
        }

        $normalized = strtolower(trim($cityName));

        if (str_contains($normalized, 'dubai')) {
            return 'dubai';
        }
        if (str_contains($normalized, 'abu dhabi')) {
            return 'abu_dhabi';
        }

        return 'northern_emirates';
    }

    protected function mapFurnishing(?string $furnishing): string
    {
        // PropertyFinder accepts only the residential furnishing states; commercial fit-out
        // values (white-goods, fitted, shell-core) fall back to unfurnished.
        return in_array($furnishing, ['furnished', 'semi-furnished', 'unfurnished'], true)
            ? $furnishing
            : 'unfurnished';
    }

    protected function mapBathrooms($bathrooms): string
    {
        if ($bathrooms === null || $bathrooms === '') {
            return 'none';
        }

        $n = (int) $bathrooms;
        if ($n <= 0) {
            return 'none';
        }
        if ($n > 20) {
            return '20';
        }

        return (string) $n;
    }

    protected function mapBedrooms($bedrooms): string
    {
        $n = (int) $bedrooms;
        if ($n <= 0) {
            return 'studio';
        }
        if ($n > 30) {
            return '30';
        }

        return (string) $n;
    }

    /**
     * Build the price object based on listingType + frequency.
     *
     * @return array{type: string, amounts: array}|array{error: string}
     */
    protected function buildPrice(Property $property, array $meta = []): array
    {
        $listingType = $property->listingType;

        // Sale / off-plan
        if (in_array($listingType, ['sale', 'offPlan'], true)) {
            $amount = $property->price;
            if ($amount <= 0) {
                return ['error' => 'Sale price is required for sale/offPlan listings'];
            }
            $price = [
                'type' => 'sale',
                // PF schema types amounts as integer; a whole-number float json_encodes
                // as "1500000.0" and is rejected as a number — cast to int.
                'amounts' => ['sale' => (int) round($amount)],
            ];
            if (! empty($meta['pfDownPayment'])) {
                $price['downpayment'] = (int) $meta['pfDownPayment'];
            }
            if (! empty($meta['pfPaymentMethods'])) {
                $methods = $meta['pfPaymentMethods'];
                $decoded = is_string($methods) ? json_decode($methods, true) : null;
                $price['paymentMethods'] = is_array($decoded)
                    ? $decoded
                    : (is_array($methods) ? $methods : [(string) $methods]);
            }
            if (! empty($meta['pfPriceOnRequest'])) {
                $price['onRequest'] = true;
            }

            return $price;
        }

        // Rent / vacationHomes
        $frequency = $listingType === 'vacationHomes'
            ? 'daily'
            : ($property->pfPriceFrequency ?? 'yearly');

        if (! array_key_exists($frequency, Constants::PROPERTY_PRICE_FREQUENCIES)) {
            $frequency = 'yearly';
        }

        $amount = (float) ($property->annualRent ?? $property->price ?? 0);
        if ($amount <= 0) {
            return ['error' => 'Rent amount is required for rent/vacationHomes listings'];
        }

        $price = [
            'type' => $frequency,
            // PF schema types amounts as integer (see sale branch above).
            'amounts' => [$frequency => (int) round($amount)],
        ];

        if ($property->pfCheques) {
            $price['numberOfCheques'] = (int) $property->pfCheques;
        }

        return $price;
    }

    protected function mapProjectStatus(Property $property): ?string
    {
        return Constants::COMPLETION_STATUS_PF[$property->completionStatus] ?? null;
    }

    /**
     * Build compliance object based on emirate + listing type.
     *   Dubai vacationHomes → DTCM
     *   Dubai default       → RERA
     *   Abu Dhabi           → ADREC
     */
    protected function buildCompliance(Property $property, string $emirate): array
    {
        $type = match (true) {
            $emirate === 'abu_dhabi' => 'adrec',
            $emirate === 'dubai' && $property->listingType === 'vacationHomes' => 'dtcm',
            default => 'rera',
        };

        $compliance = [
            'listingAdvertisementNumber' => (string) $property->reraPermitNo,
            'type' => $type,
            'userConfirmedDataIsCorrect' => true,
        ];

        // Issuing license number — pull from agent meta if present.
        if ($property->agentId) {
            $licenseNumber = UserMeta::where('userId', $property->agentId)
                ->where('metaKey', '_brokerLicenseNumber')
                ->value('metaValue');
            if ($licenseNumber) {
                $compliance['issuingClientLicenseNumber'] = (string) $licenseNumber;
            }
        }

        return $compliance;
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

        $images = [];

        foreach ($media as $file) {
            $path = $file->compressedPath ?: $file->filePath;

            if (! $path) {
                continue;
            }

            $images[] = [
                'original' => ['url' => $this->absoluteMediaUrl($path)],
            ];

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

    /**
     * PF accepts a single default video URL and a single 360 tour URL.
     * We pick the first non-360 video for "default" and the first matterport/3d-style
     * platform for "view360".
     */
    protected function mapVideos(Property $property): array
    {
        $videos = $property->relationLoaded('propertyVideos')
            ? $property->propertyVideos
            : $property->propertyVideos()->get();

        $out = [];

        foreach ($videos as $v) {
            if (! $v->videoUrl) {
                continue;
            }

            $platform = strtolower((string) $v->platform);
            $is360 = in_array($platform, ['3d_view', 'matterport'], true);

            if ($is360 && ! isset($out['view360'])) {
                $out['view360'] = $v->videoUrl;
            } elseif (! $is360 && ! isset($out['default'])) {
                $out['default'] = $v->videoUrl;
            }

            if (isset($out['default']) && isset($out['view360'])) {
                break;
            }
        }

        return $out;
    }

    /**
     * Resolve the Property Finder user id for a property's listing agent.
     *
     * PF identifies users by their account-scoped id, so we look the agent up by
     * their PF lookup email (stored in user_meta._pfPublicProfileId) via
     * GET /users?email=... Returns ['id' => int] on success, or ['error' => string]
     * pinpointing why resolution failed (missing token, no email mapped, lookup
     * error, or no matching PF account).
     */
    protected function resolveAgentPfUserId(Property $property, ?string $token): array
    {
        if (! $token) {
            return ['error' => 'Cannot resolve the Property Finder user id: no PF API token is available (e.g. a dry-run preview without credentials).'];
        }

        $email = $this->resolveAgentPfEmail($property);
        if (! $email) {
            return ['error' => "No Property Finder lookup email is configured for this listing's agent. Set the agent's PF Public Profile ID to their PF account email."];
        }

        return $this->fetchPfUserIdByEmail($email, $token);
    }

    /**
     * Look up a Property Finder user id by email via the /users endpoint.
     * Returns ['id' => int] on success, or ['error' => string] (and logs) when
     * the lookup call fails or the agent has no matching PF account.
     */
    protected function fetchPfUserIdByEmail(string $email, string $token): array
    {
        try {
            $response = $this->http($token)->get('/users', ['email' => $email]);

            if (! $response->successful()) {
                Log::error('[PropertyFinder API] user lookup by email failed', [
                    'email' => $email,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return ['error' => "Property Finder user lookup failed for \"{$email}\" (API responded with HTTP {$response->status()}). Verify the PF credentials and that the email is correct."];
            }

            // Response: { "data": [ { "id": 1234, "email": ..., ... } ], ... }
            $id = $response->json('data.0.publicProfile.id');
            if (! $id) {
                return ['error' => "No Property Finder account was found for \"{$email}\". Ensure the listing agent has a PF account whose email matches the value in user_meta._pfPublicProfileId / PROPERTY_FINDER_DEFAULT_PUBLIC_PROFILE_ID."];
            }

            return ['id' => (int) $id];
        } catch (\Throwable $e) {
            Log::error('[PropertyFinder API] user lookup by email threw', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return ['error' => "Property Finder user lookup for \"{$email}\" threw an exception: {$e->getMessage()}"];
        }
    }

    /**
     * Resolve the PF lookup email for a property's agent. Prefers agent-level meta
     * (`user_meta._pfPublicProfileId`); falls back to PROPERTY_FINDER_DEFAULT_PUBLIC_PROFILE_ID
     * env so listings can publish before every agent is mapped individually.
     */
    protected function resolveAgentPfEmail(Property $property): ?string
    {
        if ($property->agentId) {
            $email = UserMeta::where('userId', $property->agentId)
                ->where('metaKey', '_pfPublicProfileId')
                ->value('metaValue');
            if ($email) {
                return $email;
            }
        }

        return env('PROPERTY_FINDER_DEFAULT_PUBLIC_PROFILE_ID') ?: null;
    }

    /**
     * Start of the rolling window the lead import asks PF for, as an RFC 3339 UTC "Z"
     * timestamp — the form PF itself returns createdAt in, and one that survives URL
     * encoding (a "+04:00" offset arrives as a space and is rejected).
     *
     * Two hours, chosen so leads survive a stretch of failed runs or a change to the
     * schedule frequency rather than falling out of every window — nothing backfills
     * past it. Re-reading costs nothing for a lead whose reference resolves to one of
     * our properties: the 24-hour check in importLead skips it.
     *
     * The exception is a lead whose reference does NOT resolve. importLead cannot
     * de-dup those (there is no listing to compare on, and matching on contact alone
     * would collapse two genuinely different enquiries), so each is re-imported once
     * per run it stays visible to — 24 times across this window. Recording the
     * portal's own lead id is what would close that; until then it is the accepted
     * cost of not losing leads.
     *
     * Anchoring to midnight instead was both wasteful and lossy at the boundary — a
     * lead arriving after the last run of the day fell outside every window and was
     * never imported.
     *
     * Public so the import command can log the window it ran with.
     */
    public function leadWindowStart(): string
    {
        return now()->subHours(2)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * The origins Property Finder can generate a lead from — the `type` enum on a PF
     * lead. This is PF's own statement about where the lead came from, so it is what
     * the CRM classifies on, never the source an integration happened to type.
     */
    public const LEAD_TYPES = ['listing', 'project', 'developer', 'agent', 'company', 'seller'];

    /**
     * A PROJECT lead is a Primary Plus lead — PF's off-plan product. Every other
     * origin (a listing, an agent's profile, the company page, ...) is an ordinary
     * Property Finder lead, whichever agent or team it happens to land on.
     */
    public const LEAD_TYPE_PROJECT = 'project';

    /**
     * A SELLER lead is an owner asking us to list their property. It carries no
     * listing, project or developer block, so nothing else in the payload reveals it.
     */
    public const LEAD_TYPE_SELLER = 'seller';

    /**
     * Resolve PF's lead origin from the first candidate that names one.
     *
     * The PF API and the lead webhook send the bare enum ("project"). The WhatsApp
     * automation posts it as the sub-channel on technicalSource instead, joined to the
     * channel ("WhatsApp - project", "call - listing"), so the origin is the trailing
     * segment. Returns null when no candidate names a known origin — callers then keep
     * whatever default they already had.
     */
    public function resolveLeadType(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim((string) $candidate));

            if ($candidate === '') {
                continue;
            }

            $segments = preg_split('/\s*-\s*/', $candidate);
            $origin = trim((string) end($segments));

            if (in_array($origin, self::LEAD_TYPES, true)) {
                return $origin;
            }
        }

        return null;
    }

    /**
     * The CRM lead source for a PF lead origin: only project leads are Primary Plus.
     * An unknown/absent origin stays an ordinary Property Finder lead, which is what
     * every PF path defaulted to before.
     */
    public function leadSourceForLeadType(?string $leadType): string
    {
        return $leadType === self::LEAD_TYPE_PROJECT
            ? Constants::LEAD_SOURCE_PROPERTY_FINDER_PLUS
            : Constants::LEAD_SOURCE_PROPERTY_FINDER;
    }

    /**
     * The enquiry type a PF lead origin implies on its own — used when the lead has no
     * listing to derive one from (a project or seller lead never carries one).
     *
     * Primary Plus is the off-plan product, so a project lead is an Off-Plan Investor:
     * a sale-side type that follows exactly the same status, matching and Open Game
     * rules as a Buyer, with the tag identifying it as off-plan. Returns null for every
     * other origin so the caller's listing-derived type stands.
     */
    public function enquiryTypeForLeadType(?string $leadType): ?string
    {
        return match ($leadType) {
            self::LEAD_TYPE_PROJECT => Constants::CONTACT_TYPE_OFF_PLAN_INVESTOR,
            self::LEAD_TYPE_SELLER => Constants::CONTACT_TYPE_SELLER,
            default => null,
        };
    }

    /**
     * Is this incoming lead source one of the Property Finder family, in any of the
     * spellings integrations have sent us ("propertyFinder", "Property Finder",
     * "PfPrimaryPlus", "propertyfinderAe")? Only those are re-classified from the PF
     * lead origin — a Bayut or website lead keeps the source its caller sent.
     */
    public function isPropertyFinderSource(?string $leadSource): bool
    {
        $value = strtolower(preg_replace('/[^a-z]/i', '', (string) $leadSource));

        return str_starts_with($value, 'propertyfinder') || str_starts_with($value, 'pfprimaryplus');
    }

    public function getLeadByChannel($apiKey, $apiSecret)
    {
        $allLeads = [];
        $page = 1;
        $perPage = 50;

        // Resolve a token once for this account so per-lead agent lookups can reuse it.
        $token = $this->getAccessTokenFromApiKeys($apiKey, $apiSecret);

        $createdAtFrom = $this->leadWindowStart();
        do {
            $response = $this->getLeadsFromPF([
                'page' => $page,
                'perPage' => $perPage,
                'createdAtFrom' => $createdAtFrom,
            ], $apiKey, $apiSecret, $token);

            $leads = $response['data'] ?? [];

            if (! empty($leads)) {
                foreach ($leads as $lead) {
                    $record = $this->importLead($lead, $token);
                    // Log::error('[PropertyFinder] lead imported', $record );
                }
            }
            $allLeads = array_merge($allLeads, $leads);

            $hasMore = count($leads) === $perPage;
            $page++;
            // Log::error('Data fetched for page = '.  $page, $leads);
        } while ($hasMore);

        return $allLeads;
    }

    public function getLeadsFromPF(array $params = [], $apiKey = '', $apiSecret = '', $token = '')
    {
        $defaultParams = [
            'orderBy' => 'createdAt',
            'channel' => ['email', 'call'],
        ];

        if (! $token) {
            return [];
        }

        $params = array_merge($defaultParams, $params);

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

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->get(env('PROPERTY_FINDER_API_URL').'/leads?'.$queryString);

        if ($response->failed()) {
            throw new \Exception('PropertyFinder API error: '.$response->body());
        }

        return $response->json();
    }

    private function getAccessTokenFromApiKeys($apiKey, $apiSecret): ?string
    {

        $response = Http::post(
            env('PROPERTY_FINDER_AUTH_URL'),
            [
                'apiKey' => $apiKey,
                'apiSecret' => $apiSecret,
            ]
        );

        if ($response->successful()) {
            return $response->json()['accessToken'] ?? null;
        }

        return null;
    }

    /**
     * Import a single inbound Property Finder lead message into our CRM as an enquiry.
     *
     * The owning agent is resolved from the lead's publicProfile id (the PF agent): we
     * fetch that agent from PF, find them in our system by email, and create an Agent-role
     * user if they don't exist yet. The enquiry is created even when the listing isn't in
     * our system. De-duplicates on contact + listing. Safe to run without an auth context
     * (webhook/cron), so it does not depend on user().
     *
     * @param  array  $lead  A PF lead message payload (keys: channel, sender, listing, publicProfile, ...).
     * @param  string|null  $token  A PF API token for the account (for the agent lookup).
     * @return array{status: string, enquiryId?: int, reason?: string}
     */
    public function importLead(array $lead, ?string $token = null): array
    {
        // 1. Pull the lead contact (the client) out of the PF payload.
        $phone = null;
        $email = null;
        foreach ($lead['sender']['contacts'] ?? [] as $contactInfo) {
            $type = $contactInfo['type'] ?? '';
            $value = $contactInfo['value'] ?? '';
            if ($value === '') {
                continue;
            }
            if ($type === 'phone' && ! $phone) {
                // PF sends E.164 with a leading + ("+971582128994"); normalise so the
                // contact lookup below matches numbers stored in any other shape.
                $phone = normalizePhoneE164((string) $value) ?: (string) $value;
            } elseif ($type === 'email' && ! $email) {
                $email = (string) $value;
            }
        }

        if (! $phone && ! $email) {
            return ['status' => 'skipped', 'reason' => 'no contact phone or email given'];
        }

        // 2. Match the PF listing reference to one of our properties — optional. A lead is
        //    still imported when the listing isn't in our system.
        $reference = (string) ($lead['listing']['reference'] ?? '');
        $property = $reference !== ''
            ? Property::where('referenceNumber', $reference)->first()
            : null;

        $listingType = $property?->listingType;

        // PF states the lead's origin in `type` — only a project lead is Primary Plus,
        // and it names its own enquiry type because it carries no listing to derive one
        // from. Everything else stays an ordinary Property Finder lead.
        $leadType = $this->resolveLeadType($lead['type'] ?? null);

        $contactType = $this->enquiryTypeForLeadType($leadType)
            ?? ($listingType === 'rent'
                ? Constants::CONTACT_TYPE_TENANT
                : Constants::CONTACT_TYPE_BUYER);

        // 3. Find the contact — phone first, email only as a fallback. Matching on the
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

        // 4. De-dup: skip only when this contact already has a live enquiry on the same
        //    listing that is LESS than 24 hours old.
        //
        //    - Needs a matched listing. When the lead's reference doesn't resolve to one
        //      of our properties there is nothing specific to compare against, so the
        //      lead is always imported rather than folded into an unrelated enquiry.
        //    - The 24-hour window is what makes a re-pull a no-op (the importer re-reads
        //      its whole window every run) without blocking a genuine re-enquiry later.
        //      The previous unbounded check blocked that forever.
        //    - Soft-deleted enquiries are ignored: a deleted enquiry is meant to be gone,
        //      so the next lead from that client rebuilds it instead of silently skipping.
        if ($contact && $property) {
            $existing = Enquiry::where('contactId', $contact->id)
                ->where('listingId', $property->id)
                ->where('created_at', '>=', now()->subDay())
                ->latest('id')
                ->first();

            if ($existing) {
                Log::channel('enquiry')->info('Enquiry skipped because it already exists.', [
                    'channel' => $lead['channel'] ?? null,
                    'referenceNo' => $existing->referenceNo,
                ]);

                return ['status' => 'skipped', 'reason' => 'already exists', 'enquiryId' => $existing->id];
            }
        }

        // 5. Resolve the owning agent from PF's publicProfile id. Find them in our system by
        //    email (creating the agent if missing); fall back to the house agent below.
        //    Deliberately after the de-dup checks: this is the only step that makes an
        //    outbound API call, so a lead we are going to skip must never pay for it.
        $publicProfileId = isset($lead['publicProfile']['id'])
            ? (int) $lead['publicProfile']['id']
            : null;
        $agentId = $this->resolveAgentIdFromPfProfile($publicProfileId, $token);

        $name = trim((string) ($lead['sender']['name'] ?? ''));

        if (empty($name)) {
            $name = $phone ? "Contact - {$phone}" : 'Contact PropertyFinder';
        }

        // 6. Create the contact if it's new.
        if (! $contact) {
            $contact = Contact::create([
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'contactType' => [$contactType],
                'contactOwner' => $agentId,
                'source' => $this->leadSourceForLeadType($leadType),
                'createdBy' => $agentId,
            ]);
        } else {
            // Merge — keep any existing roles this contact already has.
            $contact->addContactTypes($contactType);
        }

        // 7. Map the PF channel to our technicalSource key (whatsApp | email | call).
        $channel = strtolower((string) ($lead['channel'] ?? ''));
        $technicalSource = match ($channel) {
            'whatsapp' => 'whatsApp',
            'email' => 'email',
            'call' => 'call',
            default => null,
        };

        if (! $agentId) {
            $user = User::where('email', 'louise@exclusive-links.com')->first(); // Get the main agent
            if ($user) {
                $agentId = $user->id;
            }
        }
        $referenceNo = generateEnquiryReferenceNo();

        // 8. Open the enquiry. Reference number comes from the centralized generator.
        $enquiry = Enquiry::create([
            'agentId' => $agentId,
            'contactId' => $contact->id,
            'listingId' => $property?->id,
            'referenceNo' => $referenceNo,
            'phone' => $phone,
            'status' => Constants::ENQUIRY_NEW_LEAD,
            'leadSource' => $this->leadSourceForLeadType($leadType),
            'technicalSource' => $technicalSource,
            'listingType' => $listingType,
            'enquiryType' => $contactType,
            'createdBy' => $agentId,
        ]);

        Log::channel('enquiry')->info('New Enquiry created.', [
            'agent_id' => $agentId,
            'public_profile_id' => $publicProfileId,
            'source' => 'propertyfinder',
            'lead_type' => $leadType,
            'channel' => $lead['channel'] ?? null,
            'referenceNo' => $referenceNo,
        ]);

        return ['status' => 'created', 'enquiryId' => $enquiry->id];
    }

    /**
     * Resolve the CRM agent (user) id for a PF lead from its publicProfile id.
     *
     * Fetches the agent's account from Property Finder, then finds our user by email —
     * creating an Agent-role user when none exists. Returns null when the profile can't be
     * resolved (no id/token, PF lookup fails, or no email), so the caller can fall back.
     */
    protected function resolveAgentIdFromPfProfile(?int $publicProfileId, ?string $token): ?int
    {
        if (! $publicProfileId || ! $token) {
            return null;
        }

        $pfAgent = $this->fetchPfUserByPublicProfileId($publicProfileId, $token);
        $email = $pfAgent['email'] ?? null;

        if (! $email) {
            return null;
        }

        // Find our agent by email first; only create when they don't exist. An
        // existing-but-inactive agent (a departed ex-agent) must NOT receive
        // leads, so return null in that case and let the caller fall back to the
        // house agent — never create a duplicate for an already-taken email.
        $user = User::where('email', $email)->first();
        if ($user) {
            return $user->status === Constants::USER_STATUS_ACTIVE ? $user->id : null;
        }

        $user = User::create([
            'roleId' => Role::where('name', Constants::AGENT)->value('id'),
            'name' => $pfAgent['name'] ?? ('PF Agent '.$publicProfileId),
            'email' => $email,
            'status' => Constants::USER_STATUS_ACTIVE,
            'agentType' => 'agent',
            'password' => Hash::make(Str::random(40)),
            'force_password_change' => true,
        ]);

        return $user->id;
    }

    /**
     * Fetch a PF agent's account details (email, name) by their publicProfile id.
     *
     * NOTE: confirm the PF param for filtering /users by public profile id; we also scan the
     * returned records for an exact publicProfile.id match.
     *
     * @return array{email: ?string, name: ?string}|null
     */
    protected function fetchPfUserByPublicProfileId(int $publicProfileId, string $token): ?array
    {
        try {
            $response = $this->http($token)->get('/users', ['publicProfileId' => $publicProfileId]);

            if (! $response->successful()) {
                Log::error('[PropertyFinder API] public profile lookup failed', [
                    'publicProfileId' => $publicProfileId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $records = $response->json('data') ?? [];
            $match = null;
            foreach ($records as $record) {
                if ((int) ($record['publicProfile']['id'] ?? 0) === $publicProfileId) {
                    $match = $record;
                    break;
                }
            }
            $match = $match ?? ($records[0] ?? null);

            if (! $match) {
                return null;
            }

            $name = $match['name']
                ?? trim((string) (($match['firstName'] ?? '').' '.($match['lastName'] ?? '')));

            return [
                'email' => $match['email'] ?? null,
                'name' => $name !== '' ? $name : null,
            ];
        } catch (\Throwable $e) {
            Log::error('[PropertyFinder API] public profile lookup threw', [
                'publicProfileId' => $publicProfileId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
