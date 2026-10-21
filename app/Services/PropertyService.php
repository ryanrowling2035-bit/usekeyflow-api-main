<?php

namespace App\Services;

use App\Constants\Constants;
use App\Exceptions\UserFacingException;
use App\Jobs\GeneratePropertyPdfsJob;
use App\Jobs\NotifyManagersOnListingCreatedJob;
use App\Jobs\NotifyPublishedListingKeyDataChangedJob;
use App\Jobs\SyncPropertyToPortalsJob;
use App\Models\Boost;
use App\Models\Favorite;
use App\Models\Follow;
use App\Models\GlobalSetting;
use App\Models\InternalNote;
use App\Models\Property;
use App\Models\PropertyCommunity;
use App\Models\PropertyListingHistory;
use App\Models\PropertyMediaFile;
use App\Models\PropertyMeta;
use App\Models\PropertyVideos;
use App\Models\Role;
use App\Models\User;
use App\Repositories\EnquiryRepository;
use App\Repositories\PropertyRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PropertyService
{
    private const PUBLISHED_LISTING_KEY_FIELDS = [
        'title' => 'Title',
        'price' => 'Price',
        'listingType' => 'Listing Type',
        'propertyType' => 'Property Type',
        'bedrooms' => 'Beds',
        'sizeArea' => 'Size',
        'pfBuiltUpArea' => 'BUA',
        'plotSize' => 'Plot',
        'pfCommunity' => 'Community',
        'status' => 'Status',
        'reraPermitNo' => 'Permit Number',
        'govtQR' => 'Permit QR',
        'transactionNumber' => 'Permit Transaction Number',
        'expireOn' => 'Permit Expiry',
    ];

    public function __construct(
        protected PropertyRepository $propertyRepository,
        protected ImageCompressionService $imageCompressionService,
        protected VideoPreviewService $videoPreviewService,
        protected PropertyPdfService $propertyPdfService,
        protected FileStorageService $fileStorage
    ) {}

    // ================= listProperties =================
    public function listProperties(Request $request)
    {
        return $this->propertyRepository->getAll($request);
    }

    // ================= getPortalFeedListings =================
    public function getPortalFeedListings(Request $request, string $metaKey, bool $paginate = true)
    {
        return $this->propertyRepository->getPortalFeedListings($request, $metaKey, $paginate);
    }

    // Un-executed feed query, for streaming large feeds via chunkById().
    public function portalFeedQuery(Request $request, string $metaKey)
    {
        return $this->propertyRepository->portalFeedQuery($request, $metaKey);
    }

    // ================= getAllMatchingProperties =================
    public function getAllMatchingProperties(Request $request)
    {
        $paginator = $this->propertyRepository->getAllMatchingProperties($request);

        // Raw model output (no resource) — strip trailing ".0" from the decimal bathrooms column.
        $paginator->getCollection()->each(function ($property) {
            $property->bathrooms = formatDecimal($property->bathrooms);
        });

        return $paginator;
    }

    // ================= generateReferenceNumber =================
    public function generateReferenceNumber(array $data): string
    {
        $listingType = strtolower($data['listingType'] ?? '');

        // The AgentCode segment follows the listing owner (not the assigned agent),
        // so it can be re-derived when the owner changes on update.
        $agentCode = $this->agentCodeForUser($data['listingOwnerId'] ?? null);

        $communityCode = '';
        if (! empty($data['pfCommunity'])) {
            $community = PropertyCommunity::where('communityId', $data['pfCommunity'])->first();
            $communityCode = $community?->communityCode ?? '';
        }

        $managedTypes = array_filter(array_map(
            fn ($v) => strtolower(trim($v)),
            explode(',', $data['managedType'] ?? '')
        ));

        $prefixMap = [
            'sale' => 'S',
            'rent' => 'R',
        ];
        $listingPrefix = $prefixMap[$listingType] ?? '';

        // Resolve the prefix (everything before the 6-digit sequence, trailing "-" included).
        if ($listingType === 'offplan') {
            // For Projects (offPlan) – OFFPLAN-S-6digitnumber
            $prefix = 'OFFPLAN-S-';
        } elseif ($listingType === 'international') {
            // For International – INTERNATIONAL-S-6digitnumber
            $prefix = 'INTERNATIONAL-S-';
        } elseif (in_array(strtolower(Constants::MANAGED_TYPE_VACATION_HOMES), $managedTypes, true)) {
            // For VH listing – VH+DevCode-R/S-6digitnumber (no prefix if not sale/rent)
            $prefix = 'VH'.$communityCode.($listingPrefix ? '-'.$listingPrefix : '').'-';
        } elseif (in_array(strtolower(Constants::MANAGED_TYPE_MANAGED), $managedTypes, true)) {
            // For Managed listing – PM-DevCode-R/S-6digitnumber
            $prefix = 'PM'.$communityCode.($listingPrefix ? '-'.$listingPrefix : '').'-';
        } else {
            // Normal Listings – AgentCode-DevCode-R/S-6digitnumber
            $prefix = $agentCode.$communityCode.($listingPrefix ? '-'.$listingPrefix : '').'-';
        }

        return $this->nextUniqueReferenceNumber($prefix);
    }

    /**
     * Build the next reference number for the given prefix, guaranteed not to
     * collide with any existing property reference. The 6-digit sequence is
     * shared across all prefixes (global max + 1).
     */
    public function nextUniqueReferenceNumber(string $prefix): string
    {
        $lastProperty = Property::whereNotNull('referenceNumber')
            ->orderBy('id', 'desc')
            ->first();

        $nextNumber = 1;
        if ($lastProperty && $lastProperty->referenceNumber) {
            if (preg_match('/(\d+)$/', $lastProperty->referenceNumber, $matches)) {
                $nextNumber = (int) $matches[1] + 1;
            }
        }

        // Skip past any number already taken.
        do {
            $referenceNumber = $prefix.str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
            $nextNumber++;
        } while (Property::where('referenceNumber', $referenceNumber)->exists());

        return $referenceNumber;
    }

    /**
     * agentCode of the given user (empty string when none/missing).
     */
    public function agentCodeForUser($userId): string
    {
        if (empty($userId)) {
            return '';
        }

        return User::find($userId)?->agentCode ?? '';
    }

    /**
     * A "normal" listing is the agent-code-prefixed kind — i.e. NOT off-plan,
     * international, Vacation Homes or Managed (those use fixed VH/PM/OFFPLAN/
     * INTERNATIONAL prefixes instead).
     */
    public function isNormalListing(?string $listingType, ?string $managedType): bool
    {
        $listingType = strtolower($listingType ?? '');
        if (in_array($listingType, ['offplan', 'international'], true)) {
            return false;
        }

        $managedTypes = array_filter(array_map(
            fn ($v) => strtolower(trim($v)),
            explode(',', $managedType ?? '')
        ));

        return ! in_array(strtolower(Constants::MANAGED_TYPE_VACATION_HOMES), $managedTypes, true)
            && ! in_array(strtolower(Constants::MANAGED_TYPE_MANAGED), $managedTypes, true);
    }

    /**
     * Managed properties are all owned by one fixed physical owner. When the
     * effective managedType contains "managed", force physicalOwnerId onto the
     * payload so create/update both land the same owner.
     */
    public function applyManagedPhysicalOwner(array $data, ?string $managedType = null): array
    {
        $managedTypes = array_filter(array_map(
            fn ($v) => strtolower(trim($v)),
            explode(',', $managedType ?? $data['managedType'] ?? '')
        ));

        if (in_array(strtolower(Constants::MANAGED_TYPE_MANAGED), $managedTypes, true)) {
            $data['physicalOwnerId'] = Constants::MANAGED_PROPERTY_PHYSICAL_OWNER_ID;
        }

        return $data;
    }

    // ================= resolvePropertyTypes =================
    public function resolvePropertyTypes(?string $type): array
    {
        if (! $type) {
            return ['pfPropertyType' => null, 'bayutPropertyType' => null];
        }

        $row = Constants::PROPERTY_TYPE_MAPPING[$type] ?? null;

        return [
            'pfPropertyType' => $row['pf'] ?? $type,
            'bayutPropertyType' => $row['bayut'] ?? null,
        ];
    }

    // ================= getPropertyTypeLabel =================
    public function getPropertyTypeLabel(?string $pfType, ?string $bayutType): ?string
    {
        if (! $pfType && ! $bayutType) {
            return null;
        }

        foreach (Constants::PROPERTY_TYPE_MAPPING as $row) {
            if (($pfType && ($row['pf'] ?? null) === $pfType)
                || ($bayutType && ($row['bayut'] ?? null) === $bayutType)) {
                return $row['label'];
            }
        }

        return $pfType ?: $bayutType;
    }

    // ================= createProperty =================
    public function createProperty(array $data, Request $request)
    {
        $data = $this->applyManagedPhysicalOwner($data);

        $property = $this->propertyRepository->create($data);

        $this->handlePropertyMediaUploads($property->id, $request);
        $this->syncPropertyMeta($property->id, $request);
        $this->syncPropertyNotes($property->id, $request);

        // Notify managers (CRM) that a new listing was created — queued.
        if ($actor = user()) {
            NotifyManagersOnListingCreatedJob::dispatch($property->id, $actor->id);
        }

        return $this->propertyRepository->findById($property->id);
    }

    /**
     * Flag that a listing needs a fresh government QR (newQrRequired meta = 1).
     * No-op unless a QR already exists, and never applies to Vacation Homes — they
     * use a DTCM Code, not a RERA permit QR. Cleared when permit details are re-entered.
     */
    public function markNewQrRequired(Property $property): void
    {
        $managedTypes = array_filter(array_map(
            fn ($v) => strtolower(trim($v)),
            explode(',', $property->managedType ?? '')
        ));

        if (in_array(strtolower(Constants::MANAGED_TYPE_VACATION_HOMES), $managedTypes, true)) {
            PropertyMeta::updateOrCreate(
                ['propertyId' => $property->id, 'metaKey' => 'newQrRequired'],
                ['metaValue' => 0],
            );

            return;
        }

        // No existing QR to invalidate yet → nothing to flag.
        if (empty($property->govtQR)) {
            return;
        }

        PropertyMeta::updateOrCreate(
            ['propertyId' => $property->id, 'metaKey' => 'newQrRequired'],
            ['metaValue' => 1],
        );
    }

    // ================= updateProperty =================
    public function updateProperty(int $id, array $data, Request $request)
    {
        $property = $this->propertyRepository->findById($id);

        if (! $property) {
            throw new UserFacingException('Property not found');
        }

        // A listing that transitions into Published (from the edit form or any other
        // direct status change) is approved by definition — the dedicated publish and
        // approve flows already do this, so keep the generic update path consistent.
        if (($data['status'] ?? null) === Constants::PROPERTY_STATUS_PUBLISHED
            && $property->status !== Constants::PROPERTY_STATUS_PUBLISHED
        ) {
            $data['approvalStatus'] = Constants::APPROVAL_COMPLETED;
            $data['latestApprovalComment'] = 'Listing published by <a href="'.config('app.frontend_url').(isAgent() ? '/dashboard/agents/'.user()->id.'/edit' : '/dashboard/users/'.user()->id).'"><strong>'.ucwords(user()->name).'</strong></a>.';
            if (empty($property->publishedAt)) {
                $data['publishedAt'] = now();
                $data['publishedBy'] = user()->id;
            }
        }

        $this->propertyRepository->createHistory($property, $data);

        $originalAttributes = $property->getAttributes();
        // Snapshot meta values before syncPropertyMeta() overwrites them, so the portal-sync
        // diff below can tell which meta fields actually changed (not just which were submitted).
        $originalMeta = $property->meta->pluck('metaValue', 'metaKey')->all();
        $publishedListingKeyDataBefore = $this->getPublishedListingKeyDataSnapshot($property);

        // When the listing owner changes on a normal listing, swap the leading
        // AgentCode of the reference number to the new owner's code — keeping the
        // DevCode, sale/rent flag and 6-digit sequence intact.
        if (array_key_exists('listingOwnerId', $data)
            && (int) $data['listingOwnerId'] !== (int) $property->listingOwnerId
            && $property->referenceNumber
            && $this->isNormalListing(
                $data['listingType'] ?? $property->listingType,
                $data['managedType'] ?? $property->managedType
            )
        ) {
            $oldCode = $this->agentCodeForUser($property->listingOwnerId);
            $newCode = $this->agentCodeForUser($data['listingOwnerId']);

            if ($oldCode !== '' && str_starts_with($property->referenceNumber, $oldCode)) {
                $data['referenceNumber'] = $newCode.substr($property->referenceNumber, strlen($oldCode));
            }
        }

        // When a listing is (re)classified into a Managed or Vacation-Homes type,
        // switch the reference number's leading code to PM / VH — keeping the
        // DevCode, sale/rent flag and 6-digit sequence intact. A listing is
        // either Vacation-Homes or Managed, never both.
        if (array_key_exists('managedType', $data) && $property->referenceNumber) {
            $oldManagedTypes = array_filter(array_map(
                fn ($v) => strtolower(trim($v)),
                explode(',', $property->managedType ?? '')
            ));
            $newManagedTypes = array_filter(array_map(
                fn ($v) => strtolower(trim($v)),
                explode(',', $data['managedType'] ?? '')
            ));

            $vh = strtolower(Constants::MANAGED_TYPE_VACATION_HOMES);
            $pm = strtolower(Constants::MANAGED_TYPE_MANAGED);

            // Leading code implied by the new type (null when it stays a normal listing)…
            $newCode = in_array($vh, $newManagedTypes, true) ? 'VH'
                : (in_array($pm, $newManagedTypes, true) ? 'PM' : null);

            if ($newCode !== null) {
                // …and the code currently on the reference: VH/PM if it was already a
                // managed/VH listing, otherwise the listing owner's AgentCode.
                $oldCode = in_array($vh, $oldManagedTypes, true) ? 'VH'
                    : (in_array($pm, $oldManagedTypes, true) ? 'PM'
                        : $this->agentCodeForUser($property->listingOwnerId));

                if ($oldCode !== '' && $oldCode !== $newCode
                    && str_starts_with($property->referenceNumber, $oldCode)
                ) {
                    $data['referenceNumber'] = $newCode.substr($property->referenceNumber, strlen($oldCode));
                }
            }
        }

        // Cloned listings temporarily set isClonedListing=true to unlock listingType.
        // On save, force it back to false so the flag is one-time only.
        if (filter_var($originalMeta['isClonedListing'] ?? null, FILTER_VALIDATE_BOOLEAN)) {
            // When the listing type changes between Sale and Rent, keep the reference
            // number's sale/rent flag in sync — the -R-/-S- token sits directly before
            // the trailing 6-digit sequence (e.g. ADBB-R-064636 -> ADBB-S-064636).
            if (array_key_exists('listingType', $data)
                && $property->referenceNumber
                && strtolower((string) $data['listingType']) !== strtolower((string) $property->listingType)
            ) {
                $newPrefixLetter = match (strtolower((string) $data['listingType'])) {
                    Constants::PROPERTY_PURPOSE_SALE => 'S',
                    Constants::PROPERTY_PURPOSE_RENT => 'R',
                    default => null,
                };

                if ($newPrefixLetter !== null) {
                    $reference = $data['referenceNumber'] ?? $property->referenceNumber;
                    $data['referenceNumber'] = preg_replace('/-[RS]-(\d+)$/', '-'.$newPrefixLetter.'-$1', $reference);
                }
            }
            $request->merge(['isClonedListing' => '0']);
        }

        $property = $this->propertyRepository->update($property, $data);

        $this->handlePropertyMediaUploads($property->id, $request);
        $this->syncPropertyMeta($property->id, $request);
        $this->syncPropertyNotes($property->id, $request);
        $videosChanged = $this->syncPropertyVideos($property->id, $request);
        $property->load('meta');

        // A listing edit invalidates the government QR only on two triggers (image
        // replacement is handled in the media-upload API, which carries the photos):
        //   (1) a price change, or
        //   (2) on SALES listings only, a change of CRM agent owner / listing agent.
        // General edits — beds, baths, title, description, etc. — must NOT trigger it,
        // and rental agent reassignment never does (gate (2) is sale-only).
        $updatedAttributes = $property->getAttributes();
        $needsNewQr = false;

        foreach (['price', 'salePrice', 'annualRent'] as $field) {
            if (($originalAttributes[$field] ?? null) != ($updatedAttributes[$field] ?? null)) {
                $needsNewQr = true;
                break;
            }
        }

        if (! $needsNewQr && strtolower((string) $property->listingType) === Constants::PROPERTY_PURPOSE_SALE) {
            foreach (['listingOwnerId', 'agentId'] as $field) {
                if ((int) ($originalAttributes[$field] ?? 0) !== (int) ($updatedAttributes[$field] ?? 0)) {
                    $needsNewQr = true;
                    break;
                }
            }
        }

        if ($needsNewQr) {
            $this->markNewQrRequired($property);
        }

        $portalSyncStatuses = Constants::PROPERTY_PORTAL_SYNC_STATUSES;

        $oldStatus = $originalAttributes['status'] ?? null;
        $newStatus = $property->status;
        $wasOnPortal = in_array($oldStatus, $portalSyncStatuses, true);
        $isOnPortal = in_array($newStatus, $portalSyncStatuses, true);

        if ($wasOnPortal && ! $isOnPortal) {
            SyncPropertyToPortalsJob::dispatch((int) $property->id, SyncPropertyToPortalsJob::MODE_DRAFT);
        } elseif ($isOnPortal) {
            $changedFields = [];

            $newAttributes = $property->getAttributes();
            foreach ($newAttributes as $key => $value) {
                if (! array_key_exists($key, $originalAttributes) || $originalAttributes[$key] !== $value) {
                    $changedFields[] = $key;
                }
            }

            foreach (array_keys(Constants::PROPERTY_META_KEYS) as $metaRequestKey) {
                if (! $request->has($metaRequestKey)) {
                    continue;
                }

                // Some meta values arrive as arrays (e.g. flyerData, paymentPlans,
                // pfPortalListingData). Serialize them the same way syncPropertyMeta()
                // stores them so the change check never string-casts a raw array.
                $submitted = $request->input($metaRequestKey);
                $submitted = is_array($submitted) ? json_encode($submitted) : (string) $submitted;

                if ($submitted !== (string) ($originalMeta[$metaRequestKey] ?? null)) {
                    $changedFields[] = $metaRequestKey;
                }
            }

            foreach (array_keys(Constants::PROPERTY_MEDIA_KEYS) as $mediaRequestKey) {
                if ($request->hasFile($mediaRequestKey) || $request->has('delete'.ucfirst($mediaRequestKey))) {
                    $changedFields[] = 'media';
                    break;
                }
            }

            // Videos live in property_videos — not a column, a meta key or an uploaded
            // file — so they are invisible to every diff above. Report them through the
            // same 'media' sentinel, or a video-only edit never reaches the portals.
            if ($videosChanged && ! in_array('media', $changedFields, true)) {
                $changedFields[] = 'media';
            }

            if (! empty($changedFields)) {
                SyncPropertyToPortalsJob::dispatch((int) $property->id, SyncPropertyToPortalsJob::MODE_SYNC, $changedFields);
            }
        }

        notifyModule(Constants::MODULE_PROPERTY, $property->id, Constants::NOTIFICATION_TYPES['property_update'], 'updated', $property->title);

        // Notify admins (CRM + email) when key data of a published listing changes — queued.
        if (! empty($publishedListingKeyDataBefore) && ($actor = user())) {
            NotifyPublishedListingKeyDataChangedJob::dispatch($property->id, $publishedListingKeyDataBefore, $actor->id);
        }

        // GeneratePropertyPdfsJob::dispatch((int) $property->id);

        return $this->propertyRepository->findById($property->id);
    }

    // ================= syncPropertyVideos =================
    /**
     * Rewrite the property_videos rows from the request, and report whether the set
     * actually changed so the portal-sync diff can pick a video-only edit up.
     */
    protected function syncPropertyVideos(int $propertyId, Request $request): bool
    {
        if (! $request->has('videos')) {
            return false;
        }

        // Empty string and null are the same "no value" to the portals — normalize both
        // sides of the comparison so a blank title doesn't read as a change every save.
        $blankToNull = fn ($value) => ($value === null || $value === '') ? null : (string) $value;

        $submitted = [];

        foreach ((array) $request->input('videos', []) as $video) {
            if (! is_array($video) || empty($video['videoUrl'])) {
                continue;
            }

            $submitted[] = [
                'propertyId' => $propertyId,
                'videoUrl' => (string) $video['videoUrl'],
                'platform' => $blankToNull($video['platform'] ?? null),
                'title' => $blankToNull($video['title'] ?? null),
                'isDefault' => (int) filter_var($video['isDefault'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        $existing = PropertyVideos::where('propertyId', $propertyId)
            ->orderBy('id')
            ->get()
            ->map(fn ($video) => [
                'propertyId' => $propertyId,
                'videoUrl' => (string) $video->videoUrl,
                'platform' => $blankToNull($video->platform),
                'title' => $blankToNull($video->title),
                'isDefault' => (int) filter_var($video->isDefault, FILTER_VALIDATE_BOOLEAN),
            ])
            ->all();

        if ($existing === $submitted) {
            return false;
        }

        PropertyVideos::where('propertyId', $propertyId)->delete();

        foreach ($submitted as $video) {
            PropertyVideos::create($video);
        }

        return true;
    }

    // ================= syncPropertyNotes =================
    protected function syncPropertyNotes(int $propertyId, Request $request): void
    {
        $notes = [];

        if ($request->has('adminComments')) {
            $notes['adminComments'] = $request->input('adminComments');
        }

        if ($request->has('portalNotes')) {
            $notes['portalNotes'] = $request->input('portalNotes');
        }

        if (! empty($notes)) {
            app(InternalNoteService::class)->syncModuleNotes(
                Constants::MODULE_PROPERTY,
                $propertyId,
                $notes
            );
        }
    }

    // ================= syncPropertyMeta =================
    protected function syncPropertyMeta(int $propertyId, Request $request): void
    {
        foreach (Constants::PROPERTY_META_KEYS as $requestKey => $metaKey) {
            if (! $request->has($requestKey)) {
                continue;
            }

            $value = $request->input($requestKey);

            // If value is null, empty string, or empty array — remove the meta record
            $isEmpty = $value === null || $value === '' || $value === [] || $value === '[]' || $value === '{}';

            if ($isEmpty) {
                PropertyMeta::where('propertyId', $propertyId)
                    ->where('metaKey', $metaKey)
                    ->delete();

                continue;
            }

            PropertyMeta::updateOrCreate(
                [
                    'propertyId' => $propertyId,
                    'metaKey' => $metaKey,
                ],
                [
                    'metaValue' => is_array($value) ? json_encode($value) : $value,
                ]
            );
        }
    }

    // ================= handlePropertyMediaUploads =================
    protected function handlePropertyMediaUploads(int $propertyId, Request $request): void
    {
        $mediaTypeMap = [
            '_uploadVideos' => 'uploadVideos',
            '_uploadPhotos' => 'uploadPhotos',
            '_floorPlans' => 'floorPlans',
            '_uploadDocuments' => 'uploadDocuments',
        ];

        // Group this property's uploads under its reference number (id fallback).
        $referenceNumber = Property::where('id', $propertyId)->value('referenceNumber');
        $base = $this->fileStorage->recordPrefix('properties', $referenceNumber, $propertyId);

        // Handle file uploads
        foreach (Constants::PROPERTY_MEDIA_KEYS as $requestKey => $metaKey) {
            if (! $request->hasFile($requestKey)) {
                continue;
            }

            $files = $request->file($requestKey);

            if (! is_array($files)) {
                $files = [$files];
            }

            $files = array_filter($files, function ($file) {
                return $file !== null && is_object($file);
            });

            if (empty($files)) {
                continue;
            }

            $mediaType = $mediaTypeMap[$metaKey] ?? 'other';
            $maxOrdering = PropertyMediaFile::where('propertyId', $propertyId)
                ->where('mediaType', $mediaType)
                ->max('ordering') ?? 0;

            foreach ($files as $file) {
                if ($file && $file->isValid()) {
                    $originalName = str_replace(' ', '', $file->getClientOriginalName());
                    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                    $uniqueName = time().'_'.Str::uuid().'.'.$extension;

                    if ($metaKey === '_uploadVideos') {
                        $originalUrl = $this->fileStorage->uploadPublicFile($file, $base.'/originals', $uniqueName);
                        $previewName = pathinfo($uniqueName, PATHINFO_FILENAME).'.mp4';
                        $compressedPath = $this->videoPreviewService->generatePreview($file->getPathname(), $previewName, $base);

                        PropertyMediaFile::create([
                            'propertyId' => $propertyId,
                            'fileName' => $originalName,
                            'originalPath' => $originalUrl,
                            'compressedPath' => $compressedPath,
                            'thumbnailPath' => null,
                            'mediaType' => $mediaType,
                            'ordering' => ++$maxOrdering,
                            'createdBy' => user()->id ?? null,
                        ]);
                    } elseif ($metaKey === '_uploadPhotos') {
                        $paths = $this->imageCompressionService->compressAndStore($file, $uniqueName, $base);

                        PropertyMediaFile::create([
                            'propertyId' => $propertyId,
                            'fileName' => $originalName,
                            'originalPath' => $paths['originalPath'],
                            'compressedPath' => $paths['compressedPath'],
                            'thumbnailPath' => $paths['thumbnailPath'],
                            'mediaType' => $mediaType,
                            'ordering' => ++$maxOrdering,
                            'createdBy' => user()->id ?? null,
                        ]);
                    } else {
                        $fileUrl = $this->fileStorage->uploadPublicFile($file, $base.'/files', $uniqueName);
                        PropertyMediaFile::create([
                            'propertyId' => $propertyId,
                            'fileName' => $originalName,
                            'filePath' => $fileUrl,
                            'originalPath' => $fileUrl,
                            'compressedPath' => null,
                            'thumbnailPath' => null,
                            'mediaType' => $mediaType,
                            'ordering' => ++$maxOrdering,
                            'createdBy' => user()->id ?? null,
                        ]);
                    }
                }
            }
        }

        // Handle file deletions
        foreach (Constants::PROPERTY_MEDIA_KEYS as $requestKey => $metaKey) {
            $deleteKey = 'delete'.ucfirst($requestKey);

            if (! $request->has($deleteKey)) {
                continue;
            }

            $deleteIds = $request->input($deleteKey);

            if (is_string($deleteIds)) {
                $deleteIds = json_decode($deleteIds, true) ?: explode(',', $deleteIds);
            }

            if (! is_array($deleteIds) || empty($deleteIds)) {
                continue;
            }

            $mediaType = $mediaTypeMap[$metaKey] ?? 'other';

            foreach ($deleteIds as $mediaId) {
                $mediaFile = PropertyMediaFile::where('id', $mediaId)
                    ->where('propertyId', $propertyId)
                    ->where('mediaType', $mediaType)
                    ->first();

                if ($mediaFile) {
                    $this->fileStorage->deletePublicByUrl($mediaFile->filePath);

                    // Delete database record
                    $mediaFile->delete();
                }
            }
        }
    }

    // ================= deleteMediaByFilePath =================
    protected function deleteMediaByFilePath(int $propertyId, string $filePath): void
    {
        $mediaFile = PropertyMediaFile::where('propertyId', $propertyId)
            ->where('filePath', 'LIKE', '%'.$filePath.'%')
            ->first();

        if ($mediaFile) {
            $this->fileStorage->deletePublicByUrl($mediaFile->filePath);

            // Delete database record
            $mediaFile->delete();
        }
    }

    public function deleteProperty(int $id): bool
    {
        $property = Property::find($id);
        if (! $property) {
            throw new UserFacingException('Property not found');
        }
        Favorite::where('moduleId', $id)
            ->where('module', Constants::MODULE_PROPERTY)
            ->delete();
        Follow::where('moduleId', $id)
            ->where('module', Constants::MODULE_PROPERTY)
            ->delete();
        PropertyListingHistory::where('propertyId', $id)->delete();
        $property->delete();

        return true;
    }

    public function deleteProperties(array $ids): int
    {
        $deletedCount = 0;

        foreach ($ids as $id) {
            try {
                $this->deleteProperty($id);
                $deletedCount++;
            } catch (\Exception $e) {
                continue;
            }
        }

        return $deletedCount;
    }

    public function cloneProperty(Property $property)
    {
        $data = [
            'title' => $property->title.' (Copy)',
            'description' => $property->description,
            'category' => $property->category,
            'listingType' => $property->listingType,
            'completionStatus' => $property->completionStatus,
            'sizeArea' => $property->sizeArea,
            'plotSize' => $property->plotSize,
            'bedrooms' => $property->bedrooms,
            'bathrooms' => $property->bathrooms,
            'price' => $property->price,
            'reraPermitNo' => $property->reraPermitNo,
            'govtQR' => $property->govtQR,
            'agentId' => $property->agentId,
            'listingOwnerId' => $property->listingOwnerId ?: user()->id,
            'physicalOwnerId' => $property->physicalOwnerId,
            'pfPropertyType' => $property->pfPropertyType,
            'pfCity' => $property->pfCity,
            'pfCommunity' => $property->pfCommunity,
            'pfSubCommunity' => $property->pfSubCommunity,
            'pfBuilding' => $property->pfBuilding,
            'pfLatitude' => $property->pfLatitude,
            'pfLongitude' => $property->pfLongitude,
            'pfAmenities' => $property->pfAmenities,
            'pfPriceFrequency' => $property->pfPriceFrequency,
            'pfCheques' => $property->pfCheques,
            'bayutPropertyType' => $property->bayutPropertyType,
            'bayutLocation' => $property->bayutLocation,
            'bayutLocationId' => $property->bayutLocationId,
            'bayutAmenities' => $property->bayutAmenities,
            'bayutOccupancyStatus' => $property->bayutOccupancyStatus,
            'bayutMinimumContractPeriod' => $property->bayutMinimumContractPeriod,
            'bayutVacatingNoticePeriod' => $property->bayutVacatingNoticePeriod,
            'bayutMaintenanceFee' => $property->bayutMaintenanceFee,
            'bayutMaintenanceFeePaidBy' => $property->bayutMaintenanceFeePaidBy,
            'managedType' => $property->managedType,
            'annualRent' => $property->annualRent,
            'salePrice' => $property->salePrice,
            'titleDeedNumber' => $property->titleDeedNumber,
            'access' => $property->access,
            'listingLeadSource2' => $property->listingLeadSource2,
            'view' => $property->view,
            'developmentText' => $property->developmentText,
            'projectText' => $property->projectText,
            'deposit' => $property->deposit,
            'sold' => $property->sold,
            'agencyFee' => $property->agencyFee,
            'agencyFeePercent' => $property->agencyFeePercent,
            'ownershipTerm' => $property->ownershipTerm,
            'yearBuilt' => $property->yearBuilt,
            'parkingSpaces' => $property->parkingSpaces,
            'pubDate' => $property->pubDate,
            'status' => 'draft',
            'createdBy' => user()->id,
        ];

        // Generate new referenceNumber: keep prefix from original, increment last 6 digits
        // $prefix = preg_replace('/\d+$/', '', $property->referenceNumber ?? '');
        // $data['referenceNumber'] = $this->nextUniqueReferenceNumber($prefix);

        // Generate new referenceNumber: update prefix with listing owner and increment last 6 digits
        $data['referenceNumber'] = $this->generateReferenceNumber($data);

        $newProperty = $this->propertyRepository->create($data);

        // Clone media files (copy physical files and update paths) — exclude documents
        $mediaFiles = PropertyMediaFile::where('propertyId', $property->id)
            ->where('mediaType', '!=', 'uploadDocuments')
            ->get();

        foreach ($mediaFiles as $media) {
            $newOriginalPath = $this->fileStorage->copyPublicByUrl($media->originalPath);
            // Photos: cap the copy at the portal box. Pre-2026-06-12 variants are 1800x1200, and a
            // plain byte copy would republish that oversized image under the duplicated listing.
            $newCompressedPath = $media->mediaType === 'uploadPhotos'
                ? $this->imageCompressionService->copyCappedByUrl($media->compressedPath)
                : $this->fileStorage->copyPublicByUrl($media->compressedPath);
            $newThumbnailPath = $this->fileStorage->copyPublicByUrl($media->thumbnailPath);

            PropertyMediaFile::create([
                'propertyId' => $newProperty->id,
                'fileName' => $media->fileName,
                'filePath' => $newOriginalPath,
                'originalPath' => $newOriginalPath,
                'compressedPath' => $newCompressedPath,
                'thumbnailPath' => $newThumbnailPath,
                'mediaType' => $media->mediaType,
                'ordering' => $media->ordering,
                'category' => $media->category,
                'isWebsite' => $media->isWebsite,
                'isPortals' => $media->isPortals,
                'isFlyer' => $media->isFlyer,
                'createdBy' => user()->id,
            ]);
        }

        // Clone property videos (URL-based, no file copy needed)
        $videos = PropertyVideos::where('propertyId', $property->id)->get();
        foreach ($videos as $video) {
            PropertyVideos::create([
                'propertyId' => $newProperty->id,
                'videoUrl' => $video->videoUrl,
                'platform' => $video->platform,
                'title' => $video->title,
                'isDefault' => $video->isDefault,
            ]);
        }

        // Clone property meta — but never copy the live published-portal state. A clone
        // is a fresh draft that was never published, so pfPortalListingData /
        // bayutPortalListingData (which link a property to its actual listing on the
        // portal) must not carry over, otherwise the clone would point at the
        // original's portal listings and corrupt later portal sync/update/delete.
        // isClonedListing is set fresh below, so don't carry over the original's copy.
        $excludedMetaKeys = ['pfPortalListingData', 'bayutPortalListingData', 'isClonedListing'];
        $metaRecords = PropertyMeta::where('propertyId', $property->id)
            ->whereNotIn('metaKey', $excludedMetaKeys)
            ->get();
        foreach ($metaRecords as $meta) {
            PropertyMeta::create([
                'propertyId' => $newProperty->id,
                'metaKey' => $meta->metaKey,
                'metaValue' => $meta->metaValue,
            ]);
        }

        // Flag the clone so the frontend unlocks the listing-type field on the copy.
        // The flag is reset to false on the first update — see updateProperty().
        PropertyMeta::create([
            'propertyId' => $newProperty->id,
            'metaKey' => 'isClonedListing',
            'metaValue' => '1',
        ]);

        // Clone internal notes (portal notes, admin comments and their threaded
        // replies). Processed oldest-first so every parent is recreated before its
        // reply; `parentMap` remaps each reply onto the new parent so threads stay
        // intact. Original author/type are preserved — the point of a clone is to
        // keep this context on the refreshed listing.
        $parentMap = [0 => 0];
        $notes = InternalNote::where('module', Constants::MODULE_PROPERTY)
            ->where('moduleId', $property->id)
            ->orderBy('id')
            ->get();
        foreach ($notes as $note) {
            $clonedNote = InternalNote::create([
                'module' => Constants::MODULE_PROPERTY,
                'moduleId' => $newProperty->id,
                'content' => $note->content,
                'type' => $note->type,
                'parent' => $parentMap[$note->parent] ?? 0,
                'createdBy' => $note->createdBy,
            ]);
            $parentMap[$note->id] = $clonedNote->id;
        }

        // Clone boosts so the boost history and its notes/comments carry over. A
        // boost is an internal tracking record (no portal-listing linkage, no
        // credit charge on insert), so copying it is safe and does not affect the
        // clone's fresh portal state.
        $boosts = Boost::where('propertyId', $property->id)->get();
        foreach ($boosts as $boost) {
            Boost::create([
                'isPropertyFinder' => $boost->isPropertyFinder,
                'isBayut' => $boost->isBayut,
                'propertyId' => $newProperty->id,
                'duration' => $boost->duration,
                'boostType' => $boost->boostType,
                'credits' => $boost->credits,
                'status' => $boost->status,
                'notes' => $boost->notes,
                'rejectionReason' => $boost->rejectionReason,
                'startDate' => $boost->startDate,
                'endDate' => $boost->endDate,
                'createdBy' => $boost->createdBy,
            ]);
        }

        return $this->propertyRepository->findById($newProperty->id);
    }

    /**
     * Returns the missing field labels required to save an individual listing
     * in the requested status. Validation is performed against the payload only;
     * the caller must include any persisted fields it wants considered.
     */
    public function getMissingFieldsForListingStatus(array $overrides, string $status, ?Property $property = null): array
    {
        $listingType = $overrides['listingType'] ?? null;

        if (in_array($listingType, Constants::PROPERTY_INT_AND_OFF_LISTING_TYPES, true)) {
            return [];
        }

        $missing = [];

        $requiredByStatus = [
            Constants::PROPERTY_STATUS_UPCOMING => [
                ['label' => 'Title', 'field' => 'title'],
                ['label' => 'Listing Type', 'field' => 'listingType'],
                ['label' => 'Category', 'field' => 'category'],
            ],
            Constants::PROPERTY_STATUS_DRAFT => [
                ['label' => 'Title', 'field' => 'title'],
                ['label' => 'Listing Type', 'field' => 'listingType'],
                ['label' => 'Category', 'field' => 'category'],
                ['label' => 'Property Sub Type', 'callback' => fn () => $this->hasPropertySubType($overrides)],
                ['label' => 'Bedrooms', 'callback' => fn () => $this->isCommercialListing($overrides, $property) || $this->hasPropertyStatusValue($overrides, 'bedrooms', true)],
            ],
            Constants::PROPERTY_STATUS_POCKET_LISTING => [
                ['label' => 'Title', 'field' => 'title'],
                ['label' => 'Listing Type', 'field' => 'listingType'],
                ['label' => 'Category', 'field' => 'category'],
                ['label' => 'Property Sub Type', 'callback' => fn () => $this->hasPropertySubType($overrides)],
                ['label' => 'Bedrooms', 'callback' => fn () => $this->isCommercialListing($overrides, $property) || $this->hasPropertyStatusValue($overrides, 'bedrooms', true)],
                ['label' => 'Location / Community', 'callback' => fn () => $this->hasPropertyLocation($overrides)],
                ['label' => 'Price', 'callback' => fn () => $this->hasPropertyPrice($overrides)],
            ],
            Constants::PROPERTY_STATUS_PENDING => [
                ['label' => 'Title', 'field' => 'title'],
                ['label' => 'Listing Type', 'field' => 'listingType'],
                ['label' => 'Category', 'field' => 'category'],
                ['label' => 'Property Sub Type', 'callback' => fn () => $this->hasPropertySubType($overrides)],
                ['label' => 'Bedrooms', 'callback' => fn () => $this->isCommercialListing($overrides, $property) || $this->hasPropertyStatusValue($overrides, 'bedrooms', true)],
                ['label' => 'Location / Community', 'callback' => fn () => $this->hasPropertyLocation($overrides)],
                ['label' => 'Price', 'callback' => fn () => $this->hasPropertyPrice($overrides)],
                ['label' => 'BUA', 'field' => 'sizeArea'],
                ['label' => 'Bathrooms', 'callback' => fn () => $this->isCommercialListing($overrides, $property) || $this->hasPropertyStatusValue($overrides, 'bathrooms', true)],
                ['label' => 'Property Owner', 'field' => 'physicalOwnerId'],
                ['label' => 'Unit Number', 'callback' => fn () => $this->hasPropertyMetaValue($overrides, 'pfUnitNumber')],
                ['label' => 'Description (1000-2000 characters)', 'callback' => fn () => $this->hasValidIndividualListingDescription($overrides)],
                ['label' => 'Access Information', 'field' => 'access'],
                ['label' => 'Completion Status', 'field' => 'completionStatus'],
                ['label' => 'Property Sales Value (AED)', 'callback' => fn () => $listingType !== Constants::PROPERTY_PURPOSE_RENT || $this->hasPropertyMetaValue($overrides, 'propertySalesValue')],
                ['label' => 'Annual Rental Value (AED)', 'callback' => fn () => $listingType !== Constants::PROPERTY_PURPOSE_SALE || $this->hasPropertyMetaValue($overrides, 'annualRentalPrice')],
            ],
            Constants::PROPERTY_STATUS_PUBLISHED => [
                ['label' => 'Minimum 10 Images', 'callback' => fn () => $this->hasMinimumPropertyImages($property, $overrides, 10)],
            ],
        ];

        $statusOrder = [
            Constants::PROPERTY_STATUS_UPCOMING,
            Constants::PROPERTY_STATUS_DRAFT,
            Constants::PROPERTY_STATUS_POCKET_LISTING,
            Constants::PROPERTY_STATUS_PENDING,
            Constants::PROPERTY_STATUS_PUBLISHED,
        ];

        foreach ($statusOrder as $requiredStatus) {
            foreach ($requiredByStatus[$requiredStatus] as $rule) {
                $isPresent = isset($rule['callback'])
                    ? (bool) $rule['callback']()
                    : $this->hasPropertyStatusValue($overrides, $rule['field'], $rule['allowZero'] ?? false);

                if (! $isPresent) {
                    $missing[] = $rule['label'];
                }
            }

            if ($requiredStatus === $status) {
                break;
            }
        }

        return array_values(array_unique($missing));
    }

    protected function hasPropertyStatusValue(array $overrides, string $field, bool $allowZero = false): bool
    {
        $value = $overrides[$field] ?? null;

        if ($allowZero && ($value === 0 || $value === '0')) {
            return true;
        }

        return ! ($value === null || $value === '' || $value === []);
    }

    protected function isCommercialListing(array $overrides, ?Property $property = null): bool
    {
        $category = $overrides['category'] ?? $property?->category;

        return $category === Constants::PROPERTY_CATEGORY_COMMERCIAL;
    }

    protected function hasPropertySubType(array $overrides): bool
    {
        return $this->hasPropertyStatusValue($overrides, 'propertyType')
            || $this->hasPropertyStatusValue($overrides, 'pfPropertyType')
            || $this->hasPropertyStatusValue($overrides, 'bayutPropertyType');
    }

    protected function hasPropertyLocation(array $overrides): bool
    {
        return $this->hasPropertyStatusValue($overrides, 'pfCommunity')
            || $this->hasPropertyStatusValue($overrides, 'bayutLocationId');
    }

    protected function hasPropertyPrice(array $overrides): bool
    {
        return $this->hasPropertyStatusValue($overrides, 'price')
            || $this->hasPropertyStatusValue($overrides, 'salePrice')
            || $this->hasPropertyStatusValue($overrides, 'annualRent');
    }

    protected function hasValidIndividualListingDescription(array $overrides): bool
    {
        $description = $overrides['description'] ?? null;
        $description = is_string($description) ? trim(strip_tags(str_replace('&nbsp;', ' ', $description))) : '';
        $length = mb_strlen($description);

        return $length >= 1000 && $length <= 2000;
    }

    protected function hasPropertyMetaValue(array $overrides, string $key): bool
    {
        if ($key === 'pfUnitNumber' && array_key_exists('unitNo', $overrides)) {
            $value = $overrides['unitNo'];

            if (! ($value === null || $value === '' || $value === [])) {
                return true;
            }
        }

        $value = $overrides[$key] ?? null;

        return ! ($value === null || $value === '' || $value === []);
    }

    public function hasMinimumPropertyImages(?Property $property, array $overrides, int $minimum): bool
    {
        $existingCount = $property
            ? PropertyMediaFile::where('propertyId', $property->id)
                ->where('mediaType', 'uploadPhotos')
                ->count()
            : 0;

        $uploadCount = 0;
        if (isset($overrides['uploadPhotos']) && is_array($overrides['uploadPhotos'])) {
            $uploadCount = count(array_filter($overrides['uploadPhotos']));
        }

        return ($existingCount + $uploadCount) >= $minimum;
    }

    // ================= requestPermit =================
    public function requestPermit(Property $property, MailService $mailService): Property
    {
        $oldStatus = $property->status;
        $property = $this->propertyRepository->requestPermit($property);
        $property->loadMissing(['city', 'community', 'subCommunity', 'buildingTower']);

        logUserActivity([
            'action' => 'permit_request',
            'module' => Constants::MODULE_PROPERTY,
            'moduleId' => $property->id,
            'description' => "Sent permit request for property: {$property->title}",
        ]);

        // Capture actor name now — auth context may not be available after the response is sent.
        $actorName = ucwords(user()->name);
        $statusChanged = $oldStatus !== $property->status;

        // Defer emails + status-change notifications until after the response is flushed to the client.
        app()->terminating(function () use ($property, $mailService, $actorName, $statusChanged) {
            $this->sendPermitRequestNotifications($property, $mailService, $actorName, $statusChanged);
        });

        return $property;
    }

    private function sendPermitRequestNotifications(Property $property, MailService $mailService, string $actorName, bool $statusChanged): void
    {
        $permitEmail = GlobalSetting::where('settingKey', '_permitRequestEmail')->value('settingValue');
        $permitCustomEmail = GlobalSetting::where('settingKey', '_permitRequestCustomEmails')->value('settingValue');

        $propertyLink = env('FRONTEND_URL', config('app.url')).'/dashboard/property/'.$property->id.'/view';

        $emailData = [
            'property_title' => $property->title,
            'property_id' => $property->referenceNumber ?? $property->id,
            'listing_type' => $property->listingType ?? 'N/A',
            'city' => $property->city?->name ?? 'N/A',
            'community' => $property->community?->name ?? 'N/A',
            'sub_community' => $property->subCommunity?->name ?? 'N/A',
            'tower' => $property->buildingTower?->name ?? 'N/A',
            'requested_by' => $actorName,
            'requested_date' => now()->setTimezone(appTimezone())->format('F d, Y H:i'),
            'property_link' => $propertyLink,
            'company_name' => getCompanyName(),
        ];

        $permitUserIds = json_decode($permitEmail, true);

        // Replace "RERA permit" with "DTCM code" for vacationHomes listings
        $textReplacements = strtolower($property->listingType ?? '') === 'vacationhomes'
            ? ['RERA permit' => 'DTCM Code', 'RERA Permit' => 'DTCM Code']
            : [];

        $customEmails = collect(json_decode($permitCustomEmail, true) ?? [])
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->values()
            ->toArray();

        \Log::info('Permit request email debug', [
            'raw_setting' => $permitEmail,
            'decoded_ids' => $permitUserIds,
            'custom_emails' => $customEmails,
            'email_slug' => Constants::EMAIL_SLUGS['property']['rera_permit_request'],
        ]);

        if (! empty($permitUserIds) && is_array($permitUserIds)) {
            $permitUsers = User::whereIn('id', $permitUserIds)->get();
            \Log::info('Permit request: sending to users', ['count' => $permitUsers->count(), 'emails' => $permitUsers->pluck('email')]);
            foreach ($permitUsers as $permitUser) {
                try {
                    $mailService->sendEmail(
                        Constants::EMAIL_SLUGS['property']['rera_permit_request'],
                        $permitUser->email,
                        array_merge($emailData, ['admin_name' => ucwords($permitUser->name)]),
                        [],
                        $textReplacements
                    );
                } catch (\Throwable $e) {
                    \Log::error("Failed to send permit request to user {$permitUser->email}: ".$e->getMessage());
                }
            }
        } else {
            // Fallback: send to all managers
            $managerRole = Role::where('name', Constants::MANAGER)->first();
            \Log::info('Permit request: fallback to managers', ['managerRole' => $managerRole?->id]);
            if ($managerRole) {
                $managers = User::where('roleId', $managerRole->id)->where('status', 'active')->get();
                \Log::info('Permit request: managers found', ['count' => $managers->count(), 'emails' => $managers->pluck('email')]);
                foreach ($managers as $manager) {
                    try {
                        $mailService->sendEmail(
                            Constants::EMAIL_SLUGS['property']['rera_permit_request'],
                            $manager->email,
                            array_merge($emailData, ['admin_name' => ucwords($manager->name)]),
                            [],
                            $textReplacements
                        );
                        \Log::info("Permit request email sent to manager: {$manager->email}");
                    } catch (\Throwable $e) {
                        \Log::error("Failed to send permit request to manager {$manager->email}: ".$e->getMessage());
                    }
                }
            }
        }

        // Send to custom email addresses
        if (! empty($customEmails)) {
            \Log::info('Permit request: sending to custom emails', ['emails' => $customEmails]);
            foreach ($customEmails as $customEmail) {
                try {
                    $mailService->sendEmail(
                        Constants::EMAIL_SLUGS['property']['rera_permit_request'],
                        $customEmail,
                        array_merge($emailData, ['admin_name' => 'Team']),
                        [],
                        $textReplacements
                    );
                    \Log::info("Permit request email sent to custom email: {$customEmail}");
                } catch (\Throwable $e) {
                    \Log::error("Failed to send permit request to custom email {$customEmail}: ".$e->getMessage());
                }
            }
        }

        if ($statusChanged) {
            $statusLabel = Constants::PROPERTY_STATUS[$property->status] ?? $property->status;
            self::notifyAdminsManagersOnStatusChange($property, $statusLabel);
        }
    }

    // ================= enterPermitNumber =================
    public function enterPermitNumber(Property $property, string $permitNumber, MailService $mailService, $govtQR = null, ?string $transactionNumber = null, ?string $expireOn = null): Property
    {
        $oldStatus = $property->status;
        $property = $this->propertyRepository->enterPermitNumber($property, $permitNumber, $govtQR, $transactionNumber, $expireOn);

        logUserActivity([
            'action' => 'permit_number_entered',
            'module' => Constants::MODULE_PROPERTY,
            'moduleId' => $property->id,
            'description' => "Entered permit number for property: {$property->title}. Status changed to Draft.",
        ]);

        // Prefer the assigned agent, but fall back to createdBy if the agent user no longer exists (FK would fail otherwise).
        $agent = $property->agentId ? User::find($property->agentId) : null;
        if (! $agent && $property->createdBy) {
            $agent = User::find($property->createdBy);
        }
        $recipientUserId = $agent?->id;

        // Notify the agent that permit has been entered and listing is now Draft
        if ($recipientUserId) {
            $notificationService = app(NotificationService::class);
            $notificationService->notifyUser(
                userId: $recipientUserId,
                actorId: user()->id,
                type: Constants::NOTIFICATION_TYPES['property_permit_entered'],
                title: "Permit number added: {$property->title}",
                message: "Permit number has been added for your listing: {$property->title}. Your listing is now in Draft. Please review and request approval.",
                module: Constants::MODULE_PROPERTY,
                moduleId: $property->id,
                extraData: [
                    'propertyTitle' => $property->title,
                    'propertyId' => $property->id,
                    'permitNumber' => $permitNumber,
                    'status' => 'draft',
                ]
            );

            if ($agent) {
                $propertyLink = env('FRONTEND_URL', config('app.url')).'/dashboard/property/'.$property->id.'/view';

                try {
                    $mailService->sendEmail(
                        Constants::EMAIL_SLUGS['property']['permit_entered_agent'],
                        $agent->email,
                        [
                            'agent_name' => ucwords($agent->name),
                            'property_title' => $property->title,
                            'property_id' => $property->referenceNumber ?? $property->id,
                            'permit_number' => $property->reraPermitNo,
                            'added_by' => ucwords(user()->name),
                            'added_date' => now()->setTimezone(appTimezone())->format('F d, Y H:i'),
                            'property_link' => $propertyLink,
                            'company_name' => getCompanyName(),
                        ]
                    );
                } catch (\Throwable $e) {
                    \Log::error("Failed to send permit entered email to agent {$agent->email}: ".$e->getMessage());
                }
            }
        }

        if ($oldStatus !== $property->status) {
            $statusLabel = Constants::PROPERTY_STATUS[$property->status] ?? $property->status;
            self::notifyAdminsManagersOnStatusChange($property, $statusLabel);
        }

        return $property;
    }

    // ================= requestApproval =================
    public function requestApproval(Property $property, MailService $mailService): Property
    {
        $oldStatus = $property->status;
        $property = $this->propertyRepository->requestApproval($property);
        $property->loadMissing(['city', 'community', 'subCommunity', 'buildingTower']);

        logUserActivity([
            'action' => 'approval_requested',
            'module' => Constants::MODULE_PROPERTY,
            'moduleId' => $property->id,
            'description' => "Requested approval for property: {$property->title}. Status changed to Pending.",
        ]);

        $notificationService = app(NotificationService::class);
        $propertyLink = env('FRONTEND_URL', config('app.url')).'/dashboard/property/'.$property->id.'/view';

        // Replace "RERA permit" with "DTCM code" for vacationHomes listings
        $textReplacements = strtolower($property->listingType ?? '') === 'vacationhomes'
            ? ['RERA permit' => 'DTCM code', 'RERA Permit' => 'DTCM Code']
            : [];

        $agentRoleId = Role::where('name', Constants::AGENT)->value('id');

        $approvalRecipients = User::where('status', 'active')
            ->when($agentRoleId, fn ($query) => $query->where('roleId', '!=', $agentRoleId))
            ->get()
            ->unique('id');

        foreach ($approvalRecipients as $recipient) {
            try {
                $mailService->sendEmail(
                    Constants::EMAIL_SLUGS['property']['rera_manager_approval'],
                    $recipient->email,
                    [
                        'manager_name' => ucwords($recipient->name),
                        'property_title' => $property->title,
                        'property_id' => $property->referenceNumber ?? $property->id,
                        'listing_type' => $property->listingType ?? 'N/A',
                        'city' => $property->city?->name ?? 'N/A',
                        'community' => $property->community?->name ?? 'N/A',
                        'sub_community' => $property->subCommunity?->name ?? 'N/A',
                        'tower' => $property->buildingTower?->name ?? 'N/A',
                        'rera_permit_no' => $property->reraPermitNo,
                        'added_by' => ucwords(user()->name),
                        'added_date' => now()->setTimezone(appTimezone())->format('F d, Y H:i'),
                        'property_link' => $propertyLink,
                        'company_name' => getCompanyName(),
                    ],
                    [],
                    $textReplacements
                );
            } catch (\Throwable $e) {
                \Log::error("Failed to send approval request email to {$recipient->email}: ".$e->getMessage());
            }

            $notificationService->notifyUser(
                userId: $recipient->id,
                actorId: user()->id,
                type: Constants::NOTIFICATION_TYPES['property_approval_requested'],
                title: "Approval requested: {$property->title}",
                message: user()->name." has requested approval for property listing: {$property->title}",
                module: Constants::MODULE_PROPERTY,
                moduleId: $property->id,
                extraData: [
                    'propertyTitle' => $property->title,
                    'propertyId' => $property->id,
                    'requestedBy' => user()->name,
                ]
            );
        }

        if ($oldStatus !== $property->status) {
            $statusLabel = Constants::PROPERTY_STATUS[$property->status] ?? $property->status;
            self::notifyAdminsManagersOnStatusChange($property, $statusLabel);
        }

        return $property;
    }

    // ================= approveRejectProperty =================
    public function approveRejectProperty(Property $property, string $status, string $comment, MailService $mailService): Property
    {
        $oldStatus = $property->status;
        $property = $this->propertyRepository->approveRejectProperty($property, $status, $comment);

        $authUser = user();

        app()->terminating(function () use ($property, $status, $comment, $mailService, $oldStatus, $authUser) {
            try {
                $property->loadMissing(['city', 'community', 'subCommunity', 'buildingTower']);

                $isApproved = $status === 'approved';
                $statusLabel = $isApproved ? 'approved' : 'rejected';

                logUserActivity([
                    'action' => "property_{$statusLabel}",
                    'module' => Constants::MODULE_PROPERTY,
                    'moduleId' => $property->id,
                    'description' => ucfirst($statusLabel)." property: {$property->title}",
                ]);

                $recipientUserId = $property->agentId ?: $property->createdBy;

                if ($recipientUserId) {
                    $notificationService = app(NotificationService::class);
                    $notificationType = $isApproved
                        ? Constants::NOTIFICATION_TYPES['property_status_approved']
                        : Constants::NOTIFICATION_TYPES['property_status_rejected'];

                    $actorName = $authUser?->name ?? 'System';
                    $message = $isApproved
                        ? $actorName." approved your property listing: {$property->title}"
                        : $actorName." rejected your property listing: {$property->title}. Reason: ".($comment ?: 'N/A');

                    $notificationService->notifyUser(
                        userId: $recipientUserId,
                        actorId: $authUser?->id,
                        type: $notificationType,
                        title: "Property {$statusLabel}: {$property->title}",
                        message: $message,
                        module: Constants::MODULE_PROPERTY,
                        moduleId: $property->id,
                        extraData: [
                            'propertyTitle' => $property->title,
                            'propertyId' => $property->id,
                            'approvalStatus' => $statusLabel,
                            'comment' => $comment,
                        ]
                    );

                    $agent = User::find($recipientUserId);
                    if ($agent) {
                        $emailSlug = $isApproved
                            ? Constants::EMAIL_SLUGS['property']['listing_approved_agent']
                            : Constants::EMAIL_SLUGS['property']['listing_rejected_agent'];

                        $propertyLink = env('FRONTEND_URL', config('app.url')).'/dashboard/property/'.$property->id.'/view';

                        try {
                            $mailService->sendEmail(
                                $emailSlug,
                                $agent->email,
                                [
                                    'agent_name' => ucwords($agent->name),
                                    'property_title' => $property->title,
                                    'property_id' => $property->referenceNumber ?? $property->id,
                                    'status' => $statusLabel,
                                    'comment' => $comment ?: 'N/A',
                                    'city' => $property->city?->name ?? 'N/A',
                                    'community' => $property->community?->name ?? 'N/A',
                                    'sub_community' => $property->subCommunity?->name ?? 'N/A',
                                    'tower' => $property->buildingTower?->name ?? 'N/A',
                                    'reviewed_by' => ucwords($actorName),
                                    'reviewed_date' => now()->setTimezone(appTimezone())->format('F d, Y H:i'),
                                    'property_link' => $propertyLink,
                                    'company_name' => getCompanyName(),
                                ]
                            );
                        } catch (\Throwable $e) {
                            \Log::error("Failed to send property {$statusLabel} email to agent {$agent->email}: ".$e->getMessage());
                        }
                    }
                }

                if ($oldStatus !== $property->status) {
                    $finalStatusLabel = Constants::PROPERTY_STATUS[$property->status] ?? $property->status;
                    self::notifyAdminsManagersOnStatusChange($property, $finalStatusLabel);
                }

                if ($isApproved) {
                    $this->notifyMatchingEnquiryAgents($property);
                }
            } catch (\Throwable $e) {
                \Log::error('PropertyService : approveRejectProperty : deferred : '.$e->getMessage());
            }
        });

        return $property;
    }

    /**
     * Find active enquiries that match a newly published property and
     * send a bell notification to each assigned agent.
     */
    protected function notifyMatchingEnquiryAgents(Property $property): void
    {
        try {
            $notificationService = app(NotificationService::class);

            // Use the SAME matcher as the listing's Matching tab
            // (EnquiryRepository::matchActiveEnquiriesForProperty) so an agent is only
            // notified for enquiries that genuinely match — and that they can actually
            // see on that listing. The previous hand-rolled query was looser (it treated
            // a blank community as "matches any community", ignored size and the secondary
            // community columns, and matched listingType exactly), so agents were notified
            // for listings that showed no match on the Matching page.
            $matchedEnquiries = app(EnquiryRepository::class)
                ->matchActiveEnquiriesForProperty($property)
                ->get()
                ->filter(fn ($enquiry) => ! empty($enquiry->agentId))
                ->values();

            if ($matchedEnquiries->isEmpty()) {
                return;
            }

            $grouped = $matchedEnquiries->groupBy('agentId');
            $propertyRef = $property->referenceNumber ?? $property->id;

            foreach ($grouped as $agentId => $enquiries) {
                $count = $enquiries->count();
                $title = "New listing ({$propertyRef}) matched {$count} of your enquir".($count === 1 ? 'y' : 'ies');
                $refs = $enquiries->pluck('referenceNo')->filter()->take(3)->implode(', ');
                $message = "A newly published listing \"{$property->title}\" matches your enquir".($count === 1 ? 'y' : 'ies');
                if ($refs) {
                    $message .= ": {$refs}";
                    if ($count > 3) {
                        $message .= ' and '.($count - 3).' more';
                    }
                }

                $notificationService->notifyUser(
                    userId: $agentId,
                    actorId: user()->id,
                    type: Constants::NOTIFICATION_TYPES['new_listing_match'],
                    title: $title,
                    message: $message,
                    module: Constants::MODULE_PROPERTY,
                    moduleId: $property->id,
                    extraData: [
                        'propertyId' => $property->id,
                        'propertyTitle' => $property->title,
                        'propertyRef' => $propertyRef,
                        'matchedEnquiryCount' => $count,
                        'matchedEnquiryIds' => $enquiries->pluck('id')->toArray(),
                    ]
                );
            }
        } catch (\Exception $e) {
            \Log::error("Failed to notify matching enquiry agents for property {$property->id}: ".$e->getMessage());
        }
    }

    // ================= publishProperty =================
    public function publishProperty(Property $property, MailService $mailService): Property
    {
        $oldStatus = $property->status;
        $property = $this->propertyRepository->publishProperty($property);
        $property->loadMissing(['city', 'community', 'subCommunity', 'buildingTower', 'physicalOwner']);

        logUserActivity([
            'action' => 'property_published',
            'module' => Constants::MODULE_PROPERTY,
            'moduleId' => $property->id,
            'description' => "Published property: {$property->title}",
        ]);

        $notificationService = app(NotificationService::class);
        $propertyLink = env('FRONTEND_URL', config('app.url')).'/dashboard/property/'.$property->id.'/view';

        // Existing code used only createdBy as the recipient.
        $recipientUserId = $property->agentId ?: $property->createdBy;

        // Notify the agent that their listing has been published
        if ($recipientUserId) {
            $notificationService->notifyUser(
                userId: $recipientUserId,
                actorId: user()->id,
                type: Constants::NOTIFICATION_TYPES['property_published'],
                title: "Property published: {$property->title}",
                message: user()->name." has published your property listing: {$property->title}",
                module: Constants::MODULE_PROPERTY,
                moduleId: $property->id,
                extraData: [
                    'propertyTitle' => $property->title,
                    'propertyId' => $property->id,
                    'status' => 'published',
                ]
            );

            // Send email to the agent
            $agent = User::find($recipientUserId);
            if ($agent) {
                try {
                    $mailService->sendEmail(
                        Constants::EMAIL_SLUGS['property']['listing_published'],
                        $agent->email,
                        [
                            'agent_name' => ucwords($agent->name),
                            'property_title' => $property->title,
                            'property_id' => $property->referenceNumber ?? $property->id,
                            'city' => $property->city?->name ?? 'N/A',
                            'community' => $property->community?->name ?? 'N/A',
                            'sub_community' => $property->subCommunity?->name ?? 'N/A',
                            'tower' => $property->buildingTower?->name ?? 'N/A',
                            'published_by' => ucwords(user()->name),
                            'published_date' => now()->setTimezone(appTimezone())->format('F d, Y H:i'),
                            'property_link' => $propertyLink,
                            'company_name' => getCompanyName(),
                        ]
                    );
                } catch (\Throwable $e) {
                    \Log::error("Failed to send property published email to agent {$agent->email}: ".$e->getMessage());
                }
            }
        }

        // Notify the listing owner (physicalOwner / contact)
        $owner = $property->physicalOwner;
        if ($owner && $owner->email) {
            try {
                $mailService->sendEmail(
                    Constants::EMAIL_SLUGS['property']['listing_published_owner'],
                    $owner->email,
                    [
                        'owner_name' => ucwords($owner->name ?? ''),
                        'property_title' => $property->title,
                        'property_id' => $property->referenceNumber ?? $property->id,
                        'city' => $property->city?->name ?? 'N/A',
                        'community' => $property->community?->name ?? 'N/A',
                        'sub_community' => $property->subCommunity?->name ?? 'N/A',
                        'tower' => $property->buildingTower?->name ?? 'N/A',
                        'published_date' => now()->setTimezone(appTimezone())->format('F d, Y H:i'),
                        'company_name' => getCompanyName(),
                    ]
                );
            } catch (\Throwable $e) {
                \Log::error("Failed to send property published email to owner {$owner->email}: ".$e->getMessage());
            }
        }

        if ($oldStatus !== $property->status) {
            $statusLabel = Constants::PROPERTY_STATUS[$property->status] ?? $property->status;
            self::notifyAdminsManagersOnStatusChange($property, $statusLabel);
        }

        return $property;
    }

    public function uploadPropertyMediaFiles($propertyId, array $validated, Request $request)
    {
        $createdBy = $validated['createdBy'] ?? (user()->id ?? null);
        $uploaded = [];

        // Group this property's uploads under its reference number (id fallback).
        $referenceNumber = Property::where('id', $propertyId)->value('referenceNumber');
        $base = $this->fileStorage->recordPrefix('properties', $referenceNumber, $propertyId);

        $photos = $request->file('uploadPhotos') ?? [];
        $videos = $request->file('uploadVideos') ?? [];

        if (! is_array($photos)) {
            $photos = [$photos];
        }
        if (! is_array($videos)) {
            $videos = [$videos];
        }

        $photos = array_values(array_filter($photos));
        $videos = array_values(array_filter($videos));

        if (count($photos) === 0 && count($videos) === 0) {
            throw new UserFacingException('No files received');
        }

        // Upload photos with compression + watermark
        if (count($photos) > 0) {
            $maxOrdering = PropertyMediaFile::where('propertyId', $propertyId)
                ->where('mediaType', 'uploadPhotos')
                ->max('ordering') ?? 0;

            foreach ($photos as $index => $file) {
                if (! $file || ! $file->isValid()) {
                    continue;
                }

                $originalName = trim(str_replace(' ', '', $file->getClientOriginalName()));
                $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
                $uniqueName = $nameWithoutExt.'_'.time().'_'.$index.'.'.$extension;

                $paths = $this->imageCompressionService->compressAndStore($file, $uniqueName, $base);

                $uploaded[] = PropertyMediaFile::create([
                    'propertyId' => $propertyId,
                    'fileName' => $originalName,
                    'originalPath' => $paths['originalPath'],
                    'compressedPath' => $paths['compressedPath'],
                    'thumbnailPath' => $paths['thumbnailPath'],
                    'mediaType' => 'uploadPhotos',
                    'ordering' => ++$maxOrdering,
                    'createdBy' => $createdBy,
                ]);
            }

            // New photos uploaded → the listing's imagery changed (image replacement),
            // so a fresh government QR is required. Reordering goes through a separate
            // endpoint and never lands here, so it never triggers.
            if ($property = Property::find($propertyId)) {
                $this->markNewQrRequired($property);
            }
        }

        // Upload videos + generate preview (watermark applied inside VideoPreviewService)
        if (count($videos) > 0) {
            $maxOrdering = PropertyMediaFile::where('propertyId', $propertyId)
                ->where('mediaType', 'uploadVideos')
                ->max('ordering') ?? 0;

            foreach ($videos as $index => $file) {
                if (! $file || ! $file->isValid()) {
                    continue;
                }

                $originalName = trim(str_replace(' ', '', $file->getClientOriginalName()));
                $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
                $uniqueName = $nameWithoutExt.'_'.time().'_'.$index.'.'.$extension;

                $originalUrl = $this->fileStorage->uploadPublicFile($file, $base.'/originals', $uniqueName);
                $previewName = pathinfo($uniqueName, PATHINFO_FILENAME).'.mp4';
                $compressedPath = $this->videoPreviewService->generatePreview($file->getPathname(), $previewName, $base);

                $uploaded[] = PropertyMediaFile::create([
                    'propertyId' => $propertyId,
                    'fileName' => $originalName,
                    'originalPath' => $originalUrl,
                    'compressedPath' => $compressedPath,
                    'thumbnailPath' => null,
                    'mediaType' => 'uploadVideos',
                    'ordering' => ++$maxOrdering,
                    'createdBy' => $createdBy,
                ]);
            }
        }

        return $uploaded;
    }

    public function updatePropertyMediaFile($mediaId, array $validated, Request $request)
    {
        $file = $request->file('uploadPhotos')
            ?? $request->file('uploadVideos');

        // uploadPhotos/uploadVideos may come as an array; grab the first file
        if (is_array($file)) {
            $file = $file[0] ?? null;
        }

        return $this->propertyRepository->updatePropertyMediaFile(
            $mediaId,
            $validated,
            $file,
            $this->imageCompressionService,
            $this->videoPreviewService
        );
    }

    public function updateMediaAppearance(int $propertyId, string $type, string $action): int
    {
        $allowedColumns = ['isWebsite', 'isPortals', 'isFlyer'];

        if (! in_array($type, $allowedColumns, true)) {
            throw new \InvalidArgumentException("Invalid type: {$type}");
        }

        if (! in_array($action, ['check', 'uncheck'], true)) {
            throw new \InvalidArgumentException("Invalid action: {$action}");
        }

        $value = $action === 'check' ? 1 : 0;

        return PropertyMediaFile::where('propertyId', $propertyId)
            ->update([$type => $value]);
    }

    public function deletePropertyMedia($mediaId)
    {
        // Capture the media's type/property before the repo deletes it: removing a
        // photo changes the listing's imagery, so a fresh government QR is required.
        // Videos/documents don't affect the QR.
        $media = PropertyMediaFile::find($mediaId);

        $message = $this->propertyRepository->deletePropertyMedia($mediaId);

        if ($media && $media->mediaType === 'uploadPhotos'
            && ($property = Property::find($media->propertyId))
        ) {
            $this->markNewQrRequired($property);
        }

        return response()->json([
            'status' => true,
            'message' => $message,
        ]);
    }

    public function getPropertyByUserId($userId, Request $request)
    {
        return $this->propertyRepository
            ->getPropertyByAssignedUser($userId, $request);
    }

    public function getPublishedListingKeyDataSnapshot(Property $property): ?array
    {
        if ($property->status !== Constants::PROPERTY_STATUS_PUBLISHED) {
            return null;
        }

        $property->loadMissing('meta');

        return $this->getListingKeyData($property);
    }

    public function notifyPublishedListingKeyDataChanges(?array $before, Property $property, ?User $actor = null): void
    {
        if (empty($before)) {
            return;
        }

        $actor = $actor ?? user();
        if (! $actor) {
            return;
        }

        $property->loadMissing('meta');
        $after = $this->getListingKeyData($property);
        $changes = $this->getListingKeyDataChanges($before, $after);

        if (empty($changes)) {
            return;
        }

        $adminRoleIds = Role::whereNotIn('name', [Constants::AGENT, Constants::MANAGER, Constants::HR])->pluck('id');
        $recipients = User::whereIn('roleId', $adminRoleIds)
            ->where('status', Constants::USER_STATUS_ACTIVE)
            ->get(['id', 'name', 'email']);

        if ($recipients->isEmpty()) {
            return;
        }

        $notificationService = app(NotificationService::class);
        $mailService = app(MailService::class);
        $propertyLink = rtrim(env('FRONTEND_URL', config('app.url')), '/').'/dashboard/property/'.$property->id.'/view';
        $changedAt = now();

        foreach ($changes as $change) {
            $title = "Published listing key data changed: {$property->title}";
            $message = "{$actor->name} changed {$change['label']} on published listing {$property->referenceNumber}.";

            foreach ($recipients as $recipient) {
                $notificationService->notifyUser(
                    userId: $recipient->id,
                    actorId: $actor->id,
                    type: Constants::NOTIFICATION_TYPES['published_listing_key_data_changed'],
                    title: $title,
                    message: $message,
                    module: Constants::MODULE_PROPERTY,
                    moduleId: $property->id,
                    extraData: [
                        'propertyId' => $property->id,
                        'listingReference' => $property->referenceNumber,
                        'propertyTitle' => $property->title,
                        'changedBy' => $actor->name,
                        'fieldChanged' => $change['label'],
                        'fieldKey' => $change['key'],
                        'oldValue' => $change['oldValue'],
                        'newValue' => $change['newValue'],
                        'changedAt' => $changedAt->toISOString(),
                        'propertyLink' => $propertyLink,
                    ]
                );

                if (! $recipient->email) {
                    continue;
                }

                try {
                    $mailService->sendEmail(
                        Constants::EMAIL_SLUGS['property']['published_listing_key_data_changed'],
                        $recipient->email,
                        [
                            'recipient_name' => ucwords($recipient->name),
                            'listing_reference' => $property->referenceNumber ?? $property->id,
                            'property_title' => $property->title,
                            'changed_by' => ucwords($actor->name),
                            'field_changed' => $change['label'],
                            'old_value' => $change['oldValue'],
                            'new_value' => $change['newValue'],
                            'changed_date' => $changedAt->copy()->setTimezone(appTimezone())->format('F d, Y H:i'),
                            'property_link' => $propertyLink,
                            'company_name' => getCompanyName(),
                        ]
                    );
                } catch (\Throwable $e) {
                    \Log::error("Failed to send published listing key data change email to {$recipient->email}: ".$e->getMessage());
                }
            }
        }
    }

    private function getListingKeyData(Property $property): array
    {
        $meta = $property->meta
            ? $property->meta->pluck('metaValue', 'metaKey')->toArray()
            : [];

        return [
            'title' => $this->normalizeListingKeyValue($property->title),
            'price' => $this->normalizeListingKeyValue($property->price),
            'listingType' => $this->normalizeListingKeyValue($property->listingType),
            'propertyType' => $this->normalizeListingKeyValue(Constants::canonicalPropertyType($property->pfPropertyType, $property->bayutPropertyType) ?: $property->pfPropertyType ?: $property->bayutPropertyType),
            'bedrooms' => $this->normalizeListingKeyValue($property->bedrooms),
            'sizeArea' => $this->normalizeListingKeyValue($property->sizeArea),
            'pfBuiltUpArea' => $this->normalizeListingKeyValue($meta[Constants::PROPERTY_META_KEYS['pfBuiltUpArea']] ?? null),
            'plotSize' => $this->normalizeListingKeyValue($property->plotSize),
            'pfCommunity' => $this->normalizeListingKeyValue($property->pfCommunity),
            'status' => $this->normalizeListingKeyValue($property->status),
            'reraPermitNo' => $this->normalizeListingKeyValue($property->reraPermitNo),
            'govtQR' => $this->normalizeListingKeyValue($property->govtQR),
            'transactionNumber' => $this->normalizeListingKeyValue($meta[Constants::PROPERTY_META_KEYS['transactionNumber']] ?? null),
            'expireOn' => $this->normalizeListingKeyValue($meta[Constants::PROPERTY_META_KEYS['expireOn']] ?? null),
        ];
    }

    private function getListingKeyDataChanges(array $before, array $after): array
    {
        $changes = [];

        foreach (self::PUBLISHED_LISTING_KEY_FIELDS as $key => $label) {
            $oldValue = $before[$key] ?? null;
            $newValue = $after[$key] ?? null;

            if ($oldValue === $newValue) {
                continue;
            }

            $changes[] = [
                'key' => $key,
                'label' => $label,
                'oldValue' => $this->formatListingKeyValue($key, $oldValue),
                'newValue' => $this->formatListingKeyValue($key, $newValue),
            ];
        }

        return $changes;
    }

    private function normalizeListingKeyValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return trim((string) $value);
    }

    private function formatListingKeyValue(string $key, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return match ($key) {
            'listingType' => Constants::PROPERTY_PURPOSE_TYPE[$value] ?? $value,
            'status' => Constants::PROPERTY_STATUS[$value] ?? Constants::PROPERTY_INT_AND_OFF_STATUS[$value] ?? $value,
            'propertyType' => Constants::PROPERTY_TYPE_MAPPING[$value]['label'] ?? $this->getPropertyTypeLabel($value, $value) ?? $value,
            'pfCommunity' => PropertyCommunity::where('communityId', $value)->value('name') ?? $value,
            'govtQR' => 'Permit QR file',
            default => $value,
        };
    }

    // ================= notifyAdminsManagersOnStatusChange =================
    /**
     * Send notification and email to admins, managers and the assigned agent
     * whenever a listing status changes.
     */
    public static function notifyAdminsManagersOnStatusChange(
        Property $property,
        string $statusLabel,
        ?User $actor = null
    ): void {
        $actor = $actor ?? user();
        if (! $actor) {
            return;
        }

        $notificationService = app(NotificationService::class);
        $mailService = app(MailService::class);

        $adminRoleIds = Role::where('name', Constants::SUPER_ADMIN)->pluck('id');

        $recipientIds = User::whereIn('roleId', $adminRoleIds)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        // Include the assigned agent (or creator fallback) so the listing owner is always notified.
        $agentId = $property->agentId ?: $property->createdBy;
        if ($agentId) {
            $recipientIds[] = $agentId;
        }

        $recipientIds = array_values(array_unique(array_filter($recipientIds, fn ($id) => $id && $id != $actor->id)));

        if (empty($recipientIds)) {
            return;
        }

        $recipients = User::whereIn('id', $recipientIds)
            ->where('status', 'active')
            ->get(['id', 'name', 'email']);

        if ($recipients->isEmpty()) {
            return;
        }

        $propertyLink = env('FRONTEND_URL', config('app.url')).'/dashboard/property/'.$property->id.'/view';
        $title = "Listing status changed: {$property->title}";
        $message = "{$actor->name} changed the status of listing \"{$property->title}\" to \"{$statusLabel}\".";

        foreach ($recipients as $recipient) {
            // In-app notification
            $notificationService->notifyUser(
                userId: $recipient->id,
                actorId: $actor->id,
                type: Constants::NOTIFICATION_TYPES['listing_status_change'],
                title: $title,
                message: $message,
                module: Constants::MODULE_PROPERTY,
                moduleId: $property->id,
                extraData: [
                    'propertyId' => $property->id,
                    'propertyTitle' => $property->title,
                    'statusLabel' => $statusLabel,
                ]
            );

            // Email notification
            if ($recipient->email) {
                try {
                    $mailService->sendEmail(
                        Constants::EMAIL_SLUGS['property']['listing_status_change'],
                        $recipient->email,
                        [
                            'recipient_name' => ucwords($recipient->name),
                            'property_title' => $property->title,
                            'property_id' => $property->referenceNumber ?? $property->id,
                            'status' => $statusLabel,
                            'changed_by' => ucwords($actor->name),
                            'changed_date' => now()->setTimezone(appTimezone())->format('F d, Y H:i'),
                            'property_link' => $propertyLink,
                            'company_name' => getCompanyName(),
                        ]
                    );
                } catch (\Throwable $e) {
                    \Log::error("Failed to send listing status change email to {$recipient->email}: ".$e->getMessage());
                }
            }
        }
    }

    // ================= notifyListingMediaUpdated =================
    /**
     * Send a CRM (in-app) notification to admins and the listing agent when
     * images/media are changed on a PUBLISHED listing.
     *
     * @param  string  $mediaAction  e.g. 'added', 'removed', 'updated'
     */
    public static function notifyListingMediaUpdated(
        Property $property,
        string $mediaAction,
        ?int $count = null,
        ?User $actor = null
    ): void {
        // Only published listings raise this alert.
        if ($property->status !== Constants::PROPERTY_STATUS_PUBLISHED) {
            return;
        }

        $actor = $actor ?? user();
        if (! $actor) {
            return;
        }

        $notificationService = app(NotificationService::class);

        $adminRoleIds = Role::where('name', Constants::SUPER_ADMIN)->pluck('id');

        $recipientIds = User::whereIn('roleId', $adminRoleIds)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        // Include the listing agent (or creator fallback).
        $agentId = $property->agentId ?: $property->createdBy;
        if ($agentId) {
            $recipientIds[] = $agentId;
        }

        $recipientIds = array_values(array_unique(array_filter($recipientIds, fn ($id) => $id && $id != $actor->id)));

        if (empty($recipientIds)) {
            return;
        }

        $recipients = User::whereIn('id', $recipientIds)
            ->where('status', 'active')
            ->get(['id', 'name']);

        if ($recipients->isEmpty()) {
            return;
        }

        $listingRef = $property->referenceNumber ?? (string) $property->id;
        $countLabel = $count ? " ({$count})" : '';
        $title = "Listing media {$mediaAction}: {$listingRef}";
        $message = "{$actor->name} {$mediaAction} media on published listing {$listingRef}{$countLabel}.";

        foreach ($recipients as $recipient) {
            $notificationService->notifyUser(
                userId: $recipient->id,
                actorId: $actor->id,
                type: Constants::NOTIFICATION_TYPES['listing_media_updated'],
                title: $title,
                message: $message,
                module: Constants::MODULE_PROPERTY,
                moduleId: $property->id,
                extraData: [
                    'propertyId' => $property->id,
                    'listingReference' => $listingRef,
                    'propertyTitle' => $property->title,
                    'changedBy' => $actor->name,
                    'mediaAction' => $mediaAction,
                    'mediaCount' => $count,
                ]
            );
        }
    }
}
