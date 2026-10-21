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

class SyncPropertyToPortalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public const MODE_SYNC = 'sync';

    public const MODE_DRAFT = 'draft';

    /**
     * @param  string[]  $changedFields  CRM fields/meta keys (or 'media') that changed,
     *                                   used to PATCH only what changed in MODE_SYNC.
     */
    public function __construct(
        public int $propertyId,
        public string $mode = self::MODE_SYNC,
        public array $changedFields = [],
    ) {}

    public function handle(BayutService $bayutService, PropertyFinderService $propertyFinderService): void
    {
        Log::info('[Portal Sync] Job started', [
            'crm_property_id' => $this->propertyId,
            'mode' => $this->mode,
            'changedFields' => $this->changedFields,
        ]);

        $property = Property::find($this->propertyId);

        if (! $property) {
            Log::warning('[Portal Sync] Aborting — property not found', [
                'crm_property_id' => $this->propertyId,
                'mode' => $this->mode,
            ]);

            return;
        }

        $portalByMetaKey = [
            'bayutPortalListingData' => 'bayut',
            'pfPortalListingData' => 'propertyFinder',
        ];

        $listings = PropertyMeta::where('propertyId', $this->propertyId)
            ->whereIn('metaKey', array_keys($portalByMetaKey))
            ->pluck('metaValue', 'metaKey');

        foreach ($listings as $metaKey => $raw) {
            $portal = $portalByMetaKey[$metaKey] ?? null;

            if (! $portal) {
                Log::warning('[Portal Sync] Skipping — unmapped portal meta key', [
                    'crm_property_id' => $this->propertyId,
                    'metaKey' => $metaKey,
                    'mode' => $this->mode,
                ]);

                continue;
            }

            $data = json_decode((string) $raw, true);
            $externalId = $data['externalListingId'] ?? null;
            $accountSlug = $data['account'] ?? null;

            if (! $externalId || ! $accountSlug) {
                Log::warning('[Portal Sync] Skipping — missing externalListingId or account', [
                    'crm_property_id' => $this->propertyId,
                    'portal' => $portal,
                    'mode' => $this->mode,
                    'externalListingId' => $externalId,
                    'account' => $accountSlug,
                ]);

                continue;
            }

            $portalSetting = PortalSetting::where('slug', $accountSlug)
                ->where('portal', $portal)
                ->first();

            if (! $portalSetting) {
                Log::error('[Portal Sync] Skipping — portal account not found', [
                    'crm_property_id' => $this->propertyId,
                    'portal' => $portal,
                    'account' => $accountSlug,
                    'mode' => $this->mode,
                ]);

                continue;
            }

            $service = $portal === 'bayut' ? $bayutService : $propertyFinderService;

            Log::info('[Portal Sync] Dispatching to portal service', [
                'crm_property_id' => $this->propertyId,
                'portal' => $portal,
                'account' => $accountSlug,
                'externalListingId' => $externalId,
                'mode' => $this->mode,
                'action' => $this->mode === self::MODE_DRAFT ? 'unpublishProperty' : 'updateProperty',
                'changedFields' => $this->changedFields,
            ]);

            $result = $this->mode === self::MODE_DRAFT
                ? $service->unpublishProperty($property, $portalSetting, (string) $externalId)
                : $service->updateProperty($property, $portalSetting, (string) $externalId, $this->changedFields);

            if (! ($result['success'] ?? false)) {
                Log::error('[Portal Sync] '.$this->mode.' failed', [
                    'crm_property_id' => $this->propertyId,
                    'portal' => $portal,
                    'account' => $accountSlug,
                    'externalListingId' => $externalId,
                    'error' => $result['error'] ?? null,
                    'stage' => $result['stage'] ?? null,
                    'result' => $result,
                ]);

                continue;
            }

            Log::info('[Portal Sync] '.$this->mode.' succeeded', [
                'crm_property_id' => $this->propertyId,
                'portal' => $portal,
                'account' => $accountSlug,
                'externalListingId' => $externalId,
                'result' => $result,
            ]);
        }

        Log::info('[Portal Sync] Job finished', [
            'crm_property_id' => $this->propertyId,
            'mode' => $this->mode,
        ]);
    }
}
