<?php

namespace App\Jobs;

use App\Models\PortalSetting;
use App\Models\Property;
use App\Models\PropertyMeta;
use App\Services\BayutService;
use App\Services\PropertyFinderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Publish a portal DRAFT listing (Bayut or Property Finder) for a property — the queued,
 * deferred equivalent of ThirdPartyController@publishDraftTo{Bayut,PropertyFinder}.
 *
 * Dispatched (with a delay) right after a successful publish/update so the freshly created
 * draft is taken live a little later, once the portal has settled the draft. The external
 * listing id is re-read from meta at run time (not passed in) because the publish call that
 * scheduled this job is what writes it.
 */
class PublishDraftToPortalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    /** portal => the property meta key holding its stored {externalListingId,...} payload. */
    private const META_KEY = [
        'bayut' => 'bayutPortalListingData',
        'propertyFinder' => 'pfPortalListingData',
    ];

    public function __construct(
        public int $propertyId,
        public string $account,
        public string $portal,
    ) {}

    public function handle(BayutService $bayutService, PropertyFinderService $propertyFinderService): void
    {
        $metaKey = self::META_KEY[$this->portal] ?? null;

        if (! $metaKey) {
            Log::warning('[Publish Draft] Aborting — unsupported portal', [
                'crm_property_id' => $this->propertyId,
                'portal' => $this->portal,
            ]);

            return;
        }

        $property = Property::find($this->propertyId);

        if (! $property) {
            Log::warning('[Publish Draft] Aborting — property not found', [
                'crm_property_id' => $this->propertyId,
                'portal' => $this->portal,
            ]);

            return;
        }

        $portalSetting = PortalSetting::where('slug', $this->account)
            ->where('portal', $this->portal)
            ->first();

        if (! $portalSetting) {
            Log::error('[Publish Draft] Aborting — portal account not found', [
                'crm_property_id' => $this->propertyId,
                'portal' => $this->portal,
                'account' => $this->account,
            ]);

            return;
        }

        $raw = PropertyMeta::where('propertyId', $this->propertyId)
            ->where('metaKey', $metaKey)
            ->value('metaValue');
        $externalListingId = $raw ? (json_decode((string) $raw, true)['externalListingId'] ?? null) : null;

        if (! $externalListingId) {
            Log::warning('[Publish Draft] Aborting — no draft listing id found', [
                'crm_property_id' => $this->propertyId,
                'portal' => $this->portal,
                'account' => $this->account,
            ]);

            return;
        }

        $service = $this->portal === 'bayut' ? $bayutService : $propertyFinderService;

        $result = $service->publishDraftListing($property, $portalSetting, (string) $externalListingId);

        if (! ($result['success'] ?? false)) {
            Log::error('[Publish Draft] Failed', [
                'crm_property_id' => $this->propertyId,
                'portal' => $this->portal,
                'account' => $this->account,
                'externalListingId' => $externalListingId,
                'error' => $result['error'] ?? null,
                'stage' => $result['stage'] ?? null,
                'result' => $result,
            ]);

            return;
        }

        Log::info('[Publish Draft] Succeeded', [
            'crm_property_id' => $this->propertyId,
            'portal' => $this->portal,
            'account' => $this->account,
            'externalListingId' => $externalListingId,
            'result' => $result,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[Publish Draft] Job failed', [
            'crm_property_id' => $this->propertyId,
            'portal' => $this->portal,
            'account' => $this->account,
            'error' => $exception->getMessage(),
        ]);
    }
}
