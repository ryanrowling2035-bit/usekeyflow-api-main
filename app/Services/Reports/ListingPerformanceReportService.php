<?php

namespace App\Services\Reports;

use App\Constants\Constants;
use App\Models\AssignTeamMember;
use App\Models\Closing;
use App\Models\Contact;
use App\Models\Enquiry;
use App\Models\Offer;
use App\Models\Property;
use App\Models\PropertyCommunity;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Powers the "Listing Performance Report" (GET /reports/listing-performance).
 *
 * One row per property listing: the interest it attracted (enquiries, viewings,
 * offers) and the price it achieved. Raw columns only — the front-end derives
 * every KPI tile, both donuts, both ranking charts and the "Final Price vs
 * Listing (%)" column from these rows. Results are paginated server-side.
 *
 * Interest counts are attributed by the property's id: enquiries and offers via
 * their `listingId`, viewings via tasks (module = property). `finalPrice` is the
 * agreed price — the closing's saleRentalPrice for Sold listings, the accepted
 * offer amount for Under Offer listings, and null otherwise.
 *
 * The four report statuses map onto the internal property statuses:
 *   Sold ← rentSold · Under Offer ← underOffer · Withdrawn ← unpublished ·
 *   Active ← everything else (published, upcoming, pending, draft, ...).
 */
class ListingPerformanceReportService
{
    private const CURRENCY = 'AED';

    /** Closing statuses that count as a won deal (for revenue attribution). */
    private const WON_CLOSING_STATUSES = [Constants::CLOSE_STATUS_OPEN, Constants::CLOSE_STATUS_CLOSED];

    /** Task type for a property viewing — matches the dashboard's viewing metric. */
    private const VIEWING_TASK_TYPES = ['viewing'];

    /**
     * Sortable columns backed directly by a DB column — ordered in SQL before
     * pagination (fast path). Every other column (agent, community, propertyType,
     * daysOnMarket and the computed enquiries/viewings/offers/finalPrice) is
     * sorted in PHP over the full filtered set.
     */
    private const DB_SORTS = [
        'reference' => 'referenceNumber',
        'status' => 'status',
        'listingType' => 'listingType',
        'beds' => 'bedrooms',
        'listingPrice' => 'price',
    ];

    /** Columns sorted in PHP after the per-listing metrics are computed. */
    private const COMPUTED_SORTS = [
        'agent', 'community', 'propertyType', 'daysOnMarket',
        'enquiriesReceived', 'viewings', 'offersReceived', 'finalPrice',
    ];

    private const STATUS_SOLD = 'Sold';

    private const STATUS_UNDER_OFFER = 'Under Offer';

    private const STATUS_WITHDRAWN = 'Withdrawn';

    private const STATUS_ACTIVE = 'Active';

    /** listingType internal value => report display label. */
    private const LISTING_TYPE_LABELS = [
        'sale' => 'Sale',
        'rent' => 'Rent',
        'offPlan' => 'Off-Plan',
        'vacationHomes' => 'Vacation Homes',
        'International' => 'International',
    ];

    /**
     * The paginated report payload.
     *
     * @return array<string, mixed>
     */
    public function report(Request $request): array
    {
        $filters = $this->resolveFilters($request);
        $query = $this->listingQuery($filters);

        $total = (clone $query)->count();
        $listings = $this->sortedPage($query, $filters);

        return [
            'currency' => self::CURRENCY,
            'period' => ['startDate' => $filters['startDate'], 'endDate' => $filters['endDate']],
            'summary' => $this->summary($filters),
            'nationalities' => $this->nationalities($filters),
            'communityPerformance' => $this->communityPerformance($filters),
            'listings' => $listings,
            'pagination' => [
                'page' => $filters['page'],
                'perPage' => $filters['perPage'],
                'total' => $total,
            ],
        ];
    }

    /**
     * The current page of report rows, honouring the requested sort.
     *
     * DB-backed columns are ordered in SQL and only the page is fetched + enriched
     * (fast path). Computed / relation columns need every row's metrics to sort
     * correctly, so the full filtered set is built, sorted in PHP, then sliced.
     * With no sort requested the default is newest listing first.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    protected function sortedPage(Builder $query, array $filters): array
    {
        $sortBy = $filters['sortBy'];
        $dir = $filters['sortDir'];

        if ($sortBy !== null && isset(self::DB_SORTS[$sortBy])) {
            $properties = $query->orderBy(self::DB_SORTS[$sortBy], $dir)
                ->forPage($filters['page'], $filters['perPage'])
                ->get($this->columns());

            return $this->rowsFor($properties);
        }

        if ($sortBy !== null && in_array($sortBy, self::COMPUTED_SORTS, true)) {
            $rows = $this->rowsFor($query->orderByDesc('created_at')->get($this->columns()));
            $sorted = $this->sortRows($rows, $sortBy, $dir);

            return array_slice($sorted, ($filters['page'] - 1) * $filters['perPage'], $filters['perPage']);
        }

        // No (or unknown) sort: newest listing first, page only.
        $properties = $query->orderByDesc('created_at')
            ->forPage($filters['page'], $filters['perPage'])
            ->get($this->columns());

        return $this->rowsFor($properties);
    }

    /**
     * Sort already-built report rows by a computed/relation column. Nulls always
     * sort last (in both directions); strings compare case-insensitively.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function sortRows(array $rows, string $sortBy, string $dir): array
    {
        $factor = $dir === 'asc' ? 1 : -1;

        usort($rows, function ($a, $b) use ($sortBy, $factor) {
            $x = $a[$sortBy] ?? null;
            $y = $b[$sortBy] ?? null;

            // Nulls last, regardless of direction.
            if ($x === null || $y === null) {
                return $x === $y ? 0 : ($x === null ? 1 : -1);
            }

            if (is_string($x) || is_string($y)) {
                return strcasecmp((string) $x, (string) $y) * $factor;
            }

            return ($x <=> $y) * $factor;
        });

        return $rows;
    }

    /**
     * Donut: listings by nationality — the buyer's nationality for Sold listings,
     * otherwise the physical owner's — with count + total listing value.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, count: int, revenue: float}>
     */
    protected function nationalities(array $filters): array
    {
        $properties = $this->baseQuery($filters)->get(['id', 'status', 'physicalOwnerId', 'price']);

        $buyerNationality = Closing::whereIn('listingId', $properties->pluck('id'))
            ->whereIn('status', self::WON_CLOSING_STATUSES)
            ->get(['listingId', 'clientNationality'])
            ->keyBy('listingId');
        $ownerNationality = Contact::whereIn('id', $properties->pluck('physicalOwnerId')->filter()->unique())
            ->pluck('nationality', 'id');

        $map = [];
        foreach ($properties as $property) {
            $isSold = $property->status === Constants::PROPERTY_STATUS_RENTED_SOLD;
            $nationality = $isSold && ($buyerNationality[$property->id]->clientNationality ?? '') !== ''
                ? $buyerNationality[$property->id]->clientNationality
                : ($ownerNationality[$property->physicalOwnerId] ?? '');

            $key = trim((string) $nationality) ?: 'Unknown';
            $map[$key] ??= ['count' => 0, 'revenue' => 0.0];
            $map[$key]['count']++;
            $map[$key]['revenue'] += (float) $property->price;
        }

        return $this->listingDistribution($map);
    }

    /**
     * Bars: listings by community, with count + total listing value.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, count: int, revenue: float}>
     */
    protected function communityPerformance(array $filters): array
    {
        $properties = $this->baseQuery($filters)->get(['id', 'pfCommunity', 'price']);

        $names = PropertyCommunity::whereIn('communityId', $properties->pluck('pfCommunity')->filter()->unique())
            ->pluck('name', 'communityId');

        $map = [];
        foreach ($properties as $property) {
            $key = $property->pfCommunity ? ($names[$property->pfCommunity] ?? 'Unknown') : 'Unknown';
            $map[$key] ??= ['count' => 0, 'revenue' => 0.0];
            $map[$key]['count']++;
            $map[$key]['revenue'] += (float) $property->price;
        }

        return $this->listingDistribution($map);
    }

    /**
     * Top 5 slices (by listing count) + an aggregated "Others", each as
     * {label, count, revenue} — revenue is the total listing value (sum of price).
     * "Unknown" and "Others" are always pushed to the end. Percentages are derived
     * on the front-end from count.
     *
     * @param  array<string, array{count: int, revenue: float}>  $map
     * @return array<int, array{label: string, count: int, revenue: float}>
     */
    protected function listingDistribution(array $map): array
    {
        $unknown = $map['Unknown'] ?? null;
        unset($map['Unknown']);

        uasort($map, fn ($a, $b) => $b['count'] <=> $a['count']);

        $result = [];
        $others = ['count' => 0, 'revenue' => 0.0];
        $index = 0;

        foreach ($map as $key => $data) {
            if ($index < 5) {
                $result[] = ['label' => (string) $key, 'count' => (int) $data['count'], 'revenue' => round($data['revenue'], 2)];
            } else {
                $others['count'] += $data['count'];
                $others['revenue'] += $data['revenue'];
            }
            $index++;
        }

        if ($unknown) {
            $result[] = ['label' => 'Unknown', 'count' => (int) $unknown['count'], 'revenue' => round($unknown['revenue'], 2)];
        }
        if ($others['count'] > 0) {
            $result[] = ['label' => 'Others', 'count' => $others['count'], 'revenue' => round($others['revenue'], 2)];
        }

        return $result;
    }

    /**
     * KPI-tile aggregates over the ENTIRE filtered set (not just the current
     * page), so the tiles stay correct regardless of pagination.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int|float>
     */
    protected function summary(array $filters): array
    {
        $properties = $this->baseQuery($filters)->get(['id', 'price', 'status']);

        $soldIds = $properties->where('status', Constants::PROPERTY_STATUS_RENTED_SOLD)->pluck('id')->all();
        $underOfferIds = $properties->where('status', Constants::PROPERTY_STATUS_UNDER_OFFER)->pluck('id')->all();

        // Agreed prices for the sell-ratio: closing amount (Sold) / accepted offer (Under Offer).
        $soldPrice = $soldIds
            ? $this->keyed(Closing::whereIn('listingId', $soldIds)->whereNotNull('saleRentalPrice'), 'listingId', 'saleRentalPrice')
            : [];
        $offerPrice = $underOfferIds
            ? $this->keyed(Offer::whereIn('listingId', $underOfferIds)->where('status', Constants::OFFER_STATUS_ACCEPTED), 'listingId', 'offeredAmount')
            : [];

        $ratios = [];
        foreach ($properties as $p) {
            $listingPrice = (float) $p->price;
            if ($listingPrice <= 0) {
                continue;
            }

            $finalPrice = match ($p->status) {
                Constants::PROPERTY_STATUS_RENTED_SOLD => isset($soldPrice[$p->id]) ? (float) $soldPrice[$p->id] : null,
                Constants::PROPERTY_STATUS_UNDER_OFFER => isset($offerPrice[$p->id]) ? (float) $offerPrice[$p->id] : null,
                default => null,
            };

            if ($finalPrice !== null) {
                $ratios[] = $finalPrice / $listingPrice * 100;
            }
        }

        return [
            'totalListings' => $properties->count(),
            // Enquiries and offers are counted by the report's own filters (date /
            // agent / listing type) rather than the property set, so they match
            // their lists and do not silently drop rows whose listing was removed
            // or is unlinked.
            'totalEnquiries' => $this->enquiriesCount($filters),
            'offersReceived' => $this->offersCount($filters),
            'dealsClosed' => count($soldIds),
            'totalListingValue' => round((float) $properties->sum('price'), 2),
            'avgSellRatio' => $ratios ? round(array_sum($ratios) / count($ratios), 1) : 0.0,
        ];
    }

    /**
     * Offers received in scope — filtered on the offer itself (created_at, agent,
     * listing type), independent of the paginated property set.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function offersCount(array $filters): int
    {
        return Offer::query()
            ->when($filters['agentIds'] !== null, fn ($q) => $q->whereIn('assignedAgentId', $filters['agentIds']))
            ->when(! empty($filters['startDate']), fn ($q) => $q->where('created_at', '>=', localDayStartUtc($filters['startDate'])))
            ->when(! empty($filters['endDate']), fn ($q) => $q->where('created_at', '<=', localDayEndUtc($filters['endDate'])))
            ->when($filters['listingType'] !== null, fn ($q) => $q->where('listingType', $filters['listingType']))
            ->count();
    }

    /**
     * Enquiries received in scope — filtered on the enquiry itself (created_at,
     * agent, listing type), independent of the paginated property set.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function enquiriesCount(array $filters): int
    {
        return Enquiry::query()
            ->when($filters['agentIds'] !== null, fn ($q) => $q->whereIn('agentId', $filters['agentIds']))
            ->when(! empty($filters['startDate']), fn ($q) => $q->where('created_at', '>=', localDayStartUtc($filters['startDate'])))
            ->when(! empty($filters['endDate']), fn ($q) => $q->where('created_at', '<=', localDayEndUtc($filters['endDate'])))
            ->when($filters['listingType'] !== null, fn ($q) => $q->where('listingType', $filters['listingType']))
            ->count();
    }

    /**
     * All filtered listings (no pagination) for the CSV export.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(Request $request): array
    {
        $properties = $this->listingQuery($this->resolveFilters($request))
            ->orderByDesc('created_at')
            ->get($this->columns());

        return array_map(fn ($row) => [
            'reference' => $row['reference'],
            'agent' => $row['agent'] ?? '—',
            'status' => $row['status'],
            'community' => $row['community'] ?? '—',
            'propertyType' => $row['propertyType'] ?? '—',
            'listingType' => $row['listingType'] ?? '—',
            'beds' => $row['beds'] === 0 ? 'Studio' : $row['beds'],
            'daysOnMarket' => $row['daysOnMarket'],
            'enquiriesReceived' => $row['enquiriesReceived'],
            'viewings' => $row['viewings'],
            'offersReceived' => $row['offersReceived'],
            'listingPrice' => $row['listingPrice'],
            'finalPrice' => $row['finalPrice'] ?? '—',
            'finalVsListing' => $row['finalPrice'] !== null && $row['listingPrice'] > 0
                ? round($row['finalPrice'] / $row['listingPrice'] * 100, 1).'%'
                : '—',
        ], $this->rowsFor($properties));
    }

    // ---------------------------------------------------------------------
    // Row building
    // ---------------------------------------------------------------------

    /**
     * Enrich a page of properties into report rows. Interest counts and agreed
     * prices are fetched with one grouped query each (no N+1 across rows).
     *
     * @param  Collection<int, Property>  $properties
     * @return array<int, array<string, mixed>>
     */
    protected function rowsFor(Collection $properties): array
    {
        if ($properties->isEmpty()) {
            return [];
        }

        $ids = $properties->pluck('id')->all();

        // Enquiries reach a listing two ways — a direct enquiries.listingId, and
        // the enquiry_linked_listings pivot (an enquiry matched to several
        // listings). Count both so the tally isn't understated.
        $enquiries = $this->enquiriesPerListing($ids);
        $offers = $this->groupedCount(Offer::whereIn('listingId', $ids), 'listingId');
        // Viewing tasks logged against the listing (module = property). "viewing"
        // is the same task type the dashboard's viewing metric uses.
        $viewings = $this->groupedCount(
            Task::where('module', Constants::MODULE_PROPERTY)
                ->whereIn('type', self::VIEWING_TASK_TYPES)
                ->whereIn('moduleId', $ids),
            'moduleId'
        );
        // Agreed prices: closing amount for Sold, accepted-offer amount for Under Offer.
        $soldPrice = $this->keyed(Closing::whereIn('listingId', $ids)->whereNotNull('saleRentalPrice'), 'listingId', 'saleRentalPrice');
        $offerPrice = $this->keyed(
            Offer::whereIn('listingId', $ids)->where('status', Constants::OFFER_STATUS_ACCEPTED),
            'listingId',
            'offeredAmount'
        );

        $now = Carbon::now();

        return $properties->map(function (Property $p) use ($enquiries, $offers, $viewings, $soldPrice, $offerPrice, $now) {
            $status = $this->statusLabel($p->status);

            $finalPrice = match ($status) {
                self::STATUS_SOLD => isset($soldPrice[$p->id]) ? (float) $soldPrice[$p->id] : null,
                self::STATUS_UNDER_OFFER => isset($offerPrice[$p->id]) ? (float) $offerPrice[$p->id] : null,
                default => null,
            };

            // On-market since publish (or creation), until it left the market.
            $start = Carbon::parse($p->publishedAt ?? $p->created_at);
            $end = in_array($status, [self::STATUS_SOLD, self::STATUS_WITHDRAWN], true)
                ? Carbon::parse($p->updated_at)
                : $now;

            return [
                'reference' => $p->referenceNumber,
                'agentId' => $p->agentId,
                'agent' => $p->agent->name ?? null,
                'status' => $status,
                'community' => $p->community->name ?? null,
                'propertyType' => $p->pfPropertyType ? Str::headline($p->pfPropertyType) : null,
                'listingType' => $this->listingTypeLabel($p->listingType),
                'beds' => (int) $p->bedrooms,
                'daysOnMarket' => (int) $start->diffInDays($end),
                'enquiriesReceived' => (int) ($enquiries[$p->id] ?? 0),
                'viewings' => (int) ($viewings[$p->id] ?? 0),
                'offersReceived' => (int) ($offers[$p->id] ?? 0),
                'listingPrice' => (float) $p->price,
                'finalPrice' => $finalPrice,
            ];
        })->all();
    }

    // ---------------------------------------------------------------------
    // Query building
    // ---------------------------------------------------------------------

    /**
     * The filtered listings query (no eager loads) — shared by the table and the
     * summary aggregates.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(array $filters): Builder
    {
        return Property::query()
            ->when($filters['agentIds'] !== null, fn ($q) => $q->whereIn('agentId', $filters['agentIds']))
            ->when(! empty($filters['startDate']), fn ($q) => $q->where('created_at', '>=', localDayStartUtc($filters['startDate'])))
            ->when(! empty($filters['endDate']), fn ($q) => $q->where('created_at', '<=', localDayEndUtc($filters['endDate'])))
            ->when($filters['listingType'] !== null, fn ($q) => $q->where('listingType', $filters['listingType']))
            ->when($filters['propertyType'] !== null, fn ($q) => $q->where('pfPropertyType', $filters['propertyType']))
            ->when(! empty($filters['city']), fn ($q) => $this->applyLocationFilter($q, 'city', 'locationId', $filters['city']))
            ->when(! empty($filters['community']), fn ($q) => $this->applyLocationFilter($q, 'community', 'communityId', $filters['community']))
            ->when($filters['status'] !== null, fn ($q) => $this->applyStatusFilter($q, $filters['status']));
    }

    /**
     * Filter on a location relation (city / community) by id OR name, accepting a
     * comma-separated list — the same contract the Properties list uses, so a
     * report tile can link straight through.
     */
    protected function applyLocationFilter(Builder $query, string $relation, string $idColumn, string $value): void
    {
        $values = array_filter(array_map('trim', explode(',', $value)));

        $query->whereHas($relation, function ($q) use ($values, $idColumn) {
            $column = collect($values)->every(fn ($v) => is_numeric($v)) ? $idColumn : 'name';
            count($values) > 1 ? $q->whereIn($column, $values) : $q->where($column, $values[0]);
        });
    }

    /**
     * The table query — base filters plus the agent/community eager loads.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function listingQuery(array $filters): Builder
    {
        return $this->baseQuery($filters)->with(['agent:id,name', 'community']);
    }

    protected function applyStatusFilter(Builder $query, string $status): void
    {
        match ($status) {
            self::STATUS_SOLD => $query->where('status', Constants::PROPERTY_STATUS_RENTED_SOLD),
            self::STATUS_UNDER_OFFER => $query->where('status', Constants::PROPERTY_STATUS_UNDER_OFFER),
            self::STATUS_WITHDRAWN => $query->where('status', Constants::PROPERTY_STATUS_UNPUBLISHED),
            default => $query->whereNotIn('status', [
                Constants::PROPERTY_STATUS_RENTED_SOLD,
                Constants::PROPERTY_STATUS_UNDER_OFFER,
                Constants::PROPERTY_STATUS_UNPUBLISHED,
            ]),
        };
    }

    /**
     * @return array<int, string>
     */
    protected function columns(): array
    {
        return [
            'id', 'referenceNumber', 'agentId', 'status', 'pfCommunity', 'pfPropertyType',
            'listingType', 'bedrooms', 'price', 'publishedAt', 'created_at', 'updated_at',
        ];
    }

    // ---------------------------------------------------------------------
    // Labels & helpers
    // ---------------------------------------------------------------------

    protected function statusLabel(?string $status): string
    {
        return match ($status) {
            Constants::PROPERTY_STATUS_RENTED_SOLD => self::STATUS_SOLD,
            Constants::PROPERTY_STATUS_UNDER_OFFER => self::STATUS_UNDER_OFFER,
            Constants::PROPERTY_STATUS_UNPUBLISHED => self::STATUS_WITHDRAWN,
            default => self::STATUS_ACTIVE,
        };
    }

    protected function listingTypeLabel(?string $listingType): ?string
    {
        if ($listingType === null) {
            return null;
        }

        return self::LISTING_TYPE_LABELS[$listingType] ?? Str::headline($listingType);
    }

    /**
     * Enquiries per listing, counting BOTH the direct enquiries.listingId and the
     * enquiry_linked_listings pivot, de-duplicated so an enquiry linked to a
     * listing both ways is counted once.
     *
     * @param  array<int, int>  $propertyIds
     * @return array<int, int>
     */
    protected function enquiriesPerListing(array $propertyIds): array
    {
        if (empty($propertyIds)) {
            return [];
        }

        // [propertyId => set of enquiryIds] so a duplicate link never double-counts.
        // NB: use foreach, not ->each(fn ...) — an arrow function captures
        // $byListing by value, so mutations inside it are lost and every listing
        // ends up with 0 enquiries.
        $byListing = [];

        foreach (Enquiry::whereIn('listingId', $propertyIds)->get(['id', 'listingId']) as $e) {
            $byListing[$e->listingId][$e->id] = true;
        }

        foreach (DB::table('enquiry_linked_listings')->whereIn('propertyId', $propertyIds)->get(['enquiryId', 'propertyId']) as $row) {
            $byListing[$row->propertyId][$row->enquiryId] = true;
        }

        return array_map('count', $byListing);
    }

    /**
     * COUNT(*) grouped by $column => [ key => count ].
     *
     * @return array<int, int>
     */
    protected function groupedCount(Builder $query, string $column): array
    {
        return $query->groupBy($column)
            ->selectRaw("$column as _key, COUNT(*) as _value")
            ->pluck('_value', '_key')
            ->mapWithKeys(fn ($value, $key) => [(int) $key => (int) $value])
            ->all();
    }

    /**
     * First $valueColumn per $keyColumn => [ key => value ].
     *
     * @return array<int, mixed>
     */
    protected function keyed(Builder $query, string $keyColumn, string $valueColumn): array
    {
        return $query->pluck($valueColumn, $keyColumn)
            ->mapWithKeys(fn ($value, $key) => [(int) $key => $value])
            ->all();
    }

    // ---------------------------------------------------------------------
    // Filters
    // ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function resolveFilters(Request $request): array
    {
        return [
            'startDate' => $request->query('startDate'),
            'endDate' => $request->query('endDate'),
            'listingType' => $this->internalListingType($request->query('listingType')),
            'status' => $request->query('status'),
            'city' => $request->query('city'),
            'community' => $request->query('community'),
            'propertyType' => $this->internalPropertyType($request->query('propertyType')),
            'sortBy' => $request->query('sortBy'),
            'sortDir' => strtolower((string) $request->query('sortDir')) === 'asc' ? 'asc' : 'desc',
            'agentIds' => $this->resolveAgentScope($request),
            'page' => max(1, (int) $request->query('page', 1)),
            // The frontend tables send snake_case `per_page` (app-wide convention);
            // `perPage` is still accepted so existing callers keep working.
            'perPage' => min(100, max(1, (int) ($request->query('perPage') ?? $request->query('per_page') ?? 10))),
        ];
    }

    /**
     * Map the display listingType ("Sale" / "Off-Plan") back to its stored value.
     */
    protected function internalListingType(?string $listingType): ?string
    {
        if (empty($listingType)) {
            return null;
        }

        $key = array_search($listingType, self::LISTING_TYPE_LABELS, true);

        return $key !== false ? $key : lcfirst(str_replace([' ', '-'], '', $listingType));
    }

    /**
     * Map the display propertyType ("Apartment") back to its stored value ("apartment").
     */
    protected function internalPropertyType(?string $propertyType): ?string
    {
        if (empty($propertyType)) {
            return null;
        }

        return lcfirst(str_replace(' ', '', $propertyType));
    }

    /**
     * @return array<int, int>|null null = all agents
     */
    protected function resolveAgentScope(Request $request): ?array
    {
        if (isAgent()) {
            return [(int) user()->id];
        }
        if ($request->filled('agentId')) {
            return [(int) $request->query('agentId')];
        }
        if ($request->filled('teamId')) {
            return AssignTeamMember::where('teamId', (int) $request->query('teamId'))
                ->pluck('userId')->map(fn ($id) => (int) $id)->unique()->values()->all();
        }

        return null;
    }
}
