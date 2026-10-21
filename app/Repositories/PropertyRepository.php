<?php

namespace App\Repositories;

use App\Constants\Constants;
use App\Exceptions\UserFacingException;
use App\Models\AssignTeamMember;
use App\Models\Enquiry;
use App\Models\Property;
use App\Models\PropertyApprovalHistory;
use App\Models\PropertyMediaFile;
use App\Models\PropertyMeta;
use App\Services\FileStorageService;
use App\Services\MatchingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PropertyRepository
{
    public function __construct(
        protected MatchingService $matchingService,
        protected FileStorageService $fileStorage
    ) {}

    private function applyUnifiedPropertyTypeFilter($query, Request $request): void
    {
        if (! $request->filled('propertyType')) {
            return;
        }

        $types = array_map('trim', explode(',', $request->propertyType));
        $pfValues = [];
        $bayutValues = [];
        foreach ($types as $type) {
            $row = Constants::PROPERTY_TYPE_MAPPING[$type] ?? null;
            if ($row) {
                if (! empty($row['pf'])) {
                    $pfValues[] = $row['pf'];
                }
                if (! empty($row['bayut'])) {
                    $bayutValues[] = $row['bayut'];
                }
            } else {
                $pfValues[] = $type;
            }
        }

        $query->where(function ($q) use ($pfValues, $bayutValues) {
            if (! empty($pfValues)) {
                $q->whereIn('pfPropertyType', array_unique($pfValues));
            }
            if (! empty($bayutValues)) {
                $q->orWhereIn('bayutPropertyType', array_unique($bayutValues));
            }
        });
    }

    public function getAll(Request $request)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        // Only eager-load what GetAllPropertyResource actually renders. The list
        // does not output `media`, `propertyCategories` or `tasks`, so loading them
        // was hydrating large child tables per page for nothing. listingOwner /
        // physicalOwner ARE rendered, so load them here to avoid a lazy N+1 per row.
        // The list needs just one representative photo, so load primaryMediaThumbnail
        // (one row per property) instead of the whole mediaThumbnails gallery.
        $query = Property::with(['primaryMediaThumbnail', 'creator', 'agent', 'listingOwner', 'physicalOwner', 'physicalOwner.mergedInto', 'city', 'community', 'subCommunity', 'buildingTower', 'meta', 'internalNotes'])
            ->withCount('acceptedOffers');

        // Agent self-scoping disabled: all roles (including agents) now see every listing.
        // if (isAgent()) {
        //     $query->where('agentId', user()->id);
        // } elseif ($request->filled('agentId')) {
        if ($request->filled('agentId')) {
            $query->where('agentId', $request->agentId);
        } elseif ($request->filled('teamId')) {
            $memberIds = AssignTeamMember::where('teamId', $request->teamId)->pluck('userId')->all();
            $query->whereIn('agentId', $memberIds ?: [0]);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('referenceNumber', 'LIKE', "%{$search}%")
                    ->orWhereHas('city', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('community', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('subCommunity', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('buildingTower', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }
        if ($request->filled('listing')) {
            $userId = user()->id ?? null;
            // All Active Listings – status = published
            if ($request->listing === 'published') {
                $query->where('status', Constants::PROPERTY_STATUS_PUBLISHED);
            }
            // My Active Listings – createdBy current user + status = published
            if ($request->listing === 'myActiveListings' && $userId) {
                $query->where('createdBy', $userId)
                    ->where('status', Constants::PROPERTY_STATUS_PUBLISHED);
            }
            // My Sold Listings – status = rentSold
            if ($request->listing === 'rentSold') {
                $query->where('status', Constants::PROPERTY_STATUS_RENTED_SOLD);
            }
            // All Listings — agent self-scoping disabled, everyone sees all listings.
            // if ($request->listing === 'all' && isAgent()) {
            //     $query->where('agentId', $userId);
            // }
        }

        if ($request->filled('statusDraft')) {
            $query->where('status', '!=', 'draft');
        }
        if ($request->filled('status')) {
            $statuses = array_map('trim', explode(',', $request->status));
            count($statuses) > 1 ? $query->whereIn('status', $statuses) : $query->where('status', $statuses[0]);
        }
        if ($request->filled('managedType')) {
            $managedTypes = array_values(array_filter(array_map('trim', explode(',', $request->managedType))));
            if (! empty($managedTypes)) {
                $query->where(function ($q) use ($managedTypes) {
                    foreach ($managedTypes as $type) {
                        $q->orWhereRaw('FIND_IN_SET(?, REPLACE(managedType, " ", "")) > 0', [$type]);
                    }
                });
            }
        }
        if ($request->filled('approvalStatus')) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', $request->approvalStatus))));
            count($statuses) > 1
                ? $query->whereIn('approvalStatus', $statuses)
                : $query->where('approvalStatus', $statuses[0]);
        }
        if ($request->filled('listingType')) {
            $listingTypes = array_map('trim', explode(',', $request->listingType));
            count($listingTypes) > 1 ? $query->whereIn('listingType', $listingTypes) : $query->where('listingType', $listingTypes[0]);
        }
        if ($request->filled('city')) {
            $cities = array_map('trim', explode(',', $request->city));
            $query->whereHas('city', function ($q) use ($cities) {
                $isNumeric = collect($cities)->every(fn ($v) => is_numeric($v));
                if ($isNumeric) {
                    count($cities) > 1 ? $q->whereIn('locationId', $cities) : $q->where('locationId', $cities[0]);
                } else {
                    count($cities) > 1 ? $q->whereIn('name', $cities) : $q->where('name', $cities[0]);
                }
            });
        }
        if ($request->filled('community')) {
            $communities = array_map('trim', explode(',', $request->community));
            $query->whereHas('community', function ($q) use ($communities) {
                $isNumeric = collect($communities)->every(fn ($v) => is_numeric($v));
                if ($isNumeric) {
                    count($communities) > 1 ? $q->whereIn('communityId', $communities) : $q->where('communityId', $communities[0]);
                } else {
                    count($communities) > 1 ? $q->whereIn('name', $communities) : $q->where('name', $communities[0]);
                }
            });
        }
        if ($request->filled('subCommunity')) {
            $subCommunities = array_map('trim', explode(',', $request->subCommunity));
            $query->whereHas('subCommunity', function ($q) use ($subCommunities) {
                $isNumeric = collect($subCommunities)->every(fn ($v) => is_numeric($v));
                if ($isNumeric) {
                    count($subCommunities) > 1 ? $q->whereIn('propertySubCommunityId', $subCommunities) : $q->where('propertySubCommunityId', $subCommunities[0]);
                } else {
                    count($subCommunities) > 1 ? $q->whereIn('name', $subCommunities) : $q->where('name', $subCommunities[0]);
                }
            });
        }
        if ($request->filled('building')) {
            $buildings = array_map('trim', explode(',', $request->building));
            $query->whereHas('buildingTower', function ($q) use ($buildings) {
                $isNumeric = collect($buildings)->every(fn ($v) => is_numeric($v));
                if ($isNumeric) {
                    count($buildings) > 1 ? $q->whereIn('propertyTowerId', $buildings) : $q->where('propertyTowerId', $buildings[0]);
                } else {
                    count($buildings) > 1 ? $q->whereIn('name', $buildings) : $q->where('name', $buildings[0]);
                }
            });
        }
        if ($request->filled('bedroomsMin') || $request->filled('bedroomsMax')) {
            $bedroomsMin = $request->filled('bedroomsMin') ? (int) $request->bedroomsMin : null;
            $bedroomsMax = $request->filled('bedroomsMax') ? (int) $request->bedroomsMax : null;
            if ($bedroomsMin !== null && $bedroomsMax !== null && $bedroomsMin > $bedroomsMax) {
                [$bedroomsMin, $bedroomsMax] = [$bedroomsMax, $bedroomsMin];
            }

            // Off-plan / International projects store bedrooms in the 6 unit-config meta slots
            // (unitTypeN), not on the bedrooms column — that column stays 0/studio at the project
            // level. Match those projects via the meta slots and all other listings via the column,
            // so a bedroom filter no longer drops every off-plan project.
            $intlTypes = Constants::PROPERTY_INT_AND_OFF_LISTING_TYPES;
            $query->where(function ($outer) use ($bedroomsMin, $bedroomsMax, $intlTypes) {
                $outer->where(function ($q) use ($bedroomsMin, $bedroomsMax, $intlTypes) {
                    $q->whereIn('listingType', $intlTypes);
                    $this->matchingService->applyOffplanUnitFiltersToPropertyQuery(
                        $q, $bedroomsMin, $bedroomsMax, null, null, null, null
                    );
                })->orWhere(function ($q) use ($bedroomsMin, $bedroomsMax, $intlTypes) {
                    $q->whereNotIn('listingType', $intlTypes);
                    if ($bedroomsMin !== null) {
                        $q->where('bedrooms', '>=', $bedroomsMin);
                    }
                    if ($bedroomsMax !== null) {
                        $q->where('bedrooms', '<=', $bedroomsMax);
                    }
                });
            });
        }

        if ($request->filled('bathroomsMin') || $request->filled('bathroomsMax')) {
            $bathroomsMin = $request->filled('bathroomsMin') ? (int) $request->bathroomsMin : null;
            $bathroomsMax = $request->filled('bathroomsMax') ? (int) $request->bathroomsMax : null;
            if ($bathroomsMin !== null && $bathroomsMax !== null && $bathroomsMin > $bathroomsMax) {
                [$bathroomsMin, $bathroomsMax] = [$bathroomsMax, $bathroomsMin];
            }
            if ($bathroomsMin !== null) {
                $query->where('bathrooms', '>=', $bathroomsMin);
            }
            if ($bathroomsMax !== null) {
                $query->where('bathrooms', '<=', $bathroomsMax);
            }
        }

        if ($request->filled('sizeMin') || $request->filled('sizeMax')) {
            $sizeMin = $request->filled('sizeMin') ? floatval($request->sizeMin) : null;
            $sizeMax = $request->filled('sizeMax') ? floatval($request->sizeMax) : null;
            if ($sizeMin !== null && $sizeMax !== null && $sizeMin > $sizeMax) {
                [$sizeMin, $sizeMax] = [$sizeMax, $sizeMin];
            }
            if ($sizeMin !== null) {
                $query->whereRaw('CAST(sizeArea AS DECIMAL(20,2)) >= ?', [$sizeMin]);
            }
            if ($sizeMax !== null) {
                $query->whereRaw('CAST(sizeArea AS DECIMAL(20,2)) <= ?', [$sizeMax]);
            }
        }

        if ($request->filled('plotSizeMin') || $request->filled('plotSizeMax')) {
            $plotSizeMin = $request->filled('plotSizeMin') ? floatval($request->plotSizeMin) : null;
            $plotSizeMax = $request->filled('plotSizeMax') ? floatval($request->plotSizeMax) : null;
            if ($plotSizeMin !== null && $plotSizeMax !== null && $plotSizeMin > $plotSizeMax) {
                [$plotSizeMin, $plotSizeMax] = [$plotSizeMax, $plotSizeMin];
            }
            if ($plotSizeMin !== null) {
                $query->whereRaw('CAST(plotSize AS DECIMAL(20,2)) >= ?', [$plotSizeMin]);
            }
            if ($plotSizeMax !== null) {
                $query->whereRaw('CAST(plotSize AS DECIMAL(20,2)) <= ?', [$plotSizeMax]);
            }
        }

        if ($request->filled('minPrice') || $request->filled('maxPrice')) {
            $minPrice = $request->filled('minPrice') ? floatval($request->minPrice) : null;
            $maxPrice = $request->filled('maxPrice') ? floatval($request->maxPrice) : null;
            if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
                [$minPrice, $maxPrice] = [$maxPrice, $minPrice];
            }
            if ($minPrice !== null) {
                $query->where('price', '>=', $minPrice);
            }
            if ($maxPrice !== null) {
                $query->where('price', '<=', $maxPrice);
            }
        }
        if ($request->filled('category')) {
            $categoryValues = array_map('trim', explode(',', $request->category));
            count($categoryValues) > 1 ? $query->whereIn('category', $categoryValues) : $query->where('category', $categoryValues[0]);
        }
        if ($request->filled('pfPropertyType')) {
            $pfPropertyTypes = array_map('trim', explode(',', $request->pfPropertyType));
            count($pfPropertyTypes) > 1 ? $query->whereIn('pfPropertyType', $pfPropertyTypes) : $query->where('pfPropertyType', $pfPropertyTypes[0]);
        }
        if ($request->filled('pfUnitNumber')) {
            $pfUnitNumber = $request->pfUnitNumber;
            $query->whereHas('meta', function ($q) use ($pfUnitNumber) {
                $q->where('metaKey', 'pfUnitNumber')
                    ->where('metaValue', 'LIKE', "%{$pfUnitNumber}%");
            });
        }
        if ($request->filled('propertyAddress')) {
            $propertyAddress = $request->propertyAddress;
            $query->whereHas('meta', function ($q) use ($propertyAddress) {
                $q->where('metaKey', 'propertyAddress')
                    ->where('metaValue', 'LIKE', "%{$propertyAddress}%");
            })->orWhereHas('subCommunity', function ($q) use ($propertyAddress) {
                $q->where('name', 'LIKE', "%{$propertyAddress}%");
            })->orWhereHas('buildingTower', function ($q) use ($propertyAddress) {
                $q->where('name', 'LIKE', "%{$propertyAddress}%");
            });
        }
        if ($request->filled('newQrRequired') && (int) $request->newQrRequired === 1) {
            $query->whereHas('meta', function ($q) {
                $q->where('metaKey', 'newQrRequired')->where('metaValue', '1');
            });
        }
        // Portal account filters. `pfPortal` / `bayutPortal` carry the portal
        // account slug the listing was published under (one, or a comma-separated
        // list). That slug lives in the `account` key of the portal's listing meta
        // JSON, and metaValue is a longtext — not a JSON column — so match the
        // encoded `"account":"<slug>"` fragment rather than JSON_EXTRACT, which
        // would blow up on any row that isn't valid JSON. Each portal is matched
        // on account AND a live portalStatus — PF says `live`, Bayut says `active`
        // — so unpublished / taken-down / failed listings that still carry the meta
        // row don't count as being on the portal. The two params OR with each
        // other: (pfPortal + live) OR (bayutPortal + live).
        $portalAccounts = [];
        foreach (['pfPortal' => 'pfPortalListingData', 'bayutPortal' => 'bayutPortalListingData'] as $param => $metaKey) {
            if (! $request->filled($param)) {
                continue;
            }
            $accounts = is_array($request->$param)
                ? $request->$param
                : explode(',', $request->$param);
            $accounts = array_values(array_filter(array_map('trim', $accounts)));
            if (! empty($accounts)) {
                $portalAccounts[$metaKey] = $accounts;
            }
        }
        if (! empty($portalAccounts)) {
            $query->where(function ($outer) use ($portalAccounts) {
                foreach ($portalAccounts as $metaKey => $accounts) {
                    $outer->orWhereHas('meta', function ($q) use ($metaKey, $accounts) {
                        $q->where('metaKey', $metaKey)
                            ->where(function ($inner) use ($accounts) {
                                foreach ($accounts as $account) {
                                    $inner->orWhere('metaValue', 'LIKE', '%"account":"'.$account.'"%');
                                }
                            })
                            ->where(function ($inner) {
                                foreach (['live', 'active'] as $portalStatus) {
                                    $inner->orWhere('metaValue', 'LIKE', '%"portalStatus":"'.$portalStatus.'"%');
                                }
                            });
                    });
                }
            });
        }
        // Own-website / Generic-XML publish flags. Both toggles are stored as a
        // `1` meta row that is DELETED when switched off (see updatePublishFlags),
        // so "not published" means "no row holding 1" — whereDoesntHave, not a `0`
        // comparison. Accepts yes/no, true/false, 1/0; anything else is ignored.
        foreach (['publishedOnWebsite' => 'publishOnWebsite', 'publishedOnGeneric' => 'publishOnGeneric'] as $param => $flag) {
            if (! $request->filled($param)) {
                continue;
            }
            $wantsPublished = filter_var($request->$param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($wantsPublished === null) {
                continue;
            }
            $metaKey = Constants::PROPERTY_META_KEYS[$flag];
            $isPublished = function ($q) use ($metaKey) {
                $q->where('metaKey', $metaKey)->where('metaValue', '1');
            };
            $wantsPublished ? $query->whereHas('meta', $isPublished) : $query->whereDoesntHave('meta', $isPublished);
        }
        $this->applyUnifiedPropertyTypeFilter($query, $request);
        if ($request->filled('completionStatus')) {
            $completionStatuses = array_map('trim', explode(',', $request->completionStatus));
            count($completionStatuses) > 1 ? $query->whereIn('completionStatus', $completionStatuses) : $query->where('completionStatus', $completionStatuses[0]);
        }
        if ($request->filled('propertyCategoryId')) {
            $categories = array_map('trim', explode(',', $request->propertyCategoryId));
            $query->whereHas('propertyCategories', function ($q) use ($categories) {
                $q->whereIn('id', $categories)->orWhereIn('name', $categories);
            });
        }
        if ($request->filled('users')) {
            $users = is_array($request->users)
                ? $request->users
                : array_map('trim', explode(',', $request->users));
            count($users) > 1 ? $query->whereIn('agentId', $users) : $query->where('agentId', $users[0]);
        }

        if ($request->filled('startDate')) {
            $query->where('created_at', '>=', $request->startDate.' 00:00:00');
        }
        if ($request->filled('endDate')) {
            $query->where('created_at', '<=', $request->endDate.' 23:59:59');
        }

        if ($request->filled('id')) {
            $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$request->id]);
        }

        $this->applyListingSorting($query, $request);

        return $query->paginate($perPage);
    }

    // Apply user-requested column sorting to the listings query.

    private function applyListingSorting($query, Request $request): void
    {
        $sortBy = $request->filled('sortBy') ? (string) $request->sortBy : null;
        $dir = strtolower((string) $request->sortDir) === 'asc' ? 'asc' : 'desc';

        $relatedNameColumns = [
            'agentId' => ['agentId', 'users', 'id'],
            'listingOwnerId' => ['listingOwnerId', 'users', 'id'],
            'createdBy' => ['createdBy', 'users', 'id'],
            'physicalOwnerId' => ['physicalOwnerId', 'contacts', 'id'],
            'community' => ['pfCommunity', 'property_communities', 'communityId'],
            'subCommunity' => ['pfSubCommunity', 'property_sub_communities', 'propertySubCommunityId'],
            'pfCity' => ['pfCity', 'property_locations', 'locationId'],
            'city' => ['pfCity', 'property_locations', 'locationId'],
            'pfBuilding' => ['pfBuilding', 'property_towers', 'propertyTowerId'],
            'buildingTower' => ['pfBuilding', 'property_towers', 'propertyTowerId'],
        ];

        if ($sortBy) {
            if (isset($relatedNameColumns[$sortBy])) {
                [$foreignKey, $table, $keyColumn] = $relatedNameColumns[$sortBy];
                $query->orderBy(
                    DB::table($table)
                        ->select('name')
                        ->whereColumn($table.'.'.$keyColumn, 'properties.'.$foreignKey)
                        ->limit(1),
                    $dir
                );
            } elseif (in_array($sortBy, $this->propertyColumns(), true)) {
                $query->orderBy('properties.'.$sortBy, $dir);
            } else {
                $query->orderBy(
                    DB::table('property_meta')
                        ->select('metaValue')
                        ->whereColumn('property_meta.propertyId', 'properties.id')
                        ->where('metaKey', $sortBy)
                        ->limit(1),
                    $dir
                );
            }
        }

        $query->orderBy('properties.id', 'desc');
    }

    /** Cached list of real column names on the `properties` table. */
    private function propertyColumns(): array
    {
        static $columns = null;
        if ($columns === null) {
            $columns = Schema::getColumnListing((new Property)->getTable());
        }

        return $columns;
    }

    /**
     * Base query for portal feeds: published/pocket/live listings flagged for the
     * given portal meta key, with the standard relations and request filters
     * (updated_after / updated_before / listingType) applied. Returns the builder
     * un-executed so callers can paginate, ->get(), or stream via chunkById().
     */
    public function portalFeedQuery(Request $request, string $metaKey)
    {
        $query = Property::with(['mediaThumbnails', 'media', 'documentCenters', 'videos', 'agent', 'city', 'community', 'subCommunity', 'buildingTower', 'meta', 'salesforceIdMap'])
            ->whereIn('status', [
                Constants::PROPERTY_STATUS_PUBLISHED,
                Constants::PROPERTY_STATUS_POCKET_LISTING,
                Constants::PROPERTY_STATUS_LIVE_ON_WEBSITE,
            ])
            ->whereHas('meta', function ($q) use ($metaKey) {
                $q->where('metaKey', $metaKey)->where('metaValue', '1');
            });

        if ($request->filled('updated_after')) {
            $query->where('updated_at', '>=', $request->updated_after);
        }

        if ($request->filled('updated_before')) {
            $query->where('updated_at', '<=', $request->updated_before);
        }

        if ($request->filled('listingType')) {
            $listingTypes = array_map('trim', explode(',', $request->listingType));
            count($listingTypes) > 1
                ? $query->whereIn('listingType', $listingTypes)
                : $query->where('listingType', $listingTypes[0]);
        }

        return $query;
    }

    public function getPortalFeedListings(Request $request, string $metaKey, bool $paginate = true)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        $query = $this->portalFeedQuery($request, $metaKey)->orderBy('id', 'desc');

        // XML/portal feeds need the full result set, not a single page — callers
        // pass $paginate = false to get every matching listing (filters still apply).
        return $paginate ? $query->paginate($perPage) : $query->get();
    }

    public function getAllMatchingProperties(Request $request)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        $isExactMatch = filter_var($request->input('isExactMatch'), FILTER_VALIDATE_BOOLEAN);

        // When listing=all, skip listingType filter
        $skipListingType = $request->filled('listing') && $request->listing === 'all';

        $listingTypes = $request->filled('listingType')
            ? array_values(array_filter(array_map('trim', explode(',', $request->listingType))))
            : [];

        $intlTypes = Constants::PROPERTY_INT_AND_OFF_LISTING_TYPES;

        // Split requested listingTypes into intl/offplan vs other. Empty input or listing=all
        // means "no user constraint" → both branches run against their full type set.
        // 'sale' in the request implicitly includes the intl/offplan domain (off-plan and
        // international are sale-side projects), so when sale is selected, the intl branch
        // runs against the full intl type set regardless of whether the user listed offPlan/
        // international explicitly. Rent stays rent-only.
        if ($skipListingType || empty($listingTypes)) {
            $intlSubset = [];
            $otherSubset = [];
            $runIntl = true;
            $runOther = true;
        } else {
            $intlSubset = array_values(array_intersect($listingTypes, $intlTypes));
            $otherSubset = array_values(array_diff($listingTypes, $intlTypes));
            $saleIncluded = in_array(Constants::PROPERTY_PURPOSE_SALE, $otherSubset, true);
            if ($saleIncluded) {
                $intlSubset = [];
            }
            $runIntl = $saleIncluded || ! empty($intlSubset);
            $runOther = ! empty($otherSubset);
        }

        $branches = [];

        if ($runIntl) {
            $branches[] = $this->buildIntlOffplanMatchingQuery($request, $intlSubset, $intlTypes);
        }

        if ($runOther) {
            $branches[] = $this->buildOtherListingMatchingQuery($request, $isExactMatch, $otherSubset, $intlTypes);
        }

        $query = array_shift($branches);
        foreach ($branches as $b) {
            $query->union($b);
        }

        $query->orderBy('id', 'desc');

        $rawSql = vsprintf(
            str_replace('?', "'%s'", $query->toSql()),
            array_map(fn ($b) => is_bool($b) ? (int) $b : $b, $query->getBindings())
        );
        Log::info("getAllMatchingProperties SQL:\n".$rawSql);

        return $query->paginate($perPage);
    }

    /**
     * Off-plan / International projects: price / bedrooms / size live in 6 unit-config meta slots
     * (unitTypeN / unitTypeBuildUpAreaN / unitTypePriceN). A project qualifies if ANY slot satisfies
     * the enquiry criteria. Bathrooms / yearBuilt / parkingSpaces / plotSize don't apply at the
     * project level and are skipped.
     */
    private function buildIntlOffplanMatchingQuery(Request $request, array $intlSubset, array $intlTypes)
    {
        $query = Property::with(['mediaThumbnails', 'propertyCategories', 'community', 'subCommunity', 'buildingTower', 'city', 'agent', 'creator', 'meta'])
            ->whereIn('listingType', ! empty($intlSubset) ? $intlSubset : $intlTypes);

        $this->applyCommonMatchingFilters($query, $request);

        $this->matchingService->applyOffplanUnitFiltersToPropertyQuery(
            $query,
            $request->filled('bedroomsMin') ? (int) $request->input('bedroomsMin') : null,
            $request->filled('bedroomsMax') ? (int) $request->input('bedroomsMax') : null,
            $request->filled('sizeMin') ? (float) $request->input('sizeMin') : null,
            $request->filled('sizeMax') ? (float) $request->input('sizeMax') : null,
            $request->filled('priceMin') ? (float) $request->input('priceMin') : null,
            $request->filled('priceMax') ? (float) $request->input('priceMax') : null,
        );

        return $query;
    }

    /**
     * Sale / Rent (single-unit) listings: price, bedrooms, bathrooms, etc. live on the property row.
     * Standard column-based filters apply.
     */
    private function buildOtherListingMatchingQuery(Request $request, bool $isExactMatch, array $otherSubset, array $intlTypes)
    {
        $query = Property::with(['mediaThumbnails', 'propertyCategories', 'community', 'subCommunity', 'buildingTower', 'city', 'agent', 'creator', 'meta'])
            ->whereNotIn('listingType', $intlTypes);

        if (! empty($otherSubset)) {
            $query->whereIn('listingType', $otherSubset);
        }

        $this->applyCommonMatchingFilters($query, $request);

        // Pass a single listingType to the variance calc; null lets MatchingService OR both rent/sale rules
        $variancePivotListingType = count($otherSubset) === 1 ? $otherSubset[0] : null;

        if ($isExactMatch && $request->filled('priceMin')) {
            $this->matchingService->applyPriceFilterFromProperty($query, $request->input('priceMin'));
        } elseif ($request->filled('priceMin') || $request->filled('priceMax')) {
            $this->matchingService->applyPriceRangeFilterFromProperty(
                $query,
                $request->input('priceMin'),
                $request->input('priceMax'),
                $variancePivotListingType
            );
        }

        $this->applyIntRangeFilter($query, $request, 'bedrooms', 'bedroomsMin', 'bedroomsMax', $isExactMatch);
        $this->applyIntRangeFilter($query, $request, 'bathrooms', 'bathroomsMin', 'bathroomsMax', $isExactMatch);
        $this->applyIntRangeFilter($query, $request, 'yearBuilt', 'yearBuiltMin', 'yearBuiltMax', $isExactMatch);
        $this->applyIntRangeFilter($query, $request, 'parkingSpaces', 'parkingSpaceMin', 'parkingSpaceMax', $isExactMatch);
        $this->applyDecimalRangeFilter($query, $request, 'sizeArea', 'sizeMin', 'sizeMax', $isExactMatch);
        $this->applyDecimalRangeFilter($query, $request, 'plotSize', 'plotSizeMin', 'plotSizeMax', $isExactMatch);

        return $query;
    }

    private function applyCommonMatchingFilters($query, Request $request): void
    {
        if (isAgent()) {
            $query->where('agentId', user()->id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('referenceNumber', 'LIKE', "%{$search}%")
                    ->orWhere('listingType', 'LIKE', "%{$search}%")
                    ->orWhereRaw('LOWER(status) LIKE ?', ['%'.strtolower($search).'%']);
            });
        }

        if ($request->filled('status')) {
            $statuses = array_map('trim', explode(',', $request->status));
            $query->whereIn('status', $statuses);
        } else {
            $query->whereIn('status', Constants::MATCHING_ALLOWED_STATUSES);
        }

        $communities = array_values(array_filter(array_map('trim', array_merge(
            $request->filled('community') ? explode(',', $request->community) : [],
            $request->filled('community_2') ? explode(',', $request->community_2) : [],
            $request->filled('community_3') ? explode(',', $request->community_3) : [],
        )), fn ($v) => $v !== ''));

        if (! empty($communities)) {
            $query->whereIn('pfCommunity', $communities);
        }

        if ($request->filled('subCommunity')) {
            $subCommunities = array_map('trim', explode(',', $request->subCommunity));
            $query->whereIn('pfSubCommunity', $subCommunities);
        }

        if ($request->filled('building')) {
            $buildings = array_map('trim', explode(',', $request->building));
            $query->whereIn('pfBuilding', $buildings);
        }

        if ($request->filled('city')) {
            $cities = array_map('trim', explode(',', $request->city));
            $query->whereIn('pfCity', $cities);
        }

        if ($request->filled('category')) {
            $categories = array_map('trim', explode(',', $request->category));
            count($categories) > 1
                ? $query->whereIn('category', $categories)
                : $query->where('category', $categories[0]);
        }

        if ($request->filled('pfPropertyType')) {
            $pfPropertyTypes = array_map('trim', explode(',', $request->pfPropertyType));
            count($pfPropertyTypes) > 1
                ? $query->whereIn('pfPropertyType', $pfPropertyTypes)
                : $query->where('pfPropertyType', $pfPropertyTypes[0]);
        }

        $this->applyUnifiedPropertyTypeFilter($query, $request);

        if ($request->filled('propertyCategoryId')) {
            $categoryIds = array_map('trim', explode(',', $request->propertyCategoryId));
            $query->whereHas('propertyCategories', function ($q) use ($categoryIds) {
                $q->whereIn('id', $categoryIds)->orWhereIn('name', $categoryIds);
            });
        }

        if ($request->filled('startDate')) {
            $query->where('created_at', '>=', $request->startDate.' 00:00:00');
        }
        if ($request->filled('endDate')) {
            $query->where('created_at', '<=', $request->endDate.' 23:59:59');
        }
    }

    /**
     * Default matching rule: builds the query of active properties whose stored attributes
     * satisfy the enquiry's stored criteria. Callers add their own eager-loads and finalize
     * with ->get() or ->paginate(). Used by the daily scheduler and the linkedProperties
     * endpoint — both consume the same fixed rule, no Request overrides.
     */
    public function matchActiveListingsForEnquiry(Enquiry $enquiry)
    {
        $intlTypes = Constants::PROPERTY_INT_AND_OFF_LISTING_TYPES;
        $listingType = $enquiry->listingType ?: null;

        // Mirror getAllMatchingProperties: a 'sale' enquiry also matches off-plan/international
        // projects (both are sale-side). Rent stays rent-only. Intl/off-plan enquiries match
        // only intl/off-plan. No listingType → both branches run unconstrained.
        if ($listingType === null) {
            $runIntl = true;
            $runOther = true;
            $intlSubset = $intlTypes;
            $otherSubset = [];
        } elseif (in_array($listingType, $intlTypes, true)) {
            $runIntl = true;
            $runOther = false;
            $intlSubset = [$listingType];
            $otherSubset = [];
        } elseif ($listingType === Constants::PROPERTY_PURPOSE_SALE) {
            $runIntl = true;
            $runOther = true;
            $intlSubset = $intlTypes;
            $otherSubset = [$listingType];
        } else {
            $runIntl = false;
            $runOther = true;
            $intlSubset = [];
            $otherSubset = [$listingType];
        }

        $branches = [];

        if ($runIntl) {
            $branches[] = $this->buildIntlOffplanMatchingQueryForEnquiry($enquiry, $intlSubset);
        }

        if ($runOther) {
            $branches[] = $this->buildOtherListingMatchingQueryForEnquiry($enquiry, $otherSubset, $intlTypes);
        }

        $query = array_shift($branches);
        foreach ($branches as $b) {
            $query->union($b);
        }

        return $query->orderBy('id', 'desc');
    }

    private function buildIntlOffplanMatchingQueryForEnquiry(Enquiry $enquiry, array $intlSubset)
    {
        $query = Property::query()
            ->whereIn('status', Constants::MATCHING_ALLOWED_STATUSES)
            ->whereIn('listingType', $intlSubset);

        $this->matchingService->applyOffplanUnitFiltersToPropertyQuery(
            $query,
            $enquiry->bedroomsMin !== null ? (int) $enquiry->bedroomsMin : null,
            $enquiry->bedroomsMax !== null ? (int) $enquiry->bedroomsMax : null,
            $enquiry->sizeMin !== null ? (float) $enquiry->sizeMin : null,
            $enquiry->sizeMax !== null ? (float) $enquiry->sizeMax : null,
            $enquiry->priceMin !== null ? (float) $enquiry->priceMin : null,
            $enquiry->priceMax !== null ? (float) $enquiry->priceMax : null,
        );

        if ($enquiry->city) {
            $query->where('pfCity', $enquiry->city);
        }
        $this->matchingService->applyEnquiryCommunitiesFilterForProperty($query, $enquiry);

        return $query;
    }

    private function buildOtherListingMatchingQueryForEnquiry(Enquiry $enquiry, array $otherSubset, array $intlTypes)
    {
        $query = Property::query()
            ->whereIn('status', Constants::MATCHING_ALLOWED_STATUSES)
            ->whereNotIn('listingType', $intlTypes);

        if (! empty($otherSubset)) {
            $query->whereIn('listingType', $otherSubset);
        }

        if ($enquiry->priceMin || $enquiry->priceMax) {
            $this->matchingService->applyPriceRangeFilterFromProperty(
                $query,
                $enquiry->priceMin,
                $enquiry->priceMax,
                count($otherSubset) === 1 ? $otherSubset[0] : null
            );
        }

        if ($enquiry->bedroomsMin) {
            $query->where('bedrooms', '>=', (int) $enquiry->bedroomsMin);
        }
        if ($enquiry->bedroomsMax) {
            $query->where('bedrooms', '<=', (int) $enquiry->bedroomsMax);
        }
        if ($enquiry->bathroomsMin) {
            $query->where('bathrooms', '>=', (int) $enquiry->bathroomsMin);
        }
        if ($enquiry->bathroomsMax) {
            $query->where('bathrooms', '<=', (int) $enquiry->bathroomsMax);
        }
        if ($enquiry->sizeMin) {
            $query->whereRaw('CAST(sizeArea AS DECIMAL(20,2)) >= ?', [(float) $enquiry->sizeMin]);
        }
        if ($enquiry->sizeMax) {
            $query->whereRaw('CAST(sizeArea AS DECIMAL(20,2)) <= ?', [(float) $enquiry->sizeMax]);
        }

        if ($enquiry->city) {
            $query->where('pfCity', $enquiry->city);
        }
        $this->matchingService->applyEnquiryCommunitiesFilterForProperty($query, $enquiry);

        return $query;
    }

    private function applyIntRangeFilter($query, Request $request, string $column, string $minKey, string $maxKey, bool $isExactMatch): void
    {
        if ($isExactMatch && $request->filled($minKey)) {
            $query->where($column, (int) $request->input($minKey));

            return;
        }
        if ($request->filled($minKey)) {
            $query->where($column, '>=', (int) $request->input($minKey));
        }
        if ($request->filled($maxKey)) {
            $query->where($column, '<=', (int) $request->input($maxKey));
        }
    }

    private function applyDecimalRangeFilter($query, Request $request, string $column, string $minKey, string $maxKey, bool $isExactMatch): void
    {
        if ($isExactMatch && $request->filled($minKey)) {
            $query->whereRaw("CAST({$column} AS DECIMAL(20,2)) = ?", [floatval($request->input($minKey))]);

            return;
        }
        if ($request->filled($minKey)) {
            $query->whereRaw("CAST({$column} AS DECIMAL(20,2)) >= ?", [floatval($request->input($minKey))]);
        }
        if ($request->filled($maxKey)) {
            $query->whereRaw("CAST({$column} AS DECIMAL(20,2)) <= ?", [floatval($request->input($maxKey))]);
        }
    }

    public function findById($id)
    {
        $query = Property::with([
            'mediaCompressed',
            'propertyCategories',
            'propertyListingHistory',
            'tasks.assignedUser',
            'creator',
            'meta',
            'internalNotes',
        ]);

        if (isAgent()) {
            $query->where('agentId', user()->id);
        }

        return $query->find($id);
    }

    public function create(array $data)
    {
        return Property::create($data);
    }

    public function update(Property $property, array $data)
    {
        // A listing that lands on Published (from the edit form or any other direct
        // status change) is approved by definition — the dedicated publish/approve
        // flows already do this, so keep the generic update path consistent.
        if (($data['status'] ?? null) === Constants::PROPERTY_STATUS_PUBLISHED) {
            $data['approvalStatus'] = Constants::APPROVAL_COMPLETED;
        }

        $property->update($data);

        return $property->fresh();
    }

    public function createHistory($property, array $newData)
    {
        $originalValues = [];
        $newValues = [];

        foreach ($newData as $field => $newValue) {

            if (! array_key_exists($field, $property->getAttributes())) {
                continue;
            }

            $oldValue = $property->$field;

            if ($oldValue != $newValue) {
                $originalValues[$field] = is_array($oldValue) ? $oldValue : (string) $oldValue;
                $newValues[$field] = is_array($newValue) ? $newValue : (string) $newValue;
            }
        }

        if (! empty($newValues)) {
            logPropertyHistory([
                'propertyId' => $property->id,
                'field' => 'updated',
                'originalValue' => $originalValues,
                'newValue' => $newValues,
            ]);
        }
    }

    public function delete(Property $property)
    {
        return $property->delete();
    }

    // ================= requestPermit =================
    public function requestPermit(Property $property): Property
    {
        $updateData = [
            'approvalStatus' => Constants::PERMIT_REQUESTED,
            'permitRequestedAt' => now(),
        ];

        // Off-plan listings stay on their website-only status track (Upcoming/Live on Website/Pocket Listing)
        if ($property->listingType !== Constants::PROPERTY_PURPOSE_OFFPLAN) {
            $updateData['status'] = Constants::PROPERTY_STATUS_PENDING;
        }

        $property->update($updateData);

        logPropertyHistory([
            'propertyId' => $property->id,
            'field' => 'permit_requested',
        ]);

        PropertyApprovalHistory::create([
            'listingId' => $property->id,
            'status' => $property->listingType === 'vacationHomes' ? 'DTCM Code Requested' : 'Permit Number Requested',
            'comment' => ($property->listingType === 'vacationHomes' ? 'DTCM code' : 'Permit number').' requested by <a href="'.config('app.frontend_url').(isAgent() ? '/dashboard/agents/'.user()->id.'/edit' : '/dashboard/users/'.user()->id).'"><strong>'.ucwords(user()->name).'</strong></a>',
            'auditBy' => user()->id,
            'auditDate' => now(),
        ]);

        return $property->fresh();
    }

    // ================= enterPermitNumber =================
    public function enterPermitNumber(Property $property, string $permitNumber, $govtQR = null, ?string $transactionNumber = null, ?string $expireOn = null): Property
    {
        $oldStatus = $property->status;

        $updateData = [
            'approvalStatus' => Constants::APPROVAL_REQUESTED,
            'reraPermitNo' => $permitNumber,
        ];

        // Off-plan listings stay on their website-only status track (Upcoming/Live on Website/Pocket Listing)
        if ($property->listingType !== Constants::PROPERTY_PURPOSE_OFFPLAN) {
            $updateData['status'] = Constants::PROPERTY_STATUS_PENDING;
        }

        if ($govtQR) {
            $fileName = time().'_govtQR_'.$property->id.'.'.$govtQR->getClientOriginalExtension();
            $base = $this->fileStorage->recordPrefix('properties', $property->referenceNumber, $property->id);
            $updateData['govtQR'] = $this->fileStorage->uploadPublicFile($govtQR, $base.'/govtQR', $fileName);
        }

        $property->update($updateData);

        if ($transactionNumber !== null) {
            PropertyMeta::updateOrCreate(
                ['propertyId' => $property->id, 'metaKey' => Constants::PROPERTY_META_KEYS['transactionNumber']],
                ['metaValue' => $transactionNumber],
            );
        }

        if ($expireOn !== null) {
            PropertyMeta::updateOrCreate(
                ['propertyId' => $property->id, 'metaKey' => Constants::PROPERTY_META_KEYS['expireOn']],
                ['metaValue' => $expireOn],
            );
        }

        logPropertyHistory([
            'propertyId' => $property->id,
            'field' => 'permit_number_entered',
            'originalValue' => json_encode(['message' => 'adminRequest']),
        ]);

        PropertyApprovalHistory::create([
            'listingId' => $property->id,
            'status' => $property->listingType === 'vacationHomes' ? 'DTCM Code Entered' : 'Permit Number Entered',
            'comment' => ($property->listingType === 'vacationHomes' ? 'DTCM code' : 'Permit number').' '.$permitNumber.' entered by <a href="'.config('app.frontend_url').(isAgent() ? '/dashboard/agents/'.user()->id.'/edit' : '/dashboard/users/'.user()->id).'"><strong>'.ucwords(user()->name).'</strong></a>',
            'auditBy' => user()->id,
            'auditDate' => now(),
        ]);

        return $property->fresh();
    }

    // ================= requestApproval =================
    public function requestApproval(Property $property): Property
    {
        $oldStatus = $property->status;

        $updateData = ['approvalStatus' => Constants::APPROVAL_REQUESTED];

        // Off-plan listings stay on their website-only status track (Upcoming/Live on Website/Pocket Listing)
        $isOffplan = $property->listingType === Constants::PROPERTY_PURPOSE_OFFPLAN;
        if (! $isOffplan) {
            $updateData['status'] = 'pending';
        }

        $property->update($updateData);

        if (! $isOffplan) {
            logPropertyHistory([
                'propertyId' => $property->id,
                'field' => 'status',
                'originalValue' => json_encode(['message' => $oldStatus]),
                'newValue' => json_encode(['message' => 'pending']),
            ]);
        }

        PropertyApprovalHistory::create([
            'listingId' => $property->id,
            'status' => 'pendingApproval',
            'comment' => 'Approval requested by <a href="'.config('app.frontend_url').(isAgent() ? '/dashboard/agents/'.user()->id.'/edit' : '/dashboard/users/'.user()->id).'"><strong>'.ucwords(user()->name).'</strong></a>',
            'auditBy' => user()->id,
            'auditDate' => now(),
        ]);

        return $property->fresh();
    }

    // ================= approveRejectProperty =================
    public function approveRejectProperty(Property $property, string $status, string $comment): Property
    {
        $oldStatus = $property->status;
        $isApproved = $status === 'approved';

        if ($isApproved) {
            $comment = 'Listing approved by <a href="'.config('app.frontend_url').(isAgent() ? '/dashboard/agents/'.user()->id.'/edit' : '/dashboard/users/'.user()->id).'"><strong>'.ucwords(user()->name).'</strong></a>';
            // Off-plan listings go Live on Website (no portal publish); other listings get the standard Published status
            $approvedStatus = $property->listingType === Constants::PROPERTY_PURPOSE_OFFPLAN
                ? Constants::PROPERTY_STATUS_LIVE_ON_WEBSITE
                : Constants::PROPERTY_STATUS_PUBLISHED;
            $updateData = [
                'approvalStatus' => Constants::APPROVAL_COMPLETED,
                'approvedBy' => user()->id,
                'approvedAt' => now(),
                'latestApprovalComment' => $comment,
                'status' => $approvedStatus,
            ];
        } else {
            // Existing code kept the property in its current status, which blocked re-requesting approval.
            $updateData = [
                'status' => Constants::PROPERTY_STATUS_DRAFT,
                'approvalStatus' => Constants::APPROVAL_REJECTED,
                'latestApprovalComment' => $comment,
            ];
        }
        $property->update($updateData);

        logPropertyHistory([
            'propertyId' => $property->id,
            'field' => 'status',
            'originalValue' => json_encode(['message' => $oldStatus]),
            'newValue' => json_encode(['message' => $isApproved ? 'approved' : 'rejected']),
        ]);

        PropertyApprovalHistory::create([
            'listingId' => $property->id,
            'status' => $status,
            'comment' => $comment,
            'auditBy' => user()->id,
            'auditDate' => now(),
        ]);

        if ($updateData['status'] == Constants::PROPERTY_STATUS_PUBLISHED) {
            $this->publishProperty($property);
        }

        return $property->fresh();
    }

    // ================= publishProperty =================
    public function publishProperty(Property $property): Property
    {
        $oldStatus = $property->status;

        $property->update([
            'status' => Constants::PROPERTY_STATUS_PUBLISHED,
            'approvalStatus' => Constants::APPROVAL_COMPLETED,
            'publishedBy' => user()->id,
            'publishedAt' => now(),
        ]);

        logPropertyHistory([
            'propertyId' => $property->id,
            'field' => 'status',
            'originalValue' => json_encode(['message' => $oldStatus]),
            'newValue' => json_encode(['message' => 'published']),
        ]);

        PropertyApprovalHistory::create([
            'listingId' => $property->id,
            'status' => 'published',
            'comment' => 'Listing published by <a href="'.config('app.frontend_url').(isAgent() ? '/dashboard/agents/'.user()->id.'/edit' : '/dashboard/users/'.user()->id).'"><strong>'.ucwords(user()->name).'</strong></a>.',
            'auditBy' => user()->id,
            'auditDate' => now(),
        ]);

        return $property->fresh();
    }

    public function uploadPropertyMediaFiles($propertyId, $createdBy, $files)
    {
        $maxOrdering = PropertyMediaFile::where('propertyId', $propertyId)->max('ordering') ?? 0;
        $uploaded = [];

        // Group this property's uploads under its reference number (id fallback).
        $referenceNumber = Property::where('id', $propertyId)->value('referenceNumber');
        $base = $this->fileStorage->recordPrefix('properties', $referenceNumber, $propertyId);

        foreach ($files as $index => $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }
            $originalName = trim(str_replace(' ', '', $file->getClientOriginalName()));
            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
            $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
            $storageName = $nameWithoutExt.'_'.time().'_'.$index.'.'.$extension;
            $fileUrl = $this->fileStorage->uploadPublicFile($file, $base.'/files', $storageName);

            $uploaded[] = PropertyMediaFile::create([
                'propertyId' => $propertyId,
                'fileName' => $originalName,
                'filePath' => $fileUrl,
                'ordering' => ++$maxOrdering,
                'createdBy' => $createdBy,
            ]);
        }

        return $uploaded;
    }

    public function updatePropertyMediaFile($mediaId, array $data, $file = null, $imageCompressionService = null, $videoPreviewService = null)
    {
        $mediaFile = PropertyMediaFile::find($mediaId);

        if (! $mediaFile) {
            throw new UserFacingException('Media file not found');
        }

        if ($file) {
            $originalName = trim(str_replace(' ', '', $file->getClientOriginalName()));
            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
            $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
            $uniqueName = $nameWithoutExt.'_'.time().'.'.$extension;

            // Group this property's uploads under its reference number (id fallback).
            $referenceNumber = Property::where('id', $mediaFile->propertyId)->value('referenceNumber');
            $base = $this->fileStorage->recordPrefix('properties', $referenceNumber, $mediaFile->propertyId);

            $pathsToDelete = array_filter([
                $mediaFile->originalPath,
                $mediaFile->compressedPath,
                $mediaFile->thumbnailPath,
                $mediaFile->filePath,
            ]);

            foreach ($pathsToDelete as $path) {
                $this->fileStorage->deletePublicByUrl((string) $path);
            }

            if ($mediaFile->mediaType === 'uploadPhotos' && $imageCompressionService) {
                $paths = $imageCompressionService->compressAndStore($file, $uniqueName, $base);

                $data['fileName'] = $originalName;
                $data['originalPath'] = $paths['originalPath'];
                $data['compressedPath'] = $paths['compressedPath'];
                $data['thumbnailPath'] = $paths['thumbnailPath'];
            } elseif ($mediaFile->mediaType === 'uploadVideos' && $videoPreviewService) {
                $originalUrl = $this->fileStorage->uploadPublicFile($file, $base.'/originals', $uniqueName);
                $previewName = pathinfo($uniqueName, PATHINFO_FILENAME).'.mp4';
                $compressedPath = $videoPreviewService->generatePreview($file->getPathname(), $previewName, $base);

                $data['fileName'] = $originalName;
                $data['originalPath'] = $originalUrl;
                $data['compressedPath'] = $compressedPath;
                $data['thumbnailPath'] = null;
            } else {
                $fileUrl = $this->fileStorage->uploadPublicFile($file, $base.'/files', $uniqueName);

                $data['fileName'] = $originalName;
                $data['filePath'] = $fileUrl;
                $data['originalPath'] = $fileUrl;
                $data['compressedPath'] = null;
                $data['thumbnailPath'] = null;
            }
        }

        $mediaFile->update($data);

        return $mediaFile->fresh();
    }

    public function deletePropertyMedia($mediaId)
    {
        $media = PropertyMediaFile::find($mediaId);

        if (! $media) {
            return 'Property Media not found';
        }

        $this->fileStorage->deletePublicByUrl($media->filePath);

        $media->delete();

        return 'Property Media deleted successfully';
    }

    public function getPropertyByAssignedUser($userId, Request $request)
    {
        $perPage = $request->per_page ?? 10;
        $query = Property::with('creator');
        if (isAgent()) {
            $query->where('agentId', user()->id);
        } else {
            $query->where('agentId', $userId);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('pfCommunity', 'LIKE', "%{$search}%")
                    ->orWhere('price', 'LIKE', "%{$search}%")
                    ->orWhereRaw('LOWER(status) LIKE ?', ['%'.strtolower($search).'%']);
            });
        }

        return $query->orderBy('id', 'desc')->paginate($perPage);
    }
}
