<?php

namespace App\Repositories;

use App\Constants\Constants;
use App\Models\AssignTeamMember;
use App\Models\Enquiry;
use App\Models\Property;
use App\Services\MatchingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EnquiryRepository
{
    public function __construct(
        protected MatchingService $matchingService
    ) {}

    public function getAll(Request $request)
    {
        $perPage = $request->integer('per_page', 10);

        $query = Enquiry::with(['agent:id,name', 'contact:id,name,phone,mergedIntoId', 'contact.mergedInto:id,name,phone', 'listing:id,title,referenceNumber', 'creator:id,name', 'tasks.assignedUser', 'follows.user', 'follows.enquiry', 'cityInfo', 'communityInfo', 'community2Info', 'community3Info', 'internalNotes', 'listing.salesforceIdMap']);

        if (isAgent()) {
            // Agents see all enquiries by default. Contact details of enquiries
            // that don't belong to the agent are masked in GetAllEnquiryResource.
            // `onlyMyEnquiry` still scopes the list to the agent's own enquiries.
            if ($request->boolean('onlyMyEnquiry')) {
                $query->where('agentId', user()->id);
            }
        } elseif ($request->filled('agentId')) {
            $query->where('agentId', $request->agentId);
        } elseif ($request->filled('teamId')) {
            $memberIds = AssignTeamMember::where('teamId', $request->teamId)->pluck('userId')->all();
            $query->whereIn('agentId', $memberIds ?: [0]);
        }

        if ($request->filled('users')) {
            $userIds = is_array($request->users) ? $request->users : explode(',', $request->users);
            $query->whereIn('agentId', $userIds);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'LIKE', "%{$search}%")
                    ->orWhere('referenceNo', 'LIKE', "%{$search}%")
                    ->orWhere('enquiryType', 'LIKE', "%{$search}%")
                    ->orWhereRaw('LOWER(status) LIKE ?', ['%'.strtolower($search).'%'])
                    ->orWhere('referredBy', 'LIKE', "%{$search}%")
                    ->orWhere('leadSource', 'LIKE', "%{$search}%")
                    ->orWhere('technicalSource', 'LIKE', "%{$search}%")
                    ->orWhere('comments', 'LIKE', "%{$search}%")
                    ->orWhereHas('agent', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('contact', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        if ($request->filled('priceMin') || $request->filled('minPrice')) {
            $query->whereRaw('CAST(priceMin AS DECIMAL(15,2)) >= ?', [(float) $request->input('priceMin', $request->input('minPrice'))]);
        }
        if ($request->filled('priceMax') || $request->filled('maxPrice')) {
            $query->whereRaw('CAST(priceMax AS DECIMAL(15,2)) <= ?', [(float) $request->input('priceMax', $request->input('maxPrice'))]);
        }
        if ($request->filled('status')) {
            $values = array_map('trim', explode(',', $request->status));
            count($values) > 1 ? $query->whereIn('status', $values) : $query->where('status', $values[0]);
        }
        if ($request->filled('leadScore')) {
            // leadRating is the effective score (manual + automatic), so filter on
            // it directly — this respects manual overrides that the status-derived
            // filter would miss. Kept as an alias of the leadRating filter.
            $temps = array_map(fn ($v) => strtolower(trim($v)), explode(',', $request->leadScore));
            $query->whereIn('leadRating', $temps ?: ['__none__']);
        }
        if ($request->filled('enquiryType')) {
            $values = array_map('trim', explode(',', $request->enquiryType));
            count($values) > 1 ? $query->whereIn('enquiryType', $values) : $query->where('enquiryType', $values[0]);
        }
        if ($request->filled('listing') && $request->listing !== 'all') {
            $values = array_map('trim', explode(',', $request->listing));
            count($values) > 1 ? $query->whereIn('listingType', $values) : $query->where('listingType', $values[0]);
        }
        if ($request->filled('listingType')) {
            $values = array_map('trim', explode(',', $request->listingType));
            count($values) > 1 ? $query->whereIn('listingType', $values) : $query->where('listingType', $values[0]);
        }
        if ($request->filled('propertyType')) {
            $values = array_map('trim', explode(',', $request->propertyType));
            count($values) > 1 ? $query->whereIn('enquiryPropertyType', $values) : $query->where('enquiryPropertyType', $values[0]);
        }
        if ($request->filled('leadSource')) {
            // Expand each selection to every spelling that rolls up to it, so
            // picking "Property Finder" returns the whole portal (the legacy
            // "propertyfinderAe" rows and Primary Plus included) rather than
            // only the value the caller happened to send.
            $values = array_values(array_unique(array_merge(...array_map(
                fn ($value) => Constants::leadSourceGroupMembers($value) ?: [$value],
                array_map('trim', explode(',', $request->leadSource))
            ))));
            count($values) > 1 ? $query->whereIn('leadSource', $values) : $query->where('leadSource', $values[0]);
        }
        if ($request->filled('technicalSource')) {
            // Free text now rather than a fixed list, so match on substring
            // instead of the whole stored value.
            $query->where('technicalSource', 'LIKE', '%'.trim($request->technicalSource).'%');
        }
        if ($request->filled('sizeMin')) {
            $query->where('sizeMin', '>=', $request->sizeMin);
        }
        if ($request->filled('sizeMax')) {
            $query->where('sizeMax', '<=', $request->sizeMax);
        }
        if ($request->filled('bedroomsMin')) {
            $query->where('bedroomsMin', '>=', $request->bedroomsMin);
        }
        if ($request->filled('bedroomsMax')) {
            $query->where('bedroomsMax', '<=', $request->bedroomsMax);
        }
        if ($request->filled('bedrooms')) {
            $bedrooms = (int) $request->bedrooms;
            $query->where(function ($q) use ($bedrooms) {
                $q->where('bedroomsMin', '<=', $bedrooms)
                    ->where('bedroomsMax', '>=', $bedrooms);
            });
        }
        if ($request->filled('bathroomsMin')) {
            $query->where('bathroomsMin', '>=', $request->bathroomsMin);
        }
        if ($request->filled('bathroomsMax')) {
            $query->where('bathroomsMax', '<=', $request->bathroomsMax);
        }
        if ($request->filled('community')) {
            $values = array_map('trim', explode(',', $request->community));
            $query->where(function ($q) use ($values) {
                $q->whereIn('community', $values)
                    ->orWhereIn('community_2', $values)
                    ->orWhereIn('community_3', $values);
            });
        }
        if ($request->filled('leadRating')) {
            $values = array_map('trim', explode(',', $request->leadRating));
            count($values) > 1 ? $query->whereIn('leadRating', $values) : $query->where('leadRating', $values[0]);
        }
        if ($request->filled('referredBy')) {
            $query->where('referredBy', 'LIKE', "%{$request->referredBy}%");
        }
        if ($request->filled('listingOwner')) {
            $owners = array_map('trim', explode(',', $request->listingOwner));
            $query->whereHas('listing', function ($q) use ($owners) {
                $q->whereIn('agentId', $owners);
            });
        }
        if ($request->filled('leadsQualified')) {
            $query->where('isQualified', $request->boolean('leadsQualified'));
        }
        if ($request->filled('yearBuiltMin')) {
            $query->where('yearBuiltMin', '>=', (int) $request->yearBuiltMin);
        }
        if ($request->filled('yearBuiltMax')) {
            $query->where('yearBuiltMax', '<=', (int) $request->yearBuiltMax);
        }
        if ($request->filled('floorMin')) {
            $query->where('floorMin', '>=', (int) $request->floorMin);
        }
        if ($request->filled('floorMax')) {
            $query->where('floorMax', '<=', (int) $request->floorMax);
        }
        if ($request->filled('parkingSpaceMin')) {
            $query->where('parkingSpaceMin', '>=', (int) $request->parkingSpaceMin);
        }
        if ($request->filled('parkingSpaceMax')) {
            $query->where('parkingSpaceMax', '<=', (int) $request->parkingSpaceMax);
        }
        if ($request->filled('isOpenGame')) {
            $query->where('isOpenGame', $request->isOpenGame);
        }
        // hasClosing=1 → only enquiries that converted to a won deal (a closing
        // with status open/closed). Powers the Enquiry Conversion "Deals Closed"
        // tile drill-down so the list matches the tile's count exactly.
        if ($request->boolean('hasClosing')) {
            $query->whereHas('closings', function ($q) {
                $q->whereIn('status', [Constants::CLOSE_STATUS_OPEN, Constants::CLOSE_STATUS_CLOSED]);
            });
        }
        // Enquiry Conversion funnel drill-downs. hasViewing/hasOffer mirror the
        // report's "Viewings Logged" / "Offers Submitted" stages; inProgress is
        // "worked past New Lead" — a non-new-lead status OR any recorded activity.
        if ($request->boolean('hasViewing')) {
            $query->whereHas('viewingTasks');
        }
        if ($request->boolean('hasOffer')) {
            $query->whereHas('offers');
        }
        if ($request->boolean('inProgress')) {
            $query->where(function ($q) {
                $q->where('status', '!=', Constants::ENQUIRY_NEW_LEAD)
                    ->orWhereHas('viewingTasks')
                    ->orWhereHas('offers')
                    ->orWhereHas('closings', function ($c) {
                        $c->whereIn('status', [Constants::CLOSE_STATUS_OPEN, Constants::CLOSE_STATUS_CLOSED]);
                    });
            });
        }
        // created_at is UTC; convert the selected local day to UTC boundaries.
        if ($request->filled('startDate')) {
            $query->where('created_at', '>=', localDayStartUtc($request->startDate));
        }
        if ($request->filled('endDate')) {
            $query->where('created_at', '<=', localDayEndUtc($request->endDate));
        }

        $allowedSortFields = [
            'id',
            'phone',
            'enquiryType',
            'status',
            'priceMin',
            'priceMax',
            'sizeMin',
            'sizeMax',
            'bedroomsMin',
            'bedroomsMax',
            'listingType',
            'leadSource',
            'technicalSource',
            'created_at',
        ];
        $sortBy = in_array($request->sortBy, $allowedSortFields)
            ? $request->sortBy
            : 'id';

        $sortOrder = $request->sortOrder === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($perPage);
    }

    /**
     * Default matching rule: builds the query of open enquiries whose stored criteria
     * overlap with the property's stored attributes. Callers add their own eager-loads
     * and finalize with ->get() or ->paginate(). Used by the linkedEnquiries endpoint —
     * fixed rule, no Request overrides.
     */
    public function matchActiveEnquiriesForProperty(Property $property)
    {
        $intlTypes = Constants::PROPERTY_INT_AND_OFF_LISTING_TYPES;
        $listingType = $property->listingType ?: null;

        // Mirror property-side: a 'sale' listing also matches enquiries typed as off-plan/
        // international (both are sale-side intents). Rent stays rent-only. Intl/off-plan
        // listings match only intl/off-plan enquiries. No listingType → both branches run.
        $isIntl = $listingType !== null && in_array($listingType, $intlTypes, true);

        if ($listingType === null) {
            $runIntl = true;
            $runOther = true;
            $intlSubset = $intlTypes;
            $otherSubset = [];
        } elseif ($isIntl) {
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
            $branches[] = $this->buildIntlOffplanMatchingQueryForProperty($property, $intlSubset);
        }

        if ($runOther) {
            $branches[] = $this->buildOtherListingMatchingQueryForProperty($property, $otherSubset, $intlTypes);
        }

        $query = array_shift($branches);
        foreach ($branches as $b) {
            $query->union($b);
        }

        return $query->orderBy('id', 'desc');
    }

    private function buildIntlOffplanMatchingQueryForProperty(Property $property, array $intlSubset)
    {
        $query = Enquiry::query()
            ->whereNotIn('status', [Constants::ENQUIRY_SOLD, Constants::ENQUIRY_LOST])
            ->whereIn('listingType', $intlSubset);

        // Enquiries already logged directly against this listing belong in the "Associated"
        // box; "Linked" shows only OTHER matching enquiries, so keep the two sets disjoint.
        $this->excludeAssociatedEnquiries($query, $property);

        // Always use unit-config matching for the intl branch — overlap with any of the property's
        // 6 unit configs when the property is intl, or pivot on the property's single price/size/
        // bedrooms when the property is non-intl (sale).
        if ($this->matchingService->isIntlOffplan($property->listingType ?: null)) {
            $this->matchingService->applyOffplanUnitFiltersToEnquiryQuery($query, $property);
        } else {
            $this->applyPropertyAttributeFiltersToEnquiryQuery($query, $property);
        }

        return $query;
    }

    private function buildOtherListingMatchingQueryForProperty(Property $property, array $otherSubset, array $intlTypes)
    {
        $query = Enquiry::query()
            ->whereNotIn('status', [Constants::ENQUIRY_SOLD, Constants::ENQUIRY_LOST])
            ->whereNotIn('listingType', $intlTypes);

        if (! empty($otherSubset)) {
            $query->whereIn('listingType', $otherSubset);
        }

        // drop this listing's own associated enquiries from "Linked".
        $this->excludeAssociatedEnquiries($query, $property);

        $this->applyPropertyAttributeFiltersToEnquiryQuery($query, $property);

        return $query;
    }

    private function excludeAssociatedEnquiries($query, Property $property): void
    {
        $query->where(function ($q) use ($property) {
            $q->whereNull('listingId')->orWhere('listingId', '!=', $property->id);
        });
    }

    private function applyPropertyAttributeFiltersToEnquiryQuery($query, Property $property): void
    {
        if ($property->price) {
            $this->matchingService->applyPriceRangeFilter(
                $query,
                $property->price,
                $property->price,
                false,
                $property->listingType ?: null
            );
        }

        if ($property->sizeArea !== null) {
            $size = (float) $property->sizeArea;
            $query->where(function ($q) use ($size) {
                $q->whereNull('sizeMin')->orWhereRaw('CAST(sizeMin AS DECIMAL(20,2)) <= ?', [$size]);
            })->where(function ($q) use ($size) {
                $q->whereNull('sizeMax')->orWhereRaw('CAST(sizeMax AS DECIMAL(20,2)) >= ?', [$size]);
            });
        }

        if ($property->pfCommunity) {
            $query->where(function ($q) use ($property) {
                $q->where('community', $property->pfCommunity)
                    ->orWhere('community_2', $property->pfCommunity)
                    ->orWhere('community_3', $property->pfCommunity);
            });
        }

        if ($property->bedrooms !== null) {
            $bedrooms = (int) $property->bedrooms;
            $query->where(function ($q) use ($bedrooms) {
                $q->whereNull('bedroomsMin')->orWhere('bedroomsMin', '<=', $bedrooms);
            })->where(function ($q) use ($bedrooms) {
                $q->whereNull('bedroomsMax')->orWhere('bedroomsMax', '>=', $bedrooms);
            });
        }
        if ($property->bathrooms !== null) {
            $bathrooms = (int) $property->bathrooms;
            $query->where(function ($q) use ($bathrooms) {
                $q->whereNull('bathroomsMin')->orWhere('bathroomsMin', '<=', $bathrooms);
            })->where(function ($q) use ($bathrooms) {
                $q->whereNull('bathroomsMax')->orWhere('bathroomsMax', '>=', $bathrooms);
            });
        }
    }

    public function getAllMatchingEnquiries(Request $request)
    {
        $perPage = $request->integer('per_page', 10);

        $query = Enquiry::with(['agent:id,name', 'contact:id,name,phone,email,mergedIntoId', 'contact.mergedInto:id,name,phone,email', 'listing:id,title,referenceNumber', 'creator:id,name']);

        $propertyId = $request->route('propertyId');
        $property = $propertyId ? Property::with('meta')->find($propertyId) : null;

        $isIntlOffplan = $property && $this->matchingService->isIntlOffplan($property->listingType);

        if ($propertyId) {
            $query->whereDoesntHave('linkedListings', function ($q) use ($propertyId) {
                $q->where('propertyId', $propertyId);
            });
        }

        // Open Game toggle acts as a real switch so agents can see/select either set:
        // checked  -> only Open Game enquiries (isOpenGame = 1)
        // unchecked -> only non-Open-Game enquiries (isOpenGame = 0)
        $query->where('isOpenGame', $request->boolean('isOpenGame') ? 1 : 0);

        $this->applyCommonEnquiryMatchingFilters($query, $request);

        if ($isIntlOffplan) {
            $this->applyIntlOffplanEnquiryMatchingFilters($query, $property);
        } else {
            $this->applyStandardEnquiryMatchingFilters($query, $request);
        }

        $query->orderBy('id', 'desc');

        $rawSql = vsprintf(
            str_replace('?', "'%s'", $query->toSql()),
            array_map(fn ($b) => is_bool($b) ? (int) $b : $b, $query->getBindings())
        );
        Log::info("getAllMatchingEnquiries SQL:\n".$rawSql);

        return $query->paginate($perPage);
    }

    private function applyCommonEnquiryMatchingFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'LIKE', "%{$search}%")
                    ->orWhere('enquiryType', 'LIKE', "%{$search}%")
                    ->orWhereRaw('LOWER(status) LIKE ?', ['%'.strtolower($search).'%'])
                    ->orWhere('referenceNo', 'LIKE', "%{$search}%")
                    ->orWhereHas('agent', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('contact', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        if ($request->filled('listingType')) {
            $values = array_map('trim', explode(',', $request->listingType));
            // 'sale' in the request implicitly includes off-plan + international enquiries
            // (they're sale-side projects). Rent stays rent-only.
            if (in_array(Constants::PROPERTY_PURPOSE_SALE, $values, true)) {
                $values = array_values(array_unique(array_merge($values, Constants::PROPERTY_INT_AND_OFF_LISTING_TYPES)));
            }
            count($values) > 1 ? $query->whereIn('listingType', $values) : $query->where('listingType', $values[0]);
        }

        if ($request->filled('status')) {
            $values = array_map('trim', explode(',', $request->status));
            count($values) > 1 ? $query->whereIn('status', $values) : $query->where('status', $values[0]);
        } else {
            $query->whereNotIn('status', [Constants::ENQUIRY_SOLD, Constants::ENQUIRY_LOST]);
        }

        if ($request->filled('enquiryType')) {
            $values = array_map('trim', explode(',', $request->enquiryType));
            count($values) > 1 ? $query->whereIn('enquiryType', $values) : $query->where('enquiryType', $values[0]);
        }
        if ($request->filled('enquiryPropertyType')) {
            $values = array_map('trim', explode(',', $request->enquiryPropertyType));
            count($values) > 1 ? $query->whereIn('enquiryPropertyType', $values) : $query->where('enquiryPropertyType', $values[0]);
        }
        if ($request->filled('enquiryPropertySubType')) {
            $values = array_map('trim', explode(',', $request->enquiryPropertySubType));
            count($values) > 1 ? $query->whereIn('enquiryPropertySubType', $values) : $query->where('enquiryPropertySubType', $values[0]);
        }
        if ($request->filled('leadSource')) {
            // Expand each selection to every spelling that rolls up to it, so
            // picking "Property Finder" returns the whole portal (the legacy
            // "propertyfinderAe" rows and Primary Plus included) rather than
            // only the value the caller happened to send.
            $values = array_values(array_unique(array_merge(...array_map(
                fn ($value) => Constants::leadSourceGroupMembers($value) ?: [$value],
                array_map('trim', explode(',', $request->leadSource))
            ))));
            count($values) > 1 ? $query->whereIn('leadSource', $values) : $query->where('leadSource', $values[0]);
        }
        if ($request->filled('technicalSource')) {
            // Free text now rather than a fixed list, so match on substring
            // instead of the whole stored value.
            $query->where('technicalSource', 'LIKE', '%'.trim($request->technicalSource).'%');
        }

        // Location match: enquiries only store location across their three community

        $locationValues = [];
        foreach (['building', 'subCommunity', 'community'] as $key) {
            if ($request->filled($key)) {
                $locationValues = array_values(array_filter(
                    array_map('trim', explode(',', (string) $request->input($key))),
                    fn ($v) => $v !== ''
                ));
                if (! empty($locationValues)) {
                    break; // finer level wins; don't also apply the broader ones
                }
            }
        }

        if (! empty($locationValues)) {
            $query->where(function ($q) use ($locationValues) {
                $q->whereIn('community', $locationValues)
                    ->orWhereIn('community_2', $locationValues)
                    ->orWhereIn('community_3', $locationValues);
            });
        }
    }

    /**
     * Off-plan / International property: match enquiries on community (property's pfCommunity
     * must be in any of the enquiry's 3 community fields) plus bedrooms / size / price overlapping
     * any of the property's 6 unit-config slots. Bathrooms / year / parking don't apply at the
     * project level and are skipped.
     */
    private function applyIntlOffplanEnquiryMatchingFilters($query, Property $property): void
    {
        if ($property->pfCommunity) {
            $query->where(function ($q) use ($property) {
                $q->where('community', $property->pfCommunity)
                    ->orWhere('community_2', $property->pfCommunity)
                    ->orWhere('community_3', $property->pfCommunity);
            });
        }

        $this->matchingService->applyOffplanUnitFiltersToEnquiryQuery($query, $property);
    }

    /**
     * Sale / Rent property: enquiry range columns (price/size/bedrooms/bathrooms) come from the
     * request and filter the enquiry rows directly.
     */
    private function applyStandardEnquiryMatchingFilters($query, Request $request): void
    {
        $isExactMatch = filter_var($request->input('isExactMatch'), FILTER_VALIDATE_BOOLEAN);

        // Variance pivot: pass exactly one non-intl listingType through; null OR's rent + sale rules
        $listingTypes = $request->filled('listingType')
            ? array_values(array_filter(array_map('trim', explode(',', $request->listingType))))
            : [];
        $intlTypes = Constants::PROPERTY_INT_AND_OFF_LISTING_TYPES;
        $otherSubset = array_values(array_diff($listingTypes, $intlTypes));
        $variancePivot = count($otherSubset) === 1 ? $otherSubset[0] : null;

        if ($request->filled('priceMin') && $request->filled('priceMax')) {
            $this->matchingService->applyPriceRangeFilter(
                $query,
                $request->input('priceMin'),
                $request->input('priceMax'),
                $isExactMatch,
                $variancePivot
            );
        }

        if ($request->filled('price')) {
            $requestPrice = $request->input('price');
            $this->matchingService->applyPriceRangeFilter($query, $requestPrice, $requestPrice, $isExactMatch, $variancePivot);
        }

        // Match on OVERLAP, not containment, and treat a NULL bound as "no preference" so it never
        // excludes an enquiry — mirrors the Linked-Enquiries logic (applyPropertyAttributeFilters).
        // An enquiry's desired [min,max] range overlaps the searched [min,max] when its max is >=
        // the searched min and its min is <= the searched max.
        if ($request->filled('sizeMin')) {
            $min = (float) $request->sizeMin;
            $query->where(fn ($q) => $q->whereNull('sizeMax')->orWhereRaw('CAST(sizeMax AS DECIMAL(20,2)) >= ?', [$min]));
        }
        if ($request->filled('sizeMax')) {
            $max = (float) $request->sizeMax;
            $query->where(fn ($q) => $q->whereNull('sizeMin')->orWhereRaw('CAST(sizeMin AS DECIMAL(20,2)) <= ?', [$max]));
        }

        if ($request->filled('bedroomsMin')) {
            $min = (int) $request->bedroomsMin;
            $query->where(fn ($q) => $q->whereNull('bedroomsMax')->orWhere('bedroomsMax', '>=', $min));
        }
        if ($request->filled('bedroomsMax')) {
            $max = (int) $request->bedroomsMax;
            $query->where(fn ($q) => $q->whereNull('bedroomsMin')->orWhere('bedroomsMin', '<=', $max));
        }

        if ($request->filled('bathroomsMin')) {
            $min = (int) $request->bathroomsMin;
            $query->where(fn ($q) => $q->whereNull('bathroomsMax')->orWhere('bathroomsMax', '>=', $min));
        }
        if ($request->filled('bathroomsMax')) {
            $max = (int) $request->bathroomsMax;
            $query->where(fn ($q) => $q->whereNull('bathroomsMin')->orWhere('bathroomsMin', '<=', $max));
        }
    }

    public function findById($id)
    {
        $query = Enquiry::with(['agent:id,name', 'contact:id,name,phone,email,mergedIntoId', 'contact.mergedInto:id,name,phone,email', 'listing:id,title,referenceNumber', 'creator:id,name', 'listing.salesforceIdMap']);

        // If not super admin, filter by created by user
        if (isAgent()) {
            $query->where('agentId', user()->id);
        }

        return $query->find($id);
    }

    public function create(array $data)
    {
        return Enquiry::create($data);
    }

    public function update(Enquiry $enquiry, array $data)
    {
        $enquiry->update($data);

        return $enquiry->fresh();
    }

    public function delete(Enquiry $enquiry)
    {
        return $enquiry->delete();
    }

    public function createHistory($enquiry, array $newData)
    {
        $originalValues = [];
        $newValues = [];

        foreach ($newData as $field => $newValue) {
            if (! array_key_exists($field, $enquiry->getAttributes())) {
                continue;
            }
            $oldValue = $enquiry->$field;
            if ($oldValue != $newValue) {
                $originalValues[$field] = is_array($oldValue) ? $oldValue : (string) $oldValue;
                $newValues[$field] = is_array($newValue) ? $newValue : (string) $newValue;
            }
        }

        if (! empty($newValues)) {
            logEnquiryHistory([
                'enquiryId' => $enquiry->id,
                'field' => 'updated',
                'originalValue' => $originalValues,
                'newValue' => $newValues,
            ]);
        }
    }

    public function getByAssignedUser($userId, Request $request)
    {
        $perPage = $request->per_page ?? 10;
        $query = Enquiry::with('agent');
        if (isAgent()) {
            $query->where('agentId', user()->id);
        } else {
            $query->where('agentId', $userId);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('referenceNo', 'LIKE', "%{$search}%")
                    ->orWhereRaw('LOWER(status) LIKE ?', ['%'.strtolower($search).'%'])
                    ->orWhere('leadSource', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%")
                    ->orWhere('listingType', 'LIKE', "%{$search}%");
            });
        }

        return $query->orderBy('id', 'desc')->paginate($perPage);
    }
}
