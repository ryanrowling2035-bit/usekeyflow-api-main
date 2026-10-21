<?php

namespace App\Services\Reports;

use App\Constants\Constants;
use App\Models\AssignTeamMember;
use App\Models\Closing;
use App\Models\Contact;
use App\Models\Enquiry;
use App\Models\EnquiryHistory;
use App\Models\Offer;
use App\Models\PropertyCommunity;
use App\Models\Task;
use App\Services\Reports\Concerns\BuildsDistribution;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Powers the "Enquiry Conversion Report" (GET /reports/enquiry-conversion).
 *
 * Enquiries are split into two conversion funnels — Buy and Rent — by their
 * `listingType` (rent / vacationHomes = Rent, everything else = Buy). Each funnel
 * walks five stages and, for every stage, reports how many enquiries reached it,
 * the top lost reasons for enquiries that fell out there, the average time to
 * reach the stage and the average first-response time.
 *
 * Stage definitions (an enquiry "reached" a stage if the linked record exists):
 *  - newEnquiries ... every enquiry of the type (funnel top).
 *  - inProgress ..... worked past "New Lead" (status changed, or a viewing/offer/
 *                     closing exists).
 *  - viewingsLogged . has at least one viewing task (task module = enquiry).
 *  - offersSubmitted  has at least one offer (offers.enquiryId).
 *  - dealsClosed .... has a closing (closings.enquiryId).
 *
 * Timing: "time to conversion" for a stage is the average gap between the
 * enquiry's created_at and the event that marks the stage (first history entry
 * for In Progress, first viewing / offer / closing otherwise). "Response time"
 * is the gap between created_at and the first real history activity (the
 * `created` bookkeeping entry is ignored).
 *
 * Lost reasons are free text, grouped verbatim (top 3) and bucketed by the
 * furthest stage the lost enquiry reached.
 *
 * Filters (shared contract): date range, agent, team, enquiryType, leadSource
 * and `stage` — the enquiry's own status (newLead, contacting, underOffer,
 * sold, ...), which narrows the enquiry set the funnels are built from.
 */
class EnquiryConversionReportService
{
    use BuildsDistribution;

    /** listingType values treated as the Rent funnel; everything else is Buy. */
    private const RENT_LISTING_TYPES = ['rent', 'vacationHomes'];

    /** Closing statuses that count as a won deal (for revenue attribution). */
    private const WON_CLOSING_STATUSES = [Constants::CLOSE_STATUS_OPEN, Constants::CLOSE_STATUS_CLOSED];

    private const STAGE_LABELS = [
        'newEnquiries' => 'New Enquiries',
        'inProgress' => 'In Progress',
        'viewingsLogged' => 'Viewings Logged',
        'offersSubmitted' => 'Offers Submitted',
        'dealsClosed' => 'Deals Closed',
    ];

    /**
     * The full report: headline KPIs plus the Buy and Rent funnels.
     *
     * @return array{kpis: array<string, int|float>, enquiryTypes: array<int, array<string, mixed>>}
     */
    public function report(Request $request): array
    {
        $filters = $this->resolveFilters($request);

        $enquiries = $this->enquiryBase($filters)
            ->get(['id', 'created_at', 'status', 'listingType', 'lostReason']);

        $events = $this->loadStageEvents($enquiries->pluck('id')->all());

        [$rentEnquiries, $buyEnquiries] = $enquiries->partition(
            fn ($e) => in_array($e->listingType, self::RENT_LISTING_TYPES, true)
        );

        return [
            'kpis' => $this->kpis($enquiries, $events),
            'enquiryTypes' => [
                $this->buildType('buy', 'Buy', $buyEnquiries, $events),
                $this->buildType('rent', 'Rent', $rentEnquiries, $events),
            ],
            'nationalities' => $this->nationalities($filters),
            'communityPerformance' => $this->communityPerformance($filters),
        ];
    }

    /**
     * Donut: closed deals by client nationality (from the linked contact), with
     * the deal count and commission revenue per nationality.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, count: int, revenue: float}>
     */
    protected function nationalities(array $filters): array
    {
        $map = [];
        foreach ($this->closedDealRows($filters) as $row) {
            $key = $row['nationality'];
            $map[$key] ??= ['count' => 0, 'revenue' => 0.0];
            $map[$key]['count']++;
            $map[$key]['revenue'] += $row['revenue'];
        }

        return $this->distributionWithRevenue($map, fn ($key) => $key);
    }

    /**
     * Bars: closed deals by community (the enquiry's primary community), with the
     * deal count and commission revenue per community.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, count: int, revenue: float}>
     */
    protected function communityPerformance(array $filters): array
    {
        $map = [];
        foreach ($this->closedDealRows($filters) as $row) {
            $key = $row['community'];
            $map[$key] ??= ['count' => 0, 'revenue' => 0.0];
            $map[$key]['count']++;
            $map[$key]['revenue'] += $row['revenue'];
        }

        return $this->distributionWithRevenue($map, fn ($key) => $key);
    }

    /**
     * Won closings for the filtered funnel, one row per deal, with the client
     * nationality and the enquiry's community attached. Both distributions are
     * "by Closed Deals", so a deal is one closing (an enquiry with two closings
     * counts as two deals).
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{nationality: string, community: string, revenue: float}>
     */
    protected function closedDealRows(array $filters): Collection
    {
        $enquiries = $this->enquiryBase($filters)->get(['id', 'contactId', 'community']);
        if ($enquiries->isEmpty()) {
            return collect();
        }

        $nationalities = Contact::whereIn('id', $enquiries->pluck('contactId')->filter()->unique())
            ->pluck('nationality', 'id');
        $communityNames = PropertyCommunity::whereIn('communityId', $enquiries->pluck('community')->filter()->unique())
            ->pluck('name', 'communityId');
        $byId = $enquiries->keyBy('id');

        return Closing::whereIn('enquiryId', $enquiries->pluck('id'))
            ->whereIn('status', self::WON_CLOSING_STATUSES)
            ->get(['enquiryId', 'totalCommission'])
            ->map(function ($closing) use ($byId, $nationalities, $communityNames) {
                $enquiry = $byId->get($closing->enquiryId);

                return [
                    'nationality' => trim((string) ($nationalities[$enquiry->contactId] ?? '')) ?: 'Unknown',
                    'community' => $enquiry->community ? ($communityNames[$enquiry->community] ?? 'Unknown') : 'Unknown',
                    'revenue' => (float) $closing->totalCommission,
                ];
            });
    }

    /**
     * Flattened rows (one per enquiry-type + stage) for the CSV export.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(Request $request): array
    {
        $rows = [];
        foreach ($this->report($request)['enquiryTypes'] as $type) {
            foreach ($type['stages'] as $stage) {
                $lostReasons = collect($stage['lostReasons'])
                    ->map(fn ($r) => "{$r['reason']} ({$r['count']})")
                    ->implode(', ');

                $rows[] = [
                    'enquiryType' => $type['label'],
                    'stage' => self::STAGE_LABELS[$stage['key']] ?? $stage['key'],
                    'totalEnquiries' => $stage['value'],
                    'lostReasons' => $lostReasons !== '' ? $lostReasons : '—',
                    'timeToConversionDays' => $stage['timeToConversionDays'] ?? '—',
                    'avgResponseTimeHours' => $stage['avgResponseTimeHours'] ?? '—',
                ];
            }
        }

        return $rows;
    }

    // ---------------------------------------------------------------------
    // KPI tiles
    // ---------------------------------------------------------------------

    /**
     * @param  Collection<int, Enquiry>  $enquiries
     * @param  array<string, Collection<int, string>>  $events
     * @return array<string, int|float>
     */
    protected function kpis(Collection $enquiries, array $events): array
    {
        $total = $enquiries->count();

        $closedPairs = [];
        $responsePairs = [];
        $conversions = 0;

        foreach ($enquiries as $e) {
            if (($closedAt = $events['closing']->get($e->id)) !== null) {
                $conversions++;
                $closedPairs[] = [$e->created_at, $closedAt];
            }
            if (($firstActivity = $events['history']->get($e->id)) !== null) {
                $responsePairs[] = [$e->created_at, $firstActivity];
            }
        }

        return [
            'totalEnquiries' => $total,
            'conversions' => $conversions,
            'overallConversionRate' => $total > 0 ? round($conversions / $total * 100, 2) : 0.0,
            'avgTimeToConversionDays' => $this->averageGap($closedPairs, 'days') ?? 0,
            'avgResponseTimeHours' => $this->averageGap($responsePairs, 'hours') ?? 0,
        ];
    }

    // ---------------------------------------------------------------------
    // Funnel builder
    // ---------------------------------------------------------------------

    /**
     * @param  Collection<int, Enquiry>  $enquiries
     * @param  array<string, Collection<int, string>>  $events
     * @return array{key: string, label: string, stages: array<int, array<string, mixed>>}
     */
    protected function buildType(string $key, string $label, Collection $enquiries, array $events): array
    {
        // Enquiry subsets that reached each stage, plus timing pairs [created, event].
        $reached = [
            'inProgress' => collect(),
            'viewingsLogged' => collect(),
            'offersSubmitted' => collect(),
            'dealsClosed' => collect(),
        ];
        $lostReasons = ['inProgress' => [], 'viewingsLogged' => [], 'offersSubmitted' => []];

        foreach ($enquiries as $e) {
            $hasViewing = $events['viewing']->get($e->id) !== null;
            $hasOffer = $events['offer']->get($e->id) !== null;
            $hasClosing = $events['closing']->get($e->id) !== null;
            $inProgress = $e->status !== Constants::ENQUIRY_NEW_LEAD || $hasViewing || $hasOffer || $hasClosing;

            if ($inProgress) {
                $reached['inProgress']->push($e);
            }
            if ($hasViewing) {
                $reached['viewingsLogged']->push($e);
            }
            if ($hasOffer) {
                $reached['offersSubmitted']->push($e);
            }
            if ($hasClosing) {
                $reached['dealsClosed']->push($e);
            }

            if ($e->status === Constants::ENQUIRY_LOST) {
                $bucket = $hasOffer ? 'offersSubmitted' : ($hasViewing ? 'viewingsLogged' : 'inProgress');
                $reason = trim((string) $e->lostReason);
                if ($reason !== '') {
                    $lostReasons[$bucket][] = $reason;
                }
            }
        }

        $stages = [
            [
                'key' => 'newEnquiries',
                'value' => $enquiries->count(),
                'lostReasons' => [],
                'timeToConversionDays' => null,
                'avgResponseTimeHours' => $this->responseTime($enquiries, $events),
            ],
            [
                'key' => 'inProgress',
                'value' => $reached['inProgress']->count(),
                'lostReasons' => $this->topReasons($lostReasons['inProgress']),
                'timeToConversionDays' => $this->stageGap($reached['inProgress'], $events['history'], 'days'),
                'avgResponseTimeHours' => $this->responseTime($reached['inProgress'], $events),
            ],
            [
                'key' => 'viewingsLogged',
                'value' => $reached['viewingsLogged']->count(),
                'lostReasons' => $this->topReasons($lostReasons['viewingsLogged']),
                'timeToConversionDays' => $this->stageGap($reached['viewingsLogged'], $events['viewing'], 'days'),
                'avgResponseTimeHours' => $this->responseTime($reached['viewingsLogged'], $events),
            ],
            [
                'key' => 'offersSubmitted',
                'value' => $reached['offersSubmitted']->count(),
                'lostReasons' => $this->topReasons($lostReasons['offersSubmitted']),
                'timeToConversionDays' => $this->stageGap($reached['offersSubmitted'], $events['offer'], 'days'),
                'avgResponseTimeHours' => $this->responseTime($reached['offersSubmitted'], $events),
            ],
            [
                'key' => 'dealsClosed',
                'value' => $reached['dealsClosed']->count(),
                'lostReasons' => [],
                'timeToConversionDays' => $this->stageGap($reached['dealsClosed'], $events['closing'], 'days'),
                'avgResponseTimeHours' => $this->responseTime($reached['dealsClosed'], $events),
            ],
        ];

        return ['key' => $key, 'label' => $label, 'stages' => $stages];
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * First timestamp per enquiry for each stage event, keyed by enquiry id.
     *
     * @param  array<int, int>  $enquiryIds
     * @return array<string, Collection<int, string>>
     */
    protected function loadStageEvents(array $enquiryIds): array
    {
        if (empty($enquiryIds)) {
            $empty = collect();

            return ['history' => $empty, 'viewing' => $empty, 'offer' => $empty, 'closing' => $empty];
        }

        $intKeys = fn (Collection $c) => $c->mapWithKeys(fn ($value, $key) => [(int) $key => $value]);

        return [
            // First real activity (the "created" bookkeeping entry is ignored) => response time.
            'history' => $intKeys(EnquiryHistory::whereIn('enquiryId', $enquiryIds)
                ->where('field', '!=', 'created')
                ->groupBy('enquiryId')
                ->selectRaw('enquiryId, MIN(created_at) as t')
                ->pluck('t', 'enquiryId')),
            'viewing' => $intKeys(Task::where('module', Constants::MODULE_ENQUIRY)
                ->where('type', 'viewing')
                ->whereIn('moduleId', $enquiryIds)
                ->groupBy('moduleId')
                ->selectRaw('moduleId, MIN(created_at) as t')
                ->pluck('t', 'moduleId')),
            'offer' => $intKeys(Offer::whereIn('enquiryId', $enquiryIds)
                ->groupBy('enquiryId')
                ->selectRaw('enquiryId, MIN(created_at) as t')
                ->pluck('t', 'enquiryId')),
            'closing' => $intKeys(Closing::whereIn('enquiryId', $enquiryIds)
                ->groupBy('enquiryId')
                ->selectRaw('enquiryId, MIN(created_at) as t')
                ->pluck('t', 'enquiryId')),
        ];
    }

    /**
     * Average first-response time (hours) over enquiries that have a first
     * activity recorded.
     *
     * @param  Collection<int, Enquiry>  $enquiries
     * @param  array<string, Collection<int, string>>  $events
     */
    protected function responseTime(Collection $enquiries, array $events): ?float
    {
        $pairs = [];
        foreach ($enquiries as $e) {
            if (($activity = $events['history']->get($e->id)) !== null) {
                $pairs[] = [$e->created_at, $activity];
            }
        }

        return $this->averageGap($pairs, 'hours');
    }

    /**
     * Average gap (days) between each enquiry's created_at and its stage event.
     *
     * @param  Collection<int, Enquiry>  $enquiries
     * @param  Collection<int, string>  $eventMap
     */
    protected function stageGap(Collection $enquiries, Collection $eventMap, string $unit): ?float
    {
        $pairs = [];
        foreach ($enquiries as $e) {
            if (($event = $eventMap->get($e->id)) !== null) {
                $pairs[] = [$e->created_at, $event];
            }
        }

        return $this->averageGap($pairs, $unit);
    }

    /**
     * @param  array<int, array{0: mixed, 1: mixed}>  $pairs  [createdAt, eventAt]
     */
    protected function averageGap(array $pairs, string $unit): ?float
    {
        if (empty($pairs)) {
            return null;
        }

        $sum = 0.0;
        foreach ($pairs as [$start, $end]) {
            $start = Carbon::parse($start);
            $end = Carbon::parse($end);
            // abs(): the gap is a magnitude — guards against out-of-order timestamps.
            $sum += abs($unit === 'hours' ? $start->floatDiffInHours($end) : $start->floatDiffInDays($end));
        }

        return round($sum / count($pairs), 1);
    }

    /**
     * Top 3 free-text lost reasons by frequency.
     *
     * @param  array<int, string>  $reasons
     * @return array<int, array{reason: string, count: int}>
     */
    protected function topReasons(array $reasons): array
    {
        if (empty($reasons)) {
            return [];
        }

        $counts = array_count_values($reasons);
        arsort($counts);

        return collect($counts)
            ->take(3)
            ->map(fn ($count, $reason) => ['reason' => (string) $reason, 'count' => (int) $count])
            ->values()
            ->all();
    }

    /**
     * @return array{startDate: ?string, endDate: ?string, agentIds: ?array<int, int>, enquiryType: ?string, leadSource: ?string}
     */
    protected function resolveFilters(Request $request): array
    {
        return [
            'startDate' => $request->query('startDate'),
            'endDate' => $request->query('endDate'),
            'enquiryType' => $request->query('enquiryType'),
            'leadSource' => $request->query('leadSource'),
            // The report toolbar labels this "Status" and sends `status`; `stage` is
            // the original name and still works.
            'status' => $request->query('stage') ?? $request->query('status'),
            'agentIds' => $this->resolveAgentScope($request),
        ];
    }

    /**
     * @return array<int, int>|null null = whole company
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

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function enquiryBase(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        // No isOpenGame filter: in this system the open-game pool is the main
        // pipeline (agents pick leads from it and close them), so the funnel must
        // include those enquiries or it would never show any converted deals.
        return Enquiry::query()
            ->when($filters['agentIds'] !== null, fn ($q) => $q->whereIn('agentId', $filters['agentIds']))
            ->when(! empty($filters['startDate']), fn ($q) => $q->where('created_at', '>=', localDayStartUtc($filters['startDate'])))
            ->when(! empty($filters['endDate']), fn ($q) => $q->where('created_at', '<=', localDayEndUtc($filters['endDate'])))
            ->when(! empty($filters['enquiryType']), fn ($q) => $this->applyEnquiryTypeFilter($q, $filters['enquiryType']))
            ->when(! empty($filters['leadSource']), fn ($q) => $q->whereIn('leadSource', Constants::leadSourceGroupMembers($filters['leadSource'])))
            // Status = the enquiry's own status (newLead, contacting, underOffer, sold, ...).
            // resolveFilters stores it under the 'status' key (accepting both the
            // `status` and legacy `stage` query params).
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']));
    }

    /**
     * The report's Enquiry Type filter is the FUNNEL side — "buy" or "rent" —
     * which is decided by listingType, exactly as the two funnels are split. Any
     * other value is treated as a real `enquiryType` (buyer, tenant, landlord…).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    protected function applyEnquiryTypeFilter($query, string $enquiryType): void
    {
        match ($enquiryType) {
            'rent' => $query->whereIn('listingType', self::RENT_LISTING_TYPES),
            // Anything not rent-side counts as Buy, including a missing listingType.
            'buy' => $query->where(fn ($q) => $q->whereNotIn('listingType', self::RENT_LISTING_TYPES)
                ->orWhereNull('listingType')),
            default => $query->where('enquiryType', $enquiryType),
        };
    }
}
