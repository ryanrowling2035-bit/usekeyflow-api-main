<?php

namespace App\Services\Reports;

use App\Constants\Constants;
use App\Models\AssignTeamMember;
use App\Models\Enquiry;
use App\Models\EnquiryHistory;
use App\Models\Property;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Powers the "Pipeline Health Report" (GET /reports/pipeline-health).
 *
 * One row per open deal (an enquiry): the stage it sits in, how long it has been
 * there, the deal value and the commission-weighted pipeline value. Unlike the
 * mockup, the stages are the enquiry statuses the system actually uses (sale and
 * rent combined into one list).
 *
 * Weighted pipeline value (per the client's rule):
 *   dealValue × commissionRate(enquiryType) × stageProbability(status)
 *
 *   - Deal value: the linked listing's price when the enquiry is linked (rent
 *     listings annualised to a yearly figure); otherwise the midpoint of the
 *     budget range (priceMin..priceMax); otherwise the single budget value.
 *   - Commission rate: Off-Plan Investor 4%, Tenant 5%, everything else
 *     (Secondary) 2%.
 *   - Stage probability: per {@see STAGES} — New Lead 5%, Contacting 10%,
 *     Property Search 30%, Thinking About It 50%, Under Offer 70%, Sale
 *     Progressing 90%, Sold/Rented 100%, Inactive 0% (all client-confirmed).
 *
 * `daysInStage` is whole days since the last status change (from the enquiry
 * history), falling back to the enquiry's updated_at.
 */
class PipelineHealthReportService
{
    private const CURRENCY = 'AED';

    private const STAGNANT_DAYS = 7;

    /**
     * Enquiry status => [label, probability%] — all client-confirmed. Rented
     * mirrors Sold (the rent-side close) at 100%.
     */
    private const STAGES = [
        Constants::ENQUIRY_NEW_LEAD => ['label' => 'New Lead', 'probability' => 5],
        Constants::ENQUIRY_CONTACTING => ['label' => 'Contacting', 'probability' => 10],
        Constants::ENQUIRY_PROPERTY_SEARCH => ['label' => 'Property Search', 'probability' => 30],
        Constants::ENQUIRY_THINKING_ABOUT_IT => ['label' => 'Thinking About It', 'probability' => 50],
        Constants::ENQUIRY_UNDER_OFFER => ['label' => 'Under Offer', 'probability' => 70],
        Constants::ENQUIRY_SALE_PROGRESSING => ['label' => 'Sale Progressing', 'probability' => 90],
        Constants::ENQUIRY_SOLD => ['label' => 'Sold', 'probability' => 100],
        Constants::ENQUIRY_RENTED => ['label' => 'Rented', 'probability' => 100],
        Constants::ENQUIRY_LOST => ['label' => 'Inactive', 'probability' => 0],
    ];

    /** Enquiry type => commission rate %. Anything not listed is "Secondary" (2%). */
    private const COMMISSION_RATES = [
        'offPlanInvestor' => 4,
        'tenant' => 5,
    ];

    private const DEFAULT_COMMISSION_RATE = 2;

    /** Columns the table can be sorted by (any other value falls back to the default). */
    private const SORTABLE = [
        'dealId', 'stageLabel', 'enquiryType', 'dealValue', 'commissionRate',
        'grossCommission', 'probability', 'weightedValue', 'daysInStage', 'agent',
        'listingType', 'lastMovementDate',
    ];

    /** listingType values whose price is a rent that must be annualised. */
    private const RENT_LISTING_TYPES = ['rent', 'vacationHomes'];

    /** pfPriceFrequency => multiplier to convert to a yearly figure. */
    private const FREQUENCY_TO_YEARLY = ['yearly' => 1, 'monthly' => 12, 'weekly' => 52, 'daily' => 365];

    /** Display listingType => enquiry listingType (property-type values handled separately). */
    private const LISTING_TYPE_FILTER = ['Off-Plan' => 'offPlan', 'Secondary' => 'sale'];

    /**
     * @return array<string, mixed>
     */
    public function report(Request $request): array
    {
        $filters = $this->resolveFilters($request);
        $deals = $this->buildDeals($filters);

        return [
            'currency' => self::CURRENCY,
            'period' => ['startDate' => $filters['startDate'], 'endDate' => $filters['endDate']],
            'stages' => $this->stageCatalogue(),
            'summary' => $this->summary($deals),
            'deals' => $deals->forPage($filters['page'], $filters['perPage'])->values()->all(),
            'pagination' => [
                'page' => $filters['page'],
                'perPage' => $filters['perPage'],
                'total' => $deals->count(),
            ],
        ];
    }

    /**
     * All filtered deals (no pagination) for the CSV export.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(Request $request): array
    {
        return $this->buildDeals($this->resolveFilters($request))
            ->map(fn ($d) => [
                'dealId' => $d['dealId'],
                'enquiryId' => $d['enquiryId'],
                'stage' => $d['stageLabel'],
                'enquiryType' => $d['enquiryType'] ?? '—',
                'dealValue' => $d['dealValue'],
                'commissionRate' => $d['commissionRate'].'%',
                'grossCommission' => $d['grossCommission'],
                'probability' => $d['probability'].'%',
                'weightedValue' => $d['weightedValue'],
                'daysInStage' => $d['daysInStage'],
                'agent' => $d['agent'] ?? '—',
                'listingType' => $d['listingType'] ?? '—',
                'stagnant' => $d['daysInStage'] > self::STAGNANT_DAYS ? 'Yes' : 'No',
                'lastMovementDate' => $d['lastMovementDate'],
            ])
            ->values()
            ->all();
    }

    // ---------------------------------------------------------------------
    // Deal building
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    protected function buildDeals(array $filters): Collection
    {
        $enquiries = $this->enquiryQuery($filters)->get([
            'id', 'referenceNo', 'status', 'enquiryType', 'listingType',
            'listingId', 'priceMin', 'priceMax', 'budget', 'agentId', 'updated_at',
        ]);

        if ($enquiries->isEmpty()) {
            return collect();
        }

        // Batched lookups (no N+1): linked listings, last status change, agent names.
        $properties = Property::whereIn('id', $enquiries->pluck('listingId')->filter()->unique())
            ->get(['id', 'price', 'listingType', 'pfPriceFrequency'])
            ->keyBy('id');
        $lastMovement = EnquiryHistory::where('field', 'status')
            ->whereIn('enquiryId', $enquiries->pluck('id'))
            ->groupBy('enquiryId')
            ->selectRaw('enquiryId, MAX(created_at) as t')
            ->pluck('t', 'enquiryId');
        $agents = User::whereIn('id', $enquiries->pluck('agentId')->filter()->unique())->pluck('name', 'id');

        $now = Carbon::now();

        $deals = $enquiries
            ->map(function (Enquiry $enquiry) use ($properties, $lastMovement, $agents, $now) {
                $stageKey = $enquiry->status;
                $probability = self::STAGES[$stageKey]['probability'] ?? 0;
                $rate = $this->commissionRate($enquiry->enquiryType);

                $dealValue = $this->dealValue($enquiry, $properties->get($enquiry->listingId));
                $grossCommission = round($dealValue * $rate / 100, 2);
                $weightedValue = round($grossCommission * $probability / 100, 2);

                $movedAt = Carbon::parse($lastMovement[$enquiry->id] ?? $enquiry->updated_at);

                return [
                    'dealId' => $enquiry->referenceNo ?: 'ENQ-'.$enquiry->id,
                    'enquiryId' => $enquiry->referenceNo ?: (string) $enquiry->id,
                    'stageKey' => $stageKey,
                    'stageLabel' => self::STAGES[$stageKey]['label'] ?? $stageKey,
                    'enquiryType' => $enquiry->enquiryType,
                    'dealValue' => $dealValue,
                    'commissionRate' => $rate,
                    'grossCommission' => $grossCommission,
                    'probability' => $probability,
                    'weightedValue' => $weightedValue,
                    'daysInStage' => (int) $movedAt->diffInDays($now),
                    'agentId' => $enquiry->agentId,
                    'agent' => $agents[$enquiry->agentId] ?? null,
                    'listingType' => $enquiry->listingType,
                    'lastMovementDate' => $movedAt->toDateString(),
                ];
            })
            ->when($filters['stagnantOnly'], fn ($rows) => $rows->where('daysInStage', '>', self::STAGNANT_DAYS));

        return $this->applySort($deals, $filters['sortBy'], $filters['sortDir']);
    }

    /**
     * Sort the deals by a whitelisted column; unknown/absent column falls back to
     * the default (most stagnant first). Strings sort case-insensitively.
     *
     * @param  Collection<int, array<string, mixed>>  $deals
     * @return Collection<int, array<string, mixed>>
     */
    protected function applySort(Collection $deals, ?string $sortBy, string $sortDir): Collection
    {
        $key = in_array($sortBy, self::SORTABLE, true) ? $sortBy : 'daysInStage';
        $descending = $sortBy === null ? true : $sortDir === 'desc';

        return $deals
            ->sortBy(fn ($d) => is_string($d[$key]) ? mb_strtolower($d[$key]) : $d[$key], SORT_REGULAR, $descending)
            ->values();
    }

    /**
     * Deal value: linked listing price (rent annualised) → budget-range midpoint
     * → single budget value → 0.
     */
    protected function dealValue(Enquiry $enquiry, ?Property $property): float
    {
        if ($property) {
            $price = (float) $property->price;

            if (in_array($property->listingType, self::RENT_LISTING_TYPES, true)) {
                $price *= self::FREQUENCY_TO_YEARLY[$property->pfPriceFrequency] ?? 1;
            }

            return round($price, 2);
        }

        if (is_numeric($enquiry->priceMin) && is_numeric($enquiry->priceMax) && ($enquiry->priceMin > 0 || $enquiry->priceMax > 0)) {
            return round(((float) $enquiry->priceMin + (float) $enquiry->priceMax) / 2, 2);
        }

        return is_numeric($enquiry->budget) ? round((float) $enquiry->budget, 2) : 0.0;
    }

    protected function commissionRate(?string $enquiryType): int
    {
        return self::COMMISSION_RATES[$enquiryType] ?? self::DEFAULT_COMMISSION_RATE;
    }

    // ---------------------------------------------------------------------
    // Summary / catalogue
    // ---------------------------------------------------------------------

    /**
     * @param  Collection<int, array<string, mixed>>  $deals
     * @return array<string, int|float>
     */
    protected function summary(Collection $deals): array
    {
        $total = $deals->count();

        return [
            'totalOpenDeals' => $total,
            'overdueDeals' => $deals->where('daysInStage', '>', self::STAGNANT_DAYS)->count(),
            'avgDaysInStage' => $total > 0 ? (int) round($deals->avg('daysInStage')) : 0,
            'totalDealValue' => round((float) $deals->sum('dealValue'), 2),
            'weightedPipelineValue' => round((float) $deals->sum('weightedValue'), 2),
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, probability: int}>
     */
    protected function stageCatalogue(): array
    {
        $catalogue = [];
        foreach (self::STAGES as $key => $meta) {
            $catalogue[] = ['key' => $key, 'label' => $meta['label'], 'probability' => $meta['probability']];
        }

        return $catalogue;
    }

    // ---------------------------------------------------------------------
    // Query / filters
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function enquiryQuery(array $filters): Builder
    {
        return Enquiry::query()
            // "Every open deal" — Sold, Rented and Inactive (Lost) are closed
            // outcomes, not open pipeline, so they must never appear here.
            ->whereIn('status', Constants::ENQUIRY_ACTIVE_STATUSES)
            ->when($filters['agentIds'] !== null, fn ($q) => $q->whereIn('agentId', $filters['agentIds']))
            ->when(! empty($filters['startDate']), fn ($q) => $q->where('created_at', '>=', localDayStartUtc($filters['startDate'])))
            ->when(! empty($filters['endDate']), fn ($q) => $q->where('created_at', '<=', localDayEndUtc($filters['endDate'])))
            ->when($filters['stage'] !== null, fn ($q) => $q->where('status', $filters['stage']))
            ->when($filters['listingTypeValue'] !== null, fn ($q) => $q->where('listingType', $filters['listingTypeValue']))
            ->when($filters['propertyTypeValue'] !== null, fn ($q) => $q->whereHas('listing', fn ($p) => $p->where('pfPropertyType', $filters['propertyTypeValue'])));
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveFilters(Request $request): array
    {
        $listingType = $request->query('listingType');

        return [
            'startDate' => $request->query('startDate'),
            'endDate' => $request->query('endDate'),
            'stage' => $request->query('stage'),
            // The Listing Type dropdown sends an enquiry listingType value
            // (sale, rent, offPlan, vacationHomes). Filter the enquiry's own
            // listingType column by it, still honouring the older display
            // labels ('Off-Plan' => 'offPlan', 'Secondary' => 'sale') if sent.
            'listingTypeValue' => $listingType ? (self::LISTING_TYPE_FILTER[$listingType] ?? $listingType) : null,
            'propertyTypeValue' => null,
            'stagnantOnly' => filter_var($request->query('stagnantOnly'), FILTER_VALIDATE_BOOLEAN),
            'sortBy' => $request->query('sortBy'),
            'sortDir' => strtolower((string) $request->query('sortDir')) === 'asc' ? 'asc' : 'desc',
            'agentIds' => $this->resolveAgentScope($request),
            'page' => max(1, (int) $request->query('page', 1)),
            // The frontend tables send snake_case `per_page` (app-wide convention);
            // `perPage` is still accepted so existing callers keep working.
            'perPage' => min(100, max(1, (int) ($request->query('perPage') ?? $request->query('per_page') ?? 12))),
        ];
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
