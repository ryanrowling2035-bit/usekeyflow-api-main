<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Jobs\PublishDraftToPortalJob;
use App\Jobs\SyncPropertyToPortalsJob;
use App\Models\PortalSetting;
use App\Models\PortalWebhookSubscription;
use App\Models\Property;
use App\Models\PropertyMeta;
use App\Services\BayutService;
use App\Services\OpenAiService;
use App\Services\PropertyFinderService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class ThirdPartyController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected BayutService $bayutService,
        protected PropertyFinderService $propertyFinderService,
        protected OpenAiService $openAiService
    ) {}

    /**
     * Manually pull Bayut leads right now — triggers the same importer the daily
     * scheduler runs (routes/console.php), iterating every active Bayut account,
     * fetching recent leads from the Bayut API and turning them into CRM enquiries.
     */
    public function importBayutLeads(Request $request)
    {
        try {
            Artisan::call('app:import-bayut-leads');

            return $this->success(['output' => trim(Artisan::output())], 'Bayut lead import completed.');
        } catch (\Throwable $e) {
            return $this->error('Failed to import Bayut leads', 500, ['message' => $e->getMessage()]);
        }
    }

    public function searchThirdPartyLocations(Request $request)
    {
        try {
            $params = $request->query();

            $bayutResult = $this->bayutService->searchLocations($params);
            $pfResult = $this->propertyFinderService->searchLocations($params);

            return $this->success([
                'bayut' => $bayutResult['success'] ? ($bayutResult['data'] ?? []) : [],
                'pf' => $pfResult['success'] ? ($pfResult['data'] ?? []) : [],
            ], 'Third party locations fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch third party locations', 500, ['message' => $e->getMessage()]);
        }
    }

    public function getPFLocations(Request $request)
    {
        try {
            $result = $this->propertyFinderService->getPFLocations($request->query());

            if (! $result['success']) {
                return $this->error($result['error'] ?? 'Failed to fetch Property Finder location', $result['status'] ?? 500, [
                    'details' => $result['details'] ?? null,
                ]);
            }

            return $this->success($result['data'], 'Property Finder location fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch Property Finder location', 500, ['message' => $e->getMessage()]);
        }
    }

    public function generatePropertyDescription(Request $request)
    {
        try {
            $type = $request->input('type', 'description');

            if (! in_array($type, ['description', 'shortDescription', 'flyer'], true)) {
                return $this->error('Invalid type. Must be description, shortDescription or flyer', 422);
            }

            $payload = $request->except(['type']);

            $result = match ($type) {
                'flyer' => $this->openAiService->generateFlyerDescription($payload),
                'shortDescription' => $this->openAiService->generatePropertyDescription($payload, true),
                default => $this->openAiService->generatePropertyDescription($payload),
            };

            if (! $result['success']) {
                return $this->error($result['error'] ?? 'Failed to generate description', $result['status'] ?? 500, [
                    'details' => $result['details'] ?? null,
                ]);
            }

            return $this->success($result['data'], 'Description generated successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to generate description', 500, ['message' => $e->getMessage()]);
        }
    }

    public function publishToBayut(Request $request)
    {
        try {
            $validated = $request->validate([
                'propertyId' => 'required|integer|exists:properties,id',
                'account' => 'required|string',
                'product' => 'sometimes|in:hot,signature,featured,premium',
                'dryRun' => 'sometimes|boolean',
            ]);

            $property = Property::find($validated['propertyId']);

            if (! $property) {
                return $this->error('Property not found', 404);
            }

            $portalSetting = PortalSetting::where('slug', $validated['account'])
                ->where('portal', 'bayut')
                ->first();

            if (! $portalSetting) {
                return $this->error("Bayut portal account not found for slug '{$validated['account']}'", 404);
            }

            $options = collect($validated)->except(['propertyId', 'account'])->toArray();

            $existingMeta = PropertyMeta::where('propertyId', $property->id)
                ->where('metaKey', 'bayutPortalListingData')
                ->value('metaValue');
            $existingListing = $existingMeta ? json_decode((string) $existingMeta, true) : null;
            $existingListingId = $existingListing['externalListingId'] ?? null;

            // No meta linking this property to a Bayut listing yet. Before publishing
            // (which would create a duplicate), check whether a listing with our
            // reference already exists on Bayut. If it does, don't publish — just fetch
            // it and store the listing data into meta, then we're done.
            if (! $existingListingId) {
                $reconciledId = $this->bayutService->findExternalIdByReference($property, $portalSetting);

                if ($reconciledId) {
                    $result = $this->bayutService->fetchListingById($property, (string) $reconciledId, $portalSetting);

                    if (! ($result['success'] ?? false)) {
                        return $this->error(
                            $result['error'] ?? 'Failed to fetch existing Bayut listing',
                            $result['status'] ?? 500,
                            array_filter([
                                'stage' => $result['stage'] ?? null,
                                'details' => $result['details'] ?? null,
                            ], fn ($v) => $v !== null)
                        );
                    }

                    return $this->success([
                        'bayutPropertyId' => (string) $reconciledId,
                        'listingData' => $result['listingData'] ?? null,
                    ], 'Property already exists on Bayut — listing synced from portal');
                }
            }

            $result = $existingListingId
                ? $this->bayutService->updateProperty($property, $portalSetting, (string) $existingListingId)
                : $this->bayutService->publishProperty($property, $portalSetting, $options);

            if (! ($result['success'] ?? false)) {
                return $this->error(
                    $result['error'] ?? 'Failed to publish to Bayut',
                    $result['status'] ?? 500,
                    array_filter([
                        'stage' => $result['stage'] ?? null,
                        'details' => $result['details'] ?? null,
                        'bayutPropertyId' => $result['bayutPropertyId'] ?? null,
                    ], fn ($v) => $v !== null)
                );
            }

            if (! empty($result['payload'])) {
                return $this->success(['payload' => $result['payload']], 'Bayut payload built (dry run)');
            }

            // After a successful publish, take the draft live a minute later (publish-draft/bayut).
            PublishDraftToPortalJob::dispatch($property->id, $validated['account'], 'bayut')
                ->delay(now()->addMinute());

            return $this->success([
                'bayutPropertyId' => $result['bayutPropertyId'],
                'permitValidated' => $result['permitValidated'] ?? false,
            ], $result['message'] ?? 'Property published to Bayut');
        } catch (\Exception $e) {
            return $this->error('Failed to publish to Bayut', 500, ['message' => $e->getMessage()]);
        }
    }

    public function publishDraftToBayut(Request $request)
    {
        try {
            $validated = $request->validate([
                'propertyId' => 'required|integer|exists:properties,id',
                'account' => 'required|string',
            ]);

            $property = Property::find($validated['propertyId']);

            if (! $property) {
                return $this->error('Property not found', 404);
            }

            $portalSetting = PortalSetting::where('slug', $validated['account'])
                ->where('portal', 'bayut')
                ->first();

            if (! $portalSetting) {
                return $this->error("Bayut portal account not found for slug '{$validated['account']}'", 404);
            }

            $existingMeta = PropertyMeta::where('propertyId', $property->id)
                ->where('metaKey', 'bayutPortalListingData')
                ->value('metaValue');
            $existingListing = $existingMeta ? json_decode((string) $existingMeta, true) : null;
            $existingListingId = $existingListing['externalListingId'] ?? null;

            if (! $existingListingId) {
                return $this->error('No Bayut draft found to publish for this property', 404);
            }

            $result = $this->bayutService->publishDraftListing($property, $portalSetting, (string) $existingListingId);

            if (! ($result['success'] ?? false)) {
                return $this->error(
                    $result['error'] ?? 'Failed to publish Bayut draft',
                    $result['status'] ?? 500,
                    array_filter([
                        'stage' => $result['stage'] ?? null,
                        'details' => $result['details'] ?? null,
                        'bayutPropertyId' => $result['bayutPropertyId'] ?? null,
                    ], fn ($v) => $v !== null)
                );
            }

            return $this->success([
                'bayutPropertyId' => $result['bayutPropertyId'],
            ], $result['message'] ?? 'Property published to Bayut');
        } catch (\Exception $e) {
            return $this->error('Failed to publish Bayut draft', 500, ['message' => $e->getMessage()]);
        }
    }

    public function publishToPropertyFinder(Request $request)
    {
        try {
            $validated = $request->validate([
                'propertyId' => 'required|integer|exists:properties,id',
                'account' => 'required|string',
                'dryRun' => 'sometimes|boolean',
            ]);

            $property = Property::find($validated['propertyId']);

            if (! $property) {
                return $this->error('Property not found', 404);
            }

            $portalSetting = PortalSetting::where('slug', $validated['account'])
                ->where('portal', 'propertyFinder')
                ->first();

            if (! $portalSetting) {
                return $this->error("Property Finder portal account not found for slug '{$validated['account']}'", 404);
            }

            $options = collect($validated)->except(['propertyId', 'account'])->toArray();

            $existingMeta = PropertyMeta::where('propertyId', $property->id)
                ->where('metaKey', 'pfPortalListingData')
                ->value('metaValue');
            $existingListing = $existingMeta ? json_decode((string) $existingMeta, true) : null;
            $existingListingId = $existingListing['externalListingId'] ?? null;

            // No meta linking this property to a PF listing yet. Before publishing
            // (which would create a duplicate), check whether a listing with our
            // reference already exists on Property Finder. If it does, don't publish —
            // just fetch it and store the listing data into meta, then we're done.
            if (! $existingListingId) {
                $reconciledId = $this->propertyFinderService->findExternalIdByReference($property, $portalSetting);

                if ($reconciledId) {
                    $result = $this->propertyFinderService->fetchListingById($property, (string) $reconciledId, $portalSetting);

                    if (! ($result['success'] ?? false)) {
                        return $this->error(
                            $result['error'] ?? 'Failed to fetch existing Property Finder listing',
                            $result['status'] ?? 500,
                            array_filter([
                                'stage' => $result['stage'] ?? null,
                                'details' => $result['details'] ?? null,
                            ], fn ($v) => $v !== null)
                        );
                    }

                    return $this->success([
                        'pfListingId' => (string) $reconciledId,
                        'listingData' => $result['listingData'] ?? null,
                    ], 'Property already exists on Property Finder — listing synced from portal');
                }
            }

            $result = $existingListingId
                ? $this->propertyFinderService->updateProperty($property, $portalSetting, (string) $existingListingId)
                : $this->propertyFinderService->publishProperty($property, $portalSetting, $options);

            if (! ($result['success'] ?? false)) {
                return $this->error(
                    $result['error'] ?? 'Failed to publish to Property Finder',
                    $result['status'] ?? 500,
                    array_filter([
                        'stage' => $result['stage'] ?? null,
                        'details' => $result['details'] ?? null,
                        'pfListingId' => $result['pfListingId'] ?? null,
                    ], fn ($v) => $v !== null)
                );
            }

            if (! empty($result['payload'])) {
                return $this->success(['payload' => $result['payload']], 'Property Finder payload built (dry run)');
            }

            // After a successful publish, take the draft live a minute later (publish-draft/property-finder).
            PublishDraftToPortalJob::dispatch($property->id, $validated['account'], 'propertyFinder')
                ->delay(now()->addMinute());

            return $this->success([
                'pfListingId' => $result['pfListingId'],
                'state' => $result['state'] ?? null,
            ], $result['message'] ?? 'Property published to Property Finder');
        } catch (\Exception $e) {
            return $this->error('Failed to publish to Property Finder', 500, ['message' => $e->getMessage()]);
        }
    }

    /**
     * Register Property Finder Atlas webhook subscriptions for the given account so
     * PF posts listing lifecycle events (published / unpublished / action) to our
     * callback — received by ExternalController@capturePropertyFinderWebhook.
     */
    public function subscribePropertyFinderWebhooks(Request $request)
    {
        try {
            $validated = $request->validate([
                'slug' => 'required|string',
            ]);

            $portalSetting = PortalSetting::where('slug', $validated['slug'])
                ->where('portal', 'propertyFinder')
                ->first();

            if (! $portalSetting) {
                return $this->error("Property Finder portal account not found for slug '{$validated['slug']}'", 404);
            }

            // Webhook config comes from env.
            $callbackUrl = env('PROPERTY_FINDER_WEBHOOK_CALLBACK_URL', 'https://api.usekeyflow.com/webhooks/property-finder');
            $secret = env('PROPERTY_FINDER_WEBHOOK_SECRET', '');

            $eventsEnv = env('PROPERTY_FINDER_WEBHOOK_EVENTS');
            $eventIds = $eventsEnv
                ? array_values(array_filter(array_map('trim', explode(',', $eventsEnv))))
                : ['listing.published', 'listing.unpublished', 'listing.action', 'lead.created'];

            if (! $secret) {
                return $this->error('Property Finder webhook secret is not configured', 422);
            }

            $result = $this->propertyFinderService->subscribeWebhooks($portalSetting, $eventIds, $callbackUrl, $secret);

            // Persist each event's outcome (success or failure) so the current
            // subscription state can be checked later.
            foreach ($result['subscriptions'] ?? [] as $subscription) {
                PortalWebhookSubscription::updateOrCreate(
                    [
                        'portal' => 'propertyFinder',
                        'slug' => $portalSetting->slug,
                        'eventId' => $subscription['eventId'],
                    ],
                    [
                        'webhookId' => $subscription['webhookId'] ?? null,
                        'callbackUrl' => $callbackUrl,
                        'status' => ($subscription['success'] ?? false) ? 'subscribed' : 'failed',
                        'statusCode' => $subscription['status'] ?? null,
                        'error' => $subscription['error'] ?? null,
                        'response' => $subscription,
                    ]
                );
            }

            if (! ($result['success'] ?? false)) {
                return $this->error(
                    $result['error'] ?? 'Failed to subscribe one or more Property Finder webhooks',
                    $result['status'] ?? 500,
                    array_filter(['subscriptions' => $result['subscriptions'] ?? null], fn ($v) => $v !== null)
                );
            }

            return $this->success(['subscriptions' => $result['subscriptions']], 'Property Finder webhooks subscribed');
        } catch (\Exception $e) {
            return $this->error('Failed to subscribe Property Finder webhooks', 500, ['message' => $e->getMessage()]);
        }
    }

    /**
     * List stored Property Finder webhook subscriptions with the linked portal
     * account (joined on slug). Optionally filter to a single account via ?slug=.
     */
    public function getPropertyFinderWebhookSubscriptions(Request $request)
    {
        try {
            $subscriptions = PortalWebhookSubscription::with('portalSetting:id,portal,branchName,slug,status')
                ->where('portal', 'propertyFinder')
                ->when($request->query('slug'), fn ($q, $slug) => $q->where('slug', $slug))
                ->orderByDesc('updated_at')
                ->get();

            return $this->success($subscriptions, 'Property Finder webhook subscriptions fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch Property Finder webhook subscriptions', 500, ['message' => $e->getMessage()]);
        }
    }

    public function publishDraftToPropertyFinder(Request $request)
    {
        try {
            $validated = $request->validate([
                'propertyId' => 'required|integer|exists:properties,id',
                'account' => 'required|string',
            ]);

            $property = Property::find($validated['propertyId']);

            if (! $property) {
                return $this->error('Property not found', 404);
            }

            $portalSetting = PortalSetting::where('slug', $validated['account'])
                ->where('portal', 'propertyFinder')
                ->first();

            if (! $portalSetting) {
                return $this->error("Property Finder portal account not found for slug '{$validated['account']}'", 404);
            }

            $existingMeta = PropertyMeta::where('propertyId', $property->id)
                ->where('metaKey', 'pfPortalListingData')
                ->value('metaValue');
            $existingListing = $existingMeta ? json_decode((string) $existingMeta, true) : null;
            $existingListingId = $existingListing['externalListingId'] ?? null;

            if (! $existingListingId) {
                return $this->error('No Property Finder draft found to publish for this property', 404);
            }

            $options = collect($validated)->except(['propertyId', 'account'])->toArray();

            $result = $this->propertyFinderService->publishDraftListing($property, $portalSetting, (string) $existingListingId, $options);

            if (! ($result['success'] ?? false)) {
                return $this->error(
                    $result['error'] ?? 'Failed to publish Property Finder draft',
                    $result['status'] ?? 500,
                    array_filter([
                        'stage' => $result['stage'] ?? null,
                        'details' => $result['details'] ?? null,
                        'pfListingId' => $result['pfListingId'] ?? null,
                    ], fn ($v) => $v !== null)
                );
            }

            return $this->success([
                'pfListingId' => $result['pfListingId'],
                'state' => $result['state'] ?? null,
            ], $result['message'] ?? 'Property published to Property Finder');
        } catch (\Exception $e) {
            return $this->error('Failed to publish Property Finder draft', 500, ['message' => $e->getMessage()]);
        }
    }

    public function updateOnBayut(Request $request)
    {
        try {
            $validated = $request->validate([
                'propertyId' => 'required|integer|exists:properties,id',
                'account' => 'required|string',
                'dryRun' => 'sometimes|boolean',
            ]);

            $property = Property::find($validated['propertyId']);

            if (! $property) {
                return $this->error('Property not found', 404);
            }

            $portalSetting = PortalSetting::where('slug', $validated['account'])
                ->where('portal', 'bayut')
                ->first();

            if (! $portalSetting) {
                return $this->error("Bayut portal account not found for slug '{$validated['account']}'", 404);
            }

            $existingMeta = PropertyMeta::where('propertyId', $property->id)
                ->where('metaKey', 'bayutPortalListingData')
                ->value('metaValue');
            $existingListing = $existingMeta ? json_decode((string) $existingMeta, true) : null;
            $existingListingId = $existingListing['externalListingId'] ?? null;

            // Imported listings have no externalListingId in meta — try to reconcile by reference.
            if (! $existingListingId) {
                $existingListingId = $this->bayutService->findExternalIdByReference($property, $portalSetting);
            }

            if (! $existingListingId) {
                return $this->error('No existing Bayut listing found to update for this property', 404);
            }

            $result = $this->bayutService->updateProperty($property, $portalSetting, (string) $existingListingId);

            if (! ($result['success'] ?? false)) {
                return $this->error(
                    $result['error'] ?? 'Failed to update on Bayut',
                    $result['status'] ?? 500,
                    array_filter([
                        'stage' => $result['stage'] ?? null,
                        'details' => $result['details'] ?? null,
                        'bayutPropertyId' => $result['bayutPropertyId'] ?? null,
                    ], fn ($v) => $v !== null)
                );
            }

            if (! empty($result['payload'])) {
                return $this->success(['payload' => $result['payload']], 'Bayut payload built (dry run)');
            }

            return $this->success([
                'bayutPropertyId' => $result['bayutPropertyId'],
                'permitValidated' => $result['permitValidated'] ?? false,
            ], $result['message'] ?? 'Property updated on Bayut');
        } catch (\Exception $e) {
            return $this->error('Failed to update on Bayut', 500, ['message' => $e->getMessage()]);
        }
    }

    public function updateOnPropertyFinder(Request $request)
    {
        try {
            $validated = $request->validate([
                'propertyId' => 'required|integer|exists:properties,id',
                'account' => 'required|string',
                'dryRun' => 'sometimes|boolean',
            ]);

            $property = Property::find($validated['propertyId']);

            if (! $property) {
                return $this->error('Property not found', 404);
            }

            $portalSetting = PortalSetting::where('slug', $validated['account'])
                ->where('portal', 'propertyFinder')
                ->first();

            if (! $portalSetting) {
                return $this->error("Property Finder portal account not found for slug '{$validated['account']}'", 404);
            }

            $existingMeta = PropertyMeta::where('propertyId', $property->id)
                ->where('metaKey', 'pfPortalListingData')
                ->value('metaValue');
            $existingListing = $existingMeta ? json_decode((string) $existingMeta, true) : null;
            $existingListingId = $existingListing['externalListingId'] ?? null;

            // Imported listings have no externalListingId in meta — try to reconcile by reference.
            if (! $existingListingId) {
                $existingListingId = $this->propertyFinderService->findExternalIdByReference($property, $portalSetting);
            }

            if (! $existingListingId) {
                return $this->error('No existing Property Finder listing found to update for this property', 404);
            }

            $result = $this->propertyFinderService->updateProperty($property, $portalSetting, (string) $existingListingId);

            if (! ($result['success'] ?? false)) {
                return $this->error(
                    $result['error'] ?? 'Failed to update on Property Finder',
                    $result['status'] ?? 500,
                    array_filter([
                        'stage' => $result['stage'] ?? null,
                        'details' => $result['details'] ?? null,
                        'pfListingId' => $result['pfListingId'] ?? null,
                    ], fn ($v) => $v !== null)
                );
            }

            if (! empty($result['payload'])) {
                return $this->success(['payload' => $result['payload']], 'Property Finder payload built (dry run)');
            }

            return $this->success([
                'pfListingId' => $result['pfListingId'],
                'state' => $result['state'] ?? null,
            ], $result['message'] ?? 'Property updated on Property Finder');
        } catch (\Exception $e) {
            return $this->error('Failed to update on Property Finder', 500, ['message' => $e->getMessage()]);
        }
    }

    public function deleteFromBayut(Request $request)
    {
        try {
            $validated = $request->validate([
                'propertyId' => 'required|integer|exists:properties,id',
                'account' => 'required|string',
            ]);

            $property = Property::find($validated['propertyId']);

            if (! $property) {
                return $this->error('Property not found', 404);
            }

            $portalSetting = PortalSetting::where('slug', $validated['account'])
                ->where('portal', 'bayut')
                ->first();

            if (! $portalSetting) {
                return $this->error("Bayut portal account not found for slug '{$validated['account']}'", 404);
            }

            $existingMeta = PropertyMeta::where('propertyId', $property->id)
                ->where('metaKey', 'bayutPortalListingData')
                ->value('metaValue');
            $existingListing = $existingMeta ? json_decode((string) $existingMeta, true) : null;
            $existingListingId = $existingListing['externalListingId'] ?? null;

            // Imported listings have no externalListingId in meta — reconcile by reference.
            if (! $existingListingId) {
                $existingListingId = $this->bayutService->findExternalIdByReference($property, $portalSetting);
            }

            if (! $existingListingId) {
                return $this->error('No Bayut listing found to delete for this property', 404);
            }

            // Attempt the portal unpublish, but don't gate meta cleanup on its outcome.
            $result = $this->bayutService->unpublishProperty($property, $portalSetting, (string) $existingListingId);

            // Archive the current listing data into the next free numbered backup
            // key (bayutPortalListingData_1, _2, ...) regardless of portal status.
            $currentValue = PropertyMeta::where('propertyId', $property->id)
                ->where('metaKey', 'bayutPortalListingData')
                ->value('metaValue');

            if ($currentValue !== null) {
                $index = 1;
                while (PropertyMeta::where('propertyId', $property->id)
                    ->where('metaKey', "bayutPortalListingData_{$index}")
                    ->exists()) {
                    $index++;
                }

                PropertyMeta::create([
                    'propertyId' => $property->id,
                    'metaKey' => "bayutPortalListingData_{$index}",
                    'metaValue' => $currentValue,
                ]);

                // Remove the original row so the active listing key is cleared once archived.
                PropertyMeta::where('propertyId', $property->id)
                    ->where('metaKey', 'bayutPortalListingData')
                    ->delete();
            }

            return $this->success([
                'bayutPropertyId' => $result['bayutPropertyId'] ?? (string) $existingListingId,
            ], $result['message'] ?? 'Bayut listing deleted');
        } catch (\Exception $e) {
            return $this->error('Failed to delete Bayut listing', 500, ['message' => $e->getMessage()]);
        }
    }

    public function deleteFromPropertyFinder(Request $request)
    {
        try {
            $validated = $request->validate([
                'propertyId' => 'required|integer|exists:properties,id',
                'account' => 'required|string',
            ]);

            $property = Property::find($validated['propertyId']);

            if (! $property) {
                return $this->error('Property not found', 404);
            }

            $portalSetting = PortalSetting::where('slug', $validated['account'])
                ->where('portal', 'propertyFinder')
                ->first();

            if (! $portalSetting) {
                return $this->error("Property Finder portal account not found for slug '{$validated['account']}'", 404);
            }

            $existingMeta = PropertyMeta::where('propertyId', $property->id)
                ->where('metaKey', 'pfPortalListingData')
                ->value('metaValue');
            $existingListing = $existingMeta ? json_decode((string) $existingMeta, true) : null;
            $existingListingId = $existingListing['externalListingId'] ?? null;

            // Imported listings have no externalListingId in meta — reconcile by reference.
            if (! $existingListingId) {
                $existingListingId = $this->propertyFinderService->findExternalIdByReference($property, $portalSetting);
            }

            if (! $existingListingId) {
                return $this->error('No Property Finder listing found to delete for this property', 404);
            }

            // Attempt the portal unpublish, but don't gate meta cleanup on its outcome.
            $result = $this->propertyFinderService->unpublishProperty($property, $portalSetting, (string) $existingListingId);

            // Archive the current listing data into the next free numbered backup
            // key (pfPortalListingData_1, _2, ...) regardless of portal status.
            $currentValue = PropertyMeta::where('propertyId', $property->id)
                ->where('metaKey', 'pfPortalListingData')
                ->value('metaValue');

            if ($currentValue !== null) {
                $index = 1;
                while (PropertyMeta::where('propertyId', $property->id)
                    ->where('metaKey', "pfPortalListingData_{$index}")
                    ->exists()) {
                    $index++;
                }

                PropertyMeta::create([
                    'propertyId' => $property->id,
                    'metaKey' => "pfPortalListingData_{$index}",
                    'metaValue' => $currentValue,
                ]);

                // Remove the original row so the active listing key is cleared once archived.
                PropertyMeta::where('propertyId', $property->id)
                    ->where('metaKey', 'pfPortalListingData')
                    ->delete();
            }

            return $this->success([
                'pfListingId' => $result['pfListingId'] ?? (string) $existingListingId,
            ], $result['message'] ?? 'Property Finder listing deleted');
        } catch (\Exception $e) {
            return $this->error('Failed to delete Property Finder listing', 500, ['message' => $e->getMessage()]);
        }
    }

    public function fetchPropertyFinderListings(Request $request)
    {
        try {
            $filters = $request->validate([
                'page' => 'sometimes|integer|min:1',
                'perPage' => 'sometimes|integer|min:1|max:100',
                'status' => 'sometimes|string|max:50',
            ]);

            $result = $this->propertyFinderService->fetchListings($filters);

            if (! ($result['success'] ?? false)) {
                return $this->error(
                    $result['error'] ?? 'Failed to fetch Property Finder listings',
                    $result['status'] ?? 500,
                    array_filter([
                        'stage' => $result['stage'] ?? null,
                        'details' => $result['details'] ?? null,
                    ], fn ($v) => $v !== null)
                );
            }

            return $this->success([
                'listings' => $result['listings'],
                'pagination' => $result['pagination'],
            ], 'Property Finder listings fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch Property Finder listings', 500, ['message' => $e->getMessage()]);
        }
    }

    public function fetchPropertyFinderListingById(Request $request, $propertyId)
    {
        try {
            $property = Property::find($propertyId);

            if (! $property) {
                return $this->error('Property not found', 404);
            }

            // The PF listing link + account slug are stored on the property when it
            // was published/updated. Account can be overridden via query param.
            $existingMeta = PropertyMeta::where('propertyId', $property->id)
                ->where('metaKey', 'pfPortalListingData')
                ->value('metaValue');
            $existingListing = $existingMeta ? json_decode((string) $existingMeta, true) : null;

            $externalListingId = $existingListing['externalListingId'] ?? null;
            $account = $request->input('account', $existingListing['account'] ?? null);

            if (! $account) {
                return $this->error('No Property Finder account associated with this property', 404);
            }

            $portalSetting = PortalSetting::where('slug', $account)
                ->where('portal', 'propertyFinder')
                ->first();

            if (! $portalSetting) {
                return $this->error("Property Finder portal account not found for slug '{$account}'", 404);
            }

            // Imported listings have no externalListingId in meta — reconcile by reference.
            if (! $externalListingId) {
                $externalListingId = $this->propertyFinderService->findExternalIdByReference($property, $portalSetting);
            }

            if (! $externalListingId) {
                return $this->error('No Property Finder listing found for this property', 404);
            }

            $result = $this->propertyFinderService->fetchListingById($property, (string) $externalListingId, $portalSetting);

            if (! ($result['success'] ?? false)) {
                return $this->error(
                    $result['error'] ?? 'Failed to fetch Property Finder listing',
                    $result['status'] ?? 500,
                    array_filter([
                        'stage' => $result['stage'] ?? null,
                        'details' => $result['details'] ?? null,
                    ], fn ($v) => $v !== null)
                );
            }

            return $this->success([
                'listing' => $result['listing'],
                'listingData' => $result['listingData'] ?? null,
                'scope' => $result['scope'] ?? null,
                'externalListingId' => (string) $externalListingId,
                'account' => $portalSetting->slug,
            ], 'Property Finder listing fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch Property Finder listing', 500, ['message' => $e->getMessage()]);
        }
    }

    public function fetchBayutListingById(Request $request, $propertyId)
    {
        try {
            $property = Property::find($propertyId);

            if (! $property) {
                return $this->error('Property not found', 404);
            }

            // The Bayut listing id + account slug are stored on the property when it
            // was published/updated. Account can be overridden via query param.
            $existingMeta = PropertyMeta::where('propertyId', $property->id)
                ->where('metaKey', 'bayutPortalListingData')
                ->value('metaValue');
            $existingListing = $existingMeta ? json_decode((string) $existingMeta, true) : null;

            $externalListingId = $existingListing['externalListingId'] ?? null;
            $account = $request->input('account', $existingListing['account'] ?? null);

            if (! $account) {
                return $this->error('No Bayut account associated with this property', 404);
            }

            $portalSetting = PortalSetting::where('slug', $account)
                ->where('portal', 'bayut')
                ->first();

            if (! $portalSetting) {
                return $this->error("Bayut portal account not found for slug '{$account}'", 404);
            }

            // Imported listings have no externalListingId in meta — reconcile by reference.
            if (! $externalListingId) {
                $externalListingId = $this->bayutService->findExternalIdByReference($property, $portalSetting);
            }

            if (! $externalListingId) {
                return $this->error('No Bayut listing found for this property', 404);
            }

            $result = $this->bayutService->fetchListingById($property, (string) $externalListingId, $portalSetting);

            if (! ($result['success'] ?? false)) {
                return $this->error(
                    $result['error'] ?? 'Failed to fetch Bayut listing',
                    $result['status'] ?? 500,
                    array_filter([
                        'stage' => $result['stage'] ?? null,
                        'details' => $result['details'] ?? null,
                    ], fn ($v) => $v !== null)
                );
            }

            return $this->success([
                'listing' => $result['listing'],
                'listingData' => $result['listingData'] ?? null,
                'externalListingId' => (string) $externalListingId,
                'account' => $portalSetting->slug,
            ], 'Bayut listing fetched successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch Bayut listing', 500, ['message' => $e->getMessage()]);
        }
    }

    /**
     * Revert this property's live Bayut / Property Finder listings back to draft.
     * POST /property/{propertyId}/portal-unpublish
     */
    public function unpublishFromPortals($propertyId)
    {
        $property = Property::find($propertyId);

        if (! $property) {
            return $this->error('The requested property was not found.', 422);
        }

        $hasPortalListing = PropertyMeta::where('propertyId', $property->id)
            ->whereIn('metaKey', ['bayutPortalListingData', 'pfPortalListingData'])
            ->exists();

        if (! $hasPortalListing) {
            return $this->error('This property is not currently published to any portal.', 422);
        }

        try {
            $portalListings = PropertyMeta::where('propertyId', $property->id)
                ->whereIn('metaKey', ['bayutPortalListingData', 'pfPortalListingData'])
                ->get();

            foreach ($portalListings as $listing) {
                $data = json_decode((string) $listing->metaValue, true) ?: [];
                $data['portalStatus'] = 'Pending Removal';
                $data['status'] = 'delete';
                $data['reason'] = 'Unpublish request submitted. Removal from portals is in progress and may take a few minutes.';

                $listing->metaValue = json_encode($data);
                $listing->save();
            }

            SyncPropertyToPortalsJob::dispatch((int) $property->id, SyncPropertyToPortalsJob::MODE_DRAFT);

            logUserActivity([
                'action' => 'unpublish_from_portals',
                'module' => Constants::MODULE_PROPERTY,
                'moduleId' => $property->id,
                'description' => "Unpublished property from portals: {$property->title}",
            ]);

            return $this->success(
                [],
                'Portal listings will be reverted to draft shortly.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('ThirdPartyController : unpublishFromPortals', $e);
        }
    }
}
