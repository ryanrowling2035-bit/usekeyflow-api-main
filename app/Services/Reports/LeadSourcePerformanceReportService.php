<?php

namespace App\Services\Reports;

use App\Constants\Constants;
use App\Models\AssignTeamMember;
use App\Models\Closing;
use App\Models\Contact;
use App\Models\Enquiry;
use App\Models\PropertyCommunity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Powers the "Lead Source Performance Report" (GET /reports/lead-source-performance).
 *
 * Enquiries are grouped by their `leadSource`; for each source the report gives
 * total enquiries, deals closed, revenue, conversion rate and revenue per lead.
 * The KPI tiles, donut and bar charts on the page are all derived from this same
 * `sources` list (plus the `totals` footer). Deals and revenue are attributed to
 * a source through the enquiry a closing is linked to (closings.enquiryId →
 * enquiries.leadSource), so they always align with the enquiry counts.
 *
 * `technicalSource` per row is the most common technical channel (WhatsApp /
 * Email / Call ...) recorded on that source's enquiries.
 *
 * Filters (shared contract): date range, agent, team, listingType, leadSource,
 * technicalSource. Open-game enquiries are included (the main pipeline here) so
 * closed deals are attributed.
 */
class LeadSourcePerformanceReportService
{
    private const CURRENCY = 'AED';

    /** Closing statuses that count as a won deal. */
    private const WON_CLOSING_STATUSES = [Constants::CLOSE_STATUS_OPEN, Constants::CLOSE_STATUS_CLOSED];

    /** Sources shown before the rest are folded into an "Others" row. */
    private const TOP_SOURCES = 6;

    /**
     * @return array{currency: string, totals: array<string, int|float>, sources: array<int, array<string, mixed>>}
     */
    public function report(Request $request): array
    {
        $filters = $this->resolveFilters($request);
        $aggregates = $this->aggregateBySource($filters);

        // Built once from the exact same closing set as totals.dealsClosed, so the
        // two breakdowns reconcile (sum of count == dealsClosed).
        $dealRows = $this->closedDealRows($filters);

        // Highest-volume sources first; the long tail is folded into "Others".
        uasort($aggregates, fn ($a, $b) => $b['enquiries'] <=> $a['enquiries']);

        $sources = [];
        $others = ['enquiries' => 0, 'deals' => 0, 'revenue' => 0.0, 'tech' => [], 'variants' => []];
        $index = 0;

        foreach ($aggregates as $data) {
            if ($index < self::TOP_SOURCES) {
                $sources[] = $this->sourceRow($data['key'], $this->techMode($data['tech']), $data);
            } else {
                $others['enquiries'] += $data['enquiries'];
                $others['deals'] += $data['deals'];
                $others['revenue'] += $data['revenue'];
                foreach ($data['tech'] as $tech => $count) {
                    $others['tech'][$tech] = ($others['tech'][$tech] ?? 0) + $count;
                }
                foreach ($data['variants'] ?? [] as $variant => $count) {
                    $others['variants'][$variant] = ($others['variants'][$variant] ?? 0) + $count;
                }
            }
            $index++;
        }

        if ($others['enquiries'] > 0) {
            $sources[] = $this->sourceRow('Others', $this->techMode($others['tech']), $others);
        }

        return [
            'currency' => self::CURRENCY,
            'totals' => $this->totals($aggregates),
            'sources' => $sources,
            'nationalities' => $this->breakdownBy($dealRows, 'nationality'),
            'communityPerformance' => $this->breakdownBy($dealRows, 'community'),
        ];
    }

    /**
     * Won closings, one row per deal, with the client nationality and community
     * attached. Scoped by the CLOSING (date / agent / listing type) and the lead /
     * technical source filters — the exact same set counted by totals.dealsClosed,
     * so the nationality and community breakdowns reconcile to that total. A deal
     * whose nationality or community is missing keeps its row (bucketed as
     * "Unknown" downstream) rather than being dropped.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Support\Collection<int, array{nationality: string, community: string, revenue: float}>
     */
    protected function closedDealRows(array $filters): \Illuminate\Support\Collection
    {
        $closings = Closing::query()
            ->whereIn('status', self::WON_CLOSING_STATUSES)
            ->whereNotNull('enquiryId')
            ->when($filters['agentIds'] !== null, fn ($q) => $q->whereIn('assignedAgentId', $filters['agentIds']))
            ->when(! empty($filters['startDate']), fn ($q) => $q->where('created_at', '>=', localDayStartUtc($filters['startDate'])))
            ->when(! empty($filters['endDate']), fn ($q) => $q->where('created_at', '<=', localDayEndUtc($filters['endDate'])))
            ->when(! empty($filters['listingType']), fn ($q) => $q->where('listingType', $filters['listingType']))
            ->get(['enquiryId', 'clientNationality', 'totalCommission']);

        if ($closings->isEmpty()) {
            return collect();
        }

        $enquiries = Enquiry::whereIn('id', $closings->pluck('enquiryId')->unique())
            ->get(['id', 'leadSource', 'technicalSource', 'community', 'contactId'])
            ->keyBy('id');
        $contactNationalities = Contact::whereIn('id', $enquiries->pluck('contactId')->filter()->unique())
            ->pluck('nationality', 'id');
        $communityNames = PropertyCommunity::whereIn('communityId', $enquiries->pluck('community')->filter()->unique())
            ->pluck('name', 'communityId');

        $rows = collect();
        foreach ($closings as $closing) {
            $enquiry = $enquiries->get($closing->enquiryId);
            if (! $enquiry) {
                continue; // Mirrors addClosings: no enquiry, so not counted in dealsClosed either.
            }
            // Same rule the enquiry-side whereIn uses, so both sides of the
            // report agree: "Property Finder" keeps a deal whose enquiry is
            // stored as "propertyfinderAe" or "pfPrimaryPlus", while a variant
            // selection stays exact.
            if (! Constants::leadSourceMatchesFilter($enquiry->leadSource, $filters['leadSource'])) {
                continue;
            }
            if (! empty($filters['technicalSource']) && stripos((string) $enquiry->technicalSource, (string) $filters['technicalSource']) === false) {
                continue;
            }

            // Nationality from the closing's recorded client nationality, else the
            // linked contact's; community from the enquiry's primary community.
            $nationality = trim((string) $closing->clientNationality)
                ?: trim((string) ($contactNationalities[$enquiry->contactId] ?? ''));
            $community = $enquiry->community ? ($communityNames[$enquiry->community] ?? null) : null;

            $rows->push([
                'nationality' => $nationality !== '' ? $nationality : 'Unknown',
                'community' => $community ?: 'Unknown',
                'revenue' => (float) $closing->totalCommission,
            ]);
        }

        return $rows;
    }

    /**
     * Roll deal rows into a report breakdown for the given key ("nationality" or
     * "community"): {label, count, revenue}, sorted desc by count, the long tail
     * folded into "Others", with "Unknown" and "Others" always pushed to the end.
     * `count` is deals closed (never renamed to `value`).
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return array<int, array{label: string, count: int, revenue: float}>
     */
    protected function breakdownBy($rows, string $key, int $limit = 8): array
    {
        $map = [];
        foreach ($rows as $row) {
            $label = $row[$key];
            $map[$label] ??= ['count' => 0, 'revenue' => 0.0];
            $map[$label]['count']++;
            $map[$label]['revenue'] += $row['revenue'];
        }

        // Unknown is pulled out so it never takes a top slot; it is appended last.
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

        // Unknown then Others — both at the tail.
        if ($unknown) {
            $result[] = ['label' => 'Unknown', 'count' => (int) $unknown['count'], 'revenue' => round($unknown['revenue'], 2)];
        }
        if ($others['count'] > 0) {
            $result[] = ['label' => 'Others', 'count' => $others['count'], 'revenue' => round($others['revenue'], 2)];
        }

        return $result;
    }

    /**
     * Flattened rows (one per lead source + a Total footer) for the CSV export.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(Request $request): array
    {
        $report = $this->report($request);

        $rows = array_map(fn ($s) => [
            'leadSource' => $s['leadSource'],
            'technicalSource' => $s['technicalSource'] ?? '—',
            'sourceVariants' => implode(', ', array_map(
                fn ($v) => $v['label'].' ('.$v['count'].')',
                $s['sourceVariants'] ?? []
            )) ?: '—',
            'totalEnquiries' => $s['totalEnquiries'],
            'dealsClosed' => $s['dealsClosed'],
            'revenue' => $s['revenue'],
            'conversionRate' => $s['conversionRate'].'%',
            'revenuePerLead' => $s['revenuePerLead'],
        ], $report['sources']);

        $t = $report['totals'];
        $rows[] = [
            'leadSource' => 'Total',
            'technicalSource' => '—',
            'sourceVariants' => '—',
            'totalEnquiries' => $t['totalEnquiries'],
            'dealsClosed' => $t['dealsClosed'],
            'revenue' => $t['revenue'],
            'conversionRate' => $t['conversionRate'].'%',
            'revenuePerLead' => $t['revenuePerLead'],
        ];

        return $rows;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Build a [leadSource => {enquiries, deals, revenue, tech}] map from the
     * filtered enquiries and their linked closings — two queries, no N+1.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, array{key: string, enquiries: int, deals: int, revenue: float, tech: array<string, int>}>
     */
    protected function aggregateBySource(array $filters): array
    {
        $enquiries = $this->enquiryBase($filters)
            ->get(['id', 'leadSource', 'technicalSource'])
            ->filter(fn ($e) => trim((string) $e->leadSource) !== '')
            ->values();

        // Keyed by the DISPLAY label of the roll-up, not the raw value: one
        // portal reached the CRM under several spellings (the legacy import
        // "propertyfinderAe", the current feed "propertyFinder", the product
        // "pfPrimaryPlus") and must appear as a single row, or a renewal figure
        // reads a fraction of the real volume. leadSourceGroup folds the known
        // families together; the label still absorbs unlisted spellings that
        // headline to the same text. The closing side below keys off the same.
        $aggregates = [];
        foreach ($enquiries as $e) {
            $source = $this->groupLabel($e->leadSource);
            if (! isset($aggregates[$source])) {
                $aggregates[$source] = ['key' => $source, 'enquiries' => 0, 'deals' => 0, 'revenue' => 0.0, 'tech' => [], 'variants' => []];
            }
            $aggregates[$source]['enquiries']++;

            // The raw stored value, kept per row so the merged source can still
            // be broken down by where it technically came from.
            $variant = trim((string) $e->leadSource);
            if ($variant !== '') {
                $aggregates[$source]['variants'][$variant] = ($aggregates[$source]['variants'][$variant] ?? 0) + 1;
            }

            $tech = trim((string) $e->technicalSource);
            if ($tech !== '') {
                $aggregates[$source]['tech'][$tech] = ($aggregates[$source]['tech'][$tech] ?? 0) + 1;
            }
        }

        $this->addClosings($aggregates, $filters);

        return $aggregates;
    }

    /**
     * Fold won closings into the per-source aggregates.
     *
     * Deals are scoped exactly like the Closings list — by the CLOSING's own
     * date, agent and listing type — so the "Deals Closed" tile and that list
     * agree. (Enquiry volume above is scoped by the ENQUIRY's date, which answers
     * a different question and stays that way.) A deal is attributed to the lead
     * source of the enquiry it belongs to, even when that enquiry was created
     * before the selected range.
     *
     * @param  array<string, array<string, mixed>>  $aggregates
     * @param  array<string, mixed>  $filters
     */
    protected function addClosings(array &$aggregates, array $filters): void
    {
        $closings = Closing::query()
            ->whereIn('status', self::WON_CLOSING_STATUSES)
            ->whereNotNull('enquiryId')
            ->when($filters['agentIds'] !== null, fn ($q) => $q->whereIn('assignedAgentId', $filters['agentIds']))
            ->when(! empty($filters['startDate']), fn ($q) => $q->where('created_at', '>=', localDayStartUtc($filters['startDate'])))
            ->when(! empty($filters['endDate']), fn ($q) => $q->where('created_at', '<=', localDayEndUtc($filters['endDate'])))
            ->when(! empty($filters['listingType']), fn ($q) => $q->where('listingType', $filters['listingType']))
            ->get(['enquiryId', 'totalCommission']);

        if ($closings->isEmpty()) {
            return;
        }

        $enquiries = Enquiry::whereIn('id', $closings->pluck('enquiryId')->unique())
            ->get(['id', 'leadSource', 'technicalSource'])
            ->keyBy('id');

        foreach ($closings as $closing) {
            $enquiry = $enquiries->get($closing->enquiryId);
            if (! $enquiry) {
                continue; // Enquiry missing — nothing to attribute the deal to.
            }

            // Honour the source filters the enquiry side already applies.
            // Same rule the enquiry-side whereIn uses, so both sides of the
            // report agree: "Property Finder" keeps a deal whose enquiry is
            // stored as "propertyfinderAe" or "pfPrimaryPlus", while a variant
            // selection stays exact.
            if (! Constants::leadSourceMatchesFilter($enquiry->leadSource, $filters['leadSource'])) {
                continue;
            }
            if (! empty($filters['technicalSource']) && stripos((string) $enquiry->technicalSource, (string) $filters['technicalSource']) === false) {
                continue;
            }

            $source = $this->groupLabel($enquiry->leadSource);
            $aggregates[$source] ??= ['key' => $source, 'enquiries' => 0, 'deals' => 0, 'revenue' => 0.0, 'tech' => [], 'variants' => []];
            $aggregates[$source]['deals']++;
            $aggregates[$source]['revenue'] += (float) $closing->totalCommission;
        }
    }

    /**
     * @param  array{enquiries: int, deals: int, revenue: float}  $data
     * @return array<string, mixed>
     */
    protected function sourceRow(string $leadSource, ?string $technicalSource, array $data): array
    {
        $enquiries = $data['enquiries'];

        return [
            'leadSource' => $leadSource,
            'technicalSource' => $technicalSource,
            'sourceVariants' => $this->variantBreakdown($data['variants'] ?? []),
            'totalEnquiries' => $enquiries,
            'dealsClosed' => $data['deals'],
            'revenue' => round($data['revenue'], 2),
            'conversionRate' => $enquiries > 0 ? round($data['deals'] / $enquiries * 100, 2) : 0.0,
            'revenuePerLead' => $enquiries > 0 ? round($data['revenue'] / $enquiries, 2) : 0.0,
        ];
    }

    /**
     * @param  array<string, array{enquiries: int, deals: int, revenue: float}>  $aggregates
     * @return array<string, int|float>
     */
    protected function totals(array $aggregates): array
    {
        $enquiries = 0;
        $deals = 0;
        $revenue = 0.0;
        foreach ($aggregates as $data) {
            $enquiries += $data['enquiries'];
            $deals += $data['deals'];
            $revenue += $data['revenue'];
        }

        return [
            'totalEnquiries' => $enquiries,
            'dealsClosed' => $deals,
            'revenue' => round($revenue, 2),
            'conversionRate' => $enquiries > 0 ? round($deals / $enquiries * 100, 2) : 0.0,
            'revenuePerLead' => $enquiries > 0 ? round($revenue / $enquiries, 2) : 0.0,
        ];
    }

    /**
     * The raw stored lead source values behind a merged row, most common first.
     * The roll-up is what the business reports on; this keeps the technical
     * origin (legacy Salesforce import vs current feed vs Primary Plus)
     * available for detailed analysis without rewriting any record.
     *
     * @param  array<string, int>  $variantCounts
     * @return array<int, array{value: string, label: string, count: int}>
     */
    protected function variantBreakdown(array $variantCounts): array
    {
        arsort($variantCounts);

        return array_values(array_map(fn ($value, $count) => [
            'value' => (string) $value,
            'label' => $this->leadSourceLabel($value),
            'count' => (int) $count,
        ], array_keys($variantCounts), $variantCounts));
    }

    /**
     * Most frequent technical source, as a display label.
     *
     * @param  array<string, int>  $techCounts
     */
    protected function techMode(array $techCounts): ?string
    {
        if (empty($techCounts)) {
            return null;
        }

        arsort($techCounts);
        $key = (string) array_key_first($techCounts);

        return Constants::TECHNICAL_SOURCE[$key] ?? Str::headline($key);
    }

    /**
     * Display label for the roll-up a raw stored value belongs to, so every
     * spelling of one portal lands on a single row.
     */
    protected function groupLabel(int|string|null $raw): string
    {
        return $this->leadSourceLabel(Constants::leadSourceGroup((string) $raw));
    }

    protected function leadSourceLabel(int|string|null $key): string
    {
        $key = (string) $key;

        return Constants::LEAD_SOURCE_VARIANT_LABELS[$key] ?? Str::headline($key);
    }

    /**
     * @return array{startDate: ?string, endDate: ?string, listingType: ?string, leadSource: ?string, technicalSource: ?string, agentIds: ?array<int, int>}
     */
    protected function resolveFilters(Request $request): array
    {
        return [
            'startDate' => $request->query('startDate'),
            'endDate' => $request->query('endDate'),
            'listingType' => $request->query('listingType'),
            'leadSource' => $request->query('leadSource'),
            'technicalSource' => $request->query('technicalSource'),
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
    protected function enquiryBase(array $filters): Builder
    {
        return Enquiry::query()
            ->when($filters['agentIds'] !== null, fn ($q) => $q->whereIn('agentId', $filters['agentIds']))
            ->when(! empty($filters['startDate']), fn ($q) => $q->where('created_at', '>=', localDayStartUtc($filters['startDate'])))
            ->when(! empty($filters['endDate']), fn ($q) => $q->where('created_at', '<=', localDayEndUtc($filters['endDate'])))
            ->when(! empty($filters['listingType']), fn ($q) => $q->where('listingType', $filters['listingType']))
            ->when(! empty($filters['leadSource']), fn ($q) => $q->whereIn('leadSource', Constants::leadSourceGroupMembers($filters['leadSource'])))
            ->when(! empty($filters['technicalSource']), fn ($q) => $q->where('technicalSource', 'LIKE', '%'.$filters['technicalSource'].'%'));
    }
}
