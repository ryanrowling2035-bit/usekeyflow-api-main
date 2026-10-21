<?php

namespace App\Services\Reports;

use App\Constants\Constants;
use App\Models\AssignTeamMember;
use App\Models\Closing;
use App\Models\Enquiry;
use App\Models\Offer;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Powers the "Company Performance Summary" report
 * (GET /reports/company-performance).
 *
 * Returned in one flat payload: `kpis` (the tiles) plus one key per chart, every
 * chart shaped as a list of `{label, value}` (grouped bars add enquiries/
 * closedDeals). All numbers share the same filter contract — date range, listing
 * type, team, agent — resolved once in {@see resolveFilters()}.
 *
 * Sources: enquiries drive lead/volume metrics (isOpenGame = 0 so an open-game
 * lead offered to many agents is not counted multiple times); offers and
 * closings drive the deal/commission metrics. Enquiries / offers / closings are
 * filtered on `created_at` (UTC datetime → local-day boundaries) and, where the
 * column exists, on `listingType`.
 */
class CompanyPerformanceReportService
{
    private const CURRENCY = 'AED';

    /** Closing statuses that count as a real (won) deal. */
    private const WON_CLOSING_STATUSES = [Constants::CLOSE_STATUS_OPEN, Constants::CLOSE_STATUS_CLOSED];

    /** Slices shown before the rest are grouped into "Others" on the donuts/bars. */
    private const TOP_SLICES = 5;

    /**
     * The full Company Performance report.
     *
     * @return array<string, mixed>
     */
    public function report(Request $request): array
    {
        $filters = $this->resolveFilters($request);

        return [
            'currency' => self::CURRENCY,
            'kpis' => $this->kpis($filters),
            'leadSources' => $this->leadSources($filters),
            'enquiriesVsClosedBySource' => $this->enquiriesVsClosedBySource($filters),
            'revenueByTeam' => $this->revenueByTeam($filters),
            'revenueByAgent' => $this->revenueByAgent($filters),
            'nationalities' => $this->nationalities($filters),
            'communityPerformance' => $this->communityPerformance($filters),
            'listingTypeDistribution' => $this->listingTypeDistribution($filters),
            'monthlyRevenueTrend' => $this->monthlyRevenueTrend($filters),
        ];
    }

    // ---------------------------------------------------------------------
    // KPI tiles
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int|float>
     */
    protected function kpis(array $filters): array
    {
        $totalEnquiries = $this->enquiryBase($filters)->count();

        $grossCommission = (float) $this->wonClosingBase($filters)->sum('totalCommission');
        $netCommission = (float) $this->wonClosingBase($filters)->sum('companyCommission');

        $totalClosings = $this->wonClosingBase($filters)->count();
        $lostClosings = $this->closingScoped($filters)
            ->where('status', Constants::CLOSE_STATUS_CANCELLED)->count();

        $averageDealValue = (float) $this->wonClosingBase($filters)->avg('saleRentalPrice');

        // Whole days between a deal being opened (created_at) and its closingDate.
        $averageDaysToClose = $this->wonClosingBase($filters)
            ->whereNotNull('closingDate')
            ->value(DB::raw('AVG(DATEDIFF(closingDate, created_at))'));

        $agentCount = $this->scopedAgentCount($filters);

        return [
            'totalHotLeads' => $this->enquiryBase($filters)
                ->where('leadRating', Constants::LEAD_SCORE_HOT)->count(),
            'totalEnquiries' => $totalEnquiries,
            'totalOffers' => $this->offerBase($filters)->count(),
            'totalClosings' => $totalClosings,
            'grossCommission' => $grossCommission,
            'netCommission' => $netCommission,
            'averageDealValue' => round($averageDealValue, 2),
            'averageDaysToClose' => $averageDaysToClose !== null ? (int) round((float) $averageDaysToClose) : 0,
            // Won deals as a share of all decided deals (won + lost/cancelled).
            'winLossRatio' => ($totalClosings + $lostClosings) > 0
                ? round($totalClosings / ($totalClosings + $lostClosings) * 100, 1)
                : 0.0,
            // Deals as a share of enquiries received.
            'leadToDealConversion' => $totalEnquiries > 0
                ? round($totalClosings / $totalEnquiries * 100, 1)
                : 0.0,
            'revenuePerAgent' => $agentCount > 0 ? round($grossCommission / $agentCount, 2) : 0.0,
            'revenuePerEnquiry' => $totalEnquiries > 0 ? round($grossCommission / $totalEnquiries, 2) : 0.0,
        ];
    }

    // ---------------------------------------------------------------------
    // Charts (each returns a list of {label, value})
    // ---------------------------------------------------------------------

    /**
     * Donut: enquiries by lead source.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, value: int}>
     */
    protected function leadSources(array $filters): array
    {
        $counts = $this->foldByLeadSourceGroup($this->groupedCount($this->enquiryBase($filters), 'leadSource'));

        return $this->topSlices($counts, fn ($key) => $this->leadSourceLabel($key));
    }

    /**
     * Grouped bars: enquiries vs closed deals per lead source.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, enquiries: int, closedDeals: int}>
     */
    protected function enquiriesVsClosedBySource(array $filters): array
    {
        // Both sides are folded onto the same roll-up before they are joined:
        // enquiries carry "propertyfinderAe" while their closings carry
        // "propertyFinder", so raw keys line the two up against each other and
        // report a portal's deals against a fraction of its leads.
        $enquiriesBySource = $this->foldByLeadSourceGroup($this->groupedCount($this->enquiryBase($filters), 'leadSource'));
        $closedBySource = $this->foldByLeadSourceGroup($this->groupedCount($this->wonClosingBase($filters), 'clientLeadSource'));

        $rows = collect(array_keys($enquiriesBySource + $closedBySource))
            ->map(fn ($source) => [
                'label' => $this->leadSourceLabel($source),
                'enquiries' => (int) ($enquiriesBySource[$source] ?? 0),
                'closedDeals' => (int) ($closedBySource[$source] ?? 0),
            ])
            ->sortByDesc('enquiries')
            ->values();

        if ($rows->count() <= self::TOP_SLICES + 1) {
            return $rows->all();
        }

        $top = $rows->take(self::TOP_SLICES);
        $rest = $rows->slice(self::TOP_SLICES);

        return $top->push([
            'label' => 'Others',
            'enquiries' => (int) $rest->sum('enquiries'),
            'closedDeals' => (int) $rest->sum('closedDeals'),
        ])->values()->all();
    }

    /**
     * Bars: commission revenue grouped by the deal agent's team.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, value: float}>
     */
    protected function revenueByTeam(array $filters): array
    {
        $revenueByAgent = $this->groupedSum($this->wonClosingBase($filters), 'assignedAgentId', 'totalCommission');
        if (empty($revenueByAgent)) {
            return [];
        }

        // One team per agent (their first assignment); agents with none => Unassigned.
        $agentTeam = AssignTeamMember::whereIn('userId', array_keys($revenueByAgent))
            ->get(['userId', 'teamId'])
            ->groupBy('userId')
            ->map(fn ($rows) => (int) $rows->first()->teamId);

        $revenueByTeam = [];
        foreach ($revenueByAgent as $agentId => $revenue) {
            $teamId = $agentTeam[$agentId] ?? 0; // 0 = Unassigned bucket
            $revenueByTeam[$teamId] = ($revenueByTeam[$teamId] ?? 0) + $revenue;
        }

        $teamNames = Team::whereIn('id', array_keys($revenueByTeam))->pluck('name', 'id');

        return collect($revenueByTeam)
            ->map(fn ($revenue, $teamId) => [
                'label' => $teamId ? ($teamNames[$teamId] ?? 'Unknown') : 'Unassigned',
                'value' => (float) $revenue,
            ])
            ->sortByDesc('value')
            ->values()
            ->all();
    }

    /**
     * Horizontal bars: commission revenue per agent (highest first).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, value: float}>
     */
    protected function revenueByAgent(array $filters): array
    {
        $revenueByAgent = $this->groupedSum($this->wonClosingBase($filters), 'assignedAgentId', 'totalCommission');
        if (empty($revenueByAgent)) {
            return [];
        }

        $agents = User::whereIn('id', array_keys($revenueByAgent))->pluck('name', 'id');

        return collect($revenueByAgent)
            ->filter(fn ($revenue, $agentId) => isset($agents[$agentId]))
            ->map(fn ($revenue, $agentId) => [
                'label' => $agents[$agentId],
                'value' => (float) $revenue,
            ])
            ->sortByDesc('value')
            ->values()
            ->all();
    }

    /**
     * Donut: closed deals by client nationality — deal count and commission
     * revenue per nationality (top 5 + Others).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, count: int, revenue: float}>
     */
    protected function nationalities(array $filters): array
    {
        $map = [];
        foreach ($this->wonClosingBase($filters)->get(['clientNationality', 'totalCommission']) as $closing) {
            $key = trim((string) $closing->clientNationality) ?: 'Unknown';
            $map[$key] ??= ['count' => 0, 'revenue' => 0.0];
            $map[$key]['count']++;
            $map[$key]['revenue'] += (float) $closing->totalCommission;
        }

        return $this->breakdownBy($map);
    }

    /**
     * Bars: closed deals grouped by the listing's community, with the deal count
     * and commission revenue per community (top slices + Others, Unknown last).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, count: int, revenue: float}>
     */
    protected function communityPerformance(array $filters): array
    {
        // LEFT join (not inner) so deals whose listing has no community still count
        // — they fall into the "Unknown" bucket instead of being dropped, keeping
        // the breakdown reconciled with the Deals Closed total. Qualified column
        // names are required because closings and properties share created_at.
        $query = Closing::query()
            ->leftJoin('properties', 'closings.listingId', '=', 'properties.id')
            ->leftJoin('property_communities', 'properties.pfCommunity', '=', 'property_communities.communityId')
            ->whereIn('closings.status', self::WON_CLOSING_STATUSES);

        if ($filters['agentIds'] !== null) {
            $query->whereIn('closings.assignedAgentId', $filters['agentIds']);
        }
        if (! empty($filters['startDate'])) {
            $query->where('closings.created_at', '>=', localDayStartUtc($filters['startDate']));
        }
        if (! empty($filters['endDate'])) {
            $query->where('closings.created_at', '<=', localDayEndUtc($filters['endDate']));
        }
        if (! empty($filters['listingType'])) {
            $query->where('closings.listingType', $filters['listingType']);
        }

        $map = [];
        $rows = $query->get([
            'property_communities.name as communityName',
            'closings.totalCommission as commission',
        ]);
        foreach ($rows as $row) {
            $key = trim((string) $row->communityName) ?: 'Unknown';
            $map[$key] ??= ['count' => 0, 'revenue' => 0.0];
            $map[$key]['count']++;
            $map[$key]['revenue'] += (float) $row->commission;
        }

        return $this->breakdownBy($map);
    }

    /**
     * Roll a [label => {count, revenue}] map into a {label, count, revenue} chart:
     * sorted desc by count, top-N kept, the tail folded into "Others", with
     * "Unknown" and "Others" always pushed to the end. `count` is deals closed.
     *
     * @param  array<string, array{count: int, revenue: float}>  $map
     * @return array<int, array{label: string, count: int, revenue: float}>
     */
    protected function breakdownBy(array $map, int $limit = self::TOP_SLICES): array
    {
        $unknown = $map['Unknown'] ?? null;
        unset($map['Unknown']);

        uasort($map, fn ($a, $b) => $b['count'] <=> $a['count']);

        $result = [];
        $others = ['count' => 0, 'revenue' => 0.0];
        $index = 0;
        foreach ($map as $label => $data) {
            if ($index < $limit) {
                $result[] = ['label' => (string) $label, 'count' => (int) $data['count'], 'revenue' => round($data['revenue'], 2)];
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
     * Donut: closed deals by listing type, expressed as a percentage share.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, value: float}>
     */
    protected function listingTypeDistribution(array $filters): array
    {
        $counts = $this->groupedCount($this->wonClosingBase($filters), 'listingType');
        $total = array_sum($counts);
        arsort($counts);

        return collect($counts)
            ->map(fn ($count, $type) => [
                'label' => Str::headline((string) $type),
                'value' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
            ])
            ->values()
            ->all();
    }

    /**
     * Line: total commission per month across the selected range (or the last
     * six months when no range is given).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, value: float}>
     */
    protected function monthlyRevenueTrend(array $filters): array
    {
        if (! empty($filters['startDate']) && ! empty($filters['endDate'])) {
            $from = Carbon::parse($filters['startDate'])->startOfMonth();
            $to = Carbon::parse($filters['endDate'])->startOfMonth();
        } else {
            $to = Carbon::now()->startOfMonth();
            $from = $to->copy()->subMonths(5);
        }

        $months = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $months[$cursor->format('Y-m')] = $cursor->format('M Y');
            $cursor->addMonth();
        }

        $query = Closing::query()->whereIn('status', self::WON_CLOSING_STATUSES)
            ->whereBetween('created_at', [$from->copy()->startOfMonth(), $to->copy()->endOfMonth()]);
        if ($filters['agentIds'] !== null) {
            $query->whereIn('assignedAgentId', $filters['agentIds']);
        }
        if (! empty($filters['listingType'])) {
            $query->where('listingType', $filters['listingType']);
        }

        $buckets = $query->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COALESCE(SUM(totalCommission), 0) as rev")
            ->groupBy('ym')
            ->pluck('rev', 'ym');

        $result = [];
        foreach ($months as $key => $label) {
            $result[] = ['label' => $label, 'value' => (float) ($buckets[$key] ?? 0)];
        }

        return $result;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @return array{startDate: ?string, endDate: ?string, listingType: ?string, agentIds: ?array<int, int>}
     */
    protected function resolveFilters(Request $request): array
    {
        return [
            'startDate' => $request->query('startDate'),
            'endDate' => $request->query('endDate'),
            'listingType' => $request->query('listingType'),
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

    /** @param array<string, mixed> $filters */
    protected function scopedAgentCount(array $filters): int
    {
        return User::where('status', 'active')
            ->whereHas('role', fn ($q) => $q->where('name', Constants::AGENT))
            ->when($filters['agentIds'] !== null, fn ($q) => $q->whereIn('id', $filters['agentIds']))
            ->count();
    }

    // ---- Scoped base builders -------------------------------------------

    /** @param array<string, mixed> $filters */
    protected function enquiryBase(array $filters): Builder
    {
        // No isOpenGame filter — every enquiry counts, Open Game pool included,
        // so the tiles reflect the whole lead volume.
        $query = Enquiry::query();
        $this->applyScope($query, 'agentId', $filters);
        $this->applyCreatedAtFilter($query, $filters);
        $this->applyListingTypeFilter($query, $filters);

        return $query;
    }

    /** @param array<string, mixed> $filters */
    protected function offerBase(array $filters): Builder
    {
        $query = Offer::query();
        $this->applyScope($query, 'assignedAgentId', $filters);
        $this->applyCreatedAtFilter($query, $filters);
        $this->applyListingTypeFilter($query, $filters);

        return $query;
    }

    /**
     * Closings within scope/date/listingType, WITHOUT a status filter.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function closingScoped(array $filters): Builder
    {
        $query = Closing::query();
        $this->applyScope($query, 'assignedAgentId', $filters);
        $this->applyCreatedAtFilter($query, $filters);
        $this->applyListingTypeFilter($query, $filters);

        return $query;
    }

    /**
     * Closings that count as won deals (open/closed).
     *
     * @param  array<string, mixed>  $filters
     */
    protected function wonClosingBase(array $filters): Builder
    {
        return $this->closingScoped($filters)->whereIn('status', self::WON_CLOSING_STATUSES);
    }

    // ---- Reusable query fragments ---------------------------------------

    /** @param array<string, mixed> $filters */
    protected function applyScope(Builder $query, string $column, array $filters): void
    {
        if ($filters['agentIds'] !== null) {
            $query->whereIn($column, $filters['agentIds']);
        }
    }

    /** @param array<string, mixed> $filters */
    protected function applyCreatedAtFilter(Builder $query, array $filters): void
    {
        if (! empty($filters['startDate'])) {
            $query->where('created_at', '>=', localDayStartUtc($filters['startDate']));
        }
        if (! empty($filters['endDate'])) {
            $query->where('created_at', '<=', localDayEndUtc($filters['endDate']));
        }
    }

    /** @param array<string, mixed> $filters */
    protected function applyListingTypeFilter(Builder $query, array $filters): void
    {
        if (! empty($filters['listingType'])) {
            $query->where('listingType', $filters['listingType']);
        }
    }

    // ---- Aggregation / formatting helpers -------------------------------

    /**
     * COUNT(*) grouped by $column => [ key => count ]. Column names are internal
     * constants, never user input.
     *
     * @return array<string|int, int>
     */
    protected function groupedCount(Builder $query, string $column): array
    {
        return $query->whereNotNull($column)
            ->groupBy($column)
            ->selectRaw("$column as _key, COUNT(*) as _value")
            ->pluck('_value', '_key')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * SUM($sumColumn) grouped by $column => [ key => sum ].
     *
     * @return array<int, float>
     */
    protected function groupedSum(Builder $query, string $column, string $sumColumn): array
    {
        return $query->whereNotNull($column)
            ->groupBy($column)
            ->selectRaw("$column as _key, COALESCE(SUM($sumColumn), 0) as _value")
            ->pluck('_value', '_key')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Turn a [key => count] map into a chart-ready list: the top {@see TOP_SLICES}
     * slices plus an aggregated "Others", each as {label, value}.
     *
     * @param  array<string|int, int>  $counts
     * @return array<int, array{label: string, value: int}>
     */
    protected function topSlices(array $counts, callable $label): array
    {
        arsort($counts);

        $result = [];
        $othersValue = 0;
        $index = 0;

        foreach ($counts as $key => $count) {
            if ($index < self::TOP_SLICES) {
                $result[] = ['label' => $label($key), 'value' => (int) $count];
            } else {
                $othersValue += $count;
            }
            $index++;
        }

        if ($othersValue > 0) {
            $result[] = ['label' => 'Others', 'value' => (int) $othersValue];
        }

        return $result;
    }

    /**
     * Re-key a [raw lead source => count] map onto its reporting roll-ups,
     * summing the spellings that belong to one source.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    protected function foldByLeadSourceGroup(array $counts): array
    {
        $folded = [];
        foreach ($counts as $raw => $count) {
            $group = Constants::leadSourceGroup((string) $raw) ?? (string) $raw;
            $folded[$group] = ($folded[$group] ?? 0) + (int) $count;
        }

        arsort($folded);

        return $folded;
    }

    protected function leadSourceLabel(int|string|null $key): string
    {
        $key = (string) $key;

        return Constants::LEAD_SOURCE_VARIANT_LABELS[$key] ?? Str::headline($key);
    }
}
