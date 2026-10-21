<?php

namespace App\Services\Reports;

use App\Constants\Constants;
use App\Models\AssignTeamMember;
use App\Models\Closing;
use App\Models\Enquiry;
use App\Models\Offer;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Powers the "Agent Productivity Report" (GET /reports/agent-productivity).
 *
 * The report is returned in one payload: scope-wide `totals` (the KPI tiles)
 * plus a per-agent `agents` breakdown. Every request shares the same filter
 * contract (date range, team, agent, listing type) resolved in
 * {@see resolveFilters()}.
 *
 * Metric definitions (kept in one place so they are easy to audit / tweak):
 *  - Total Active Enquiries . every enquiry in the range sitting in an active
 *                             pipeline status — claimed AND Open Game — so the
 *                             tile means exactly what its label says.
 *  - Claimed Active Enq. .... the subset an agent has taken ownership of
 *                             (isOpenGame = 0).
 *  - Open Game Active Enq. .. the subset still unclaimed in the Open Game pool
 *                             (isOpenGame = 1). Claimed + Open Game = Total.
 *  - New Leads .............. active enquiries still at the "New Lead" stage.
 *  - Enquiries In Progress .. active enquiries worked past the "New Lead" stage.
 *                             New Leads + In Progress = Total Active Enquiries.
 *  - Viewings Logged ........ viewing tasks whose dueDate falls in the range.
 *  - Offers Submitted ....... offers created in the range.
 *  - Closings Completed ..... closings (open/closed, not cancelled) in the range.
 *  - Revenue Generated ...... SUM(totalCommission) over those closings.
 *  - Pending Tasks .......... tasks in the "Pending" status.
 *  - Overdue Tasks .......... open tasks past due (dueDate < today).
 *  - Completed Tasks ........ tasks in a closed status.
 *
 * Enquiries / offers / closings are filtered on `created_at` (a UTC datetime,
 * so the selected local day is converted to UTC boundaries). Tasks are filtered
 * on `dueDate`, which is a plain DATE column and is compared as-is. The listing
 * type filter is applied directly to enquiries / offers / closings (they carry a
 * `listingType` column) and to tasks via their linked record — see
 * {@see applyTaskListingTypeFilter()}.
 */
class AgentProductivityReportService
{
    /**
     * Currency all monetary figures in the report are expressed in.
     */
    private const CURRENCY = 'AED';

    /**
     * The complete Agent Productivity report in one payload.
     *
     * `totals` are scope-wide headline figures (the KPI tiles) and stay correct
     * regardless of pagination; `agents` is the paginated per-agent breakdown.
     *
     * @return array{currency: string, totals: array<string, int|float>, agents: array<int, array<string, mixed>>, pagination: array{page: int, perPage: int, total: int}}
     */
    public function report(Request $request): array
    {
        $filters = $this->resolveFilters($request);

        return [
            'currency' => self::CURRENCY,
            'totals' => $this->scalarMetrics($filters),
            // Scope-wide, NOT paginated — the revenue chart reads this so it can
            // never drift with the agents table's current page.
            'revenueByAgent' => $this->revenueByAgent($filters),
            'agents' => $this->buildAgentRows($filters, true)->all(),
            'pagination' => [
                'page' => $filters['page'],
                'perPage' => $filters['perPage'],
                'total' => $this->agentQuery($filters)->count(),
            ],
        ];
    }

    /**
     * The per-agent breakdown rows for the CSV export (same data as the table),
     * honouring the same filters as {@see report()}.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(Request $request): array
    {
        return $this->buildAgentRows($this->resolveFilters($request))->all();
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Resolve the shared filter contract from the request.
     *
     * `agentIds` is the resolved scope: a single agent, a team's members, or
     * null for "everyone". Agents are always locked to their own data.
     *
     * @return array{startDate: ?string, endDate: ?string, listingType: ?string, agentIds: ?array<int, int>}
     */
    protected function resolveFilters(Request $request): array
    {
        return [
            'startDate' => $request->query('startDate'),
            'endDate' => $request->query('endDate'),
            'listingType' => $request->query('listingType'),
            'agentIds' => $this->resolveAgentScope($request),
            'page' => max(1, (int) $request->query('page', 1)),
            // The frontend tables send snake_case `per_page` (app-wide convention);
            // `perPage` is still accepted so existing callers keep working.
            'perPage' => min(100, max(1, (int) ($request->query('perPage') ?? $request->query('per_page') ?? 10))),
        ];
    }

    /**
     * @return array<int, int>|null null = no scope (all agents)
     */
    protected function resolveAgentScope(Request $request): ?array
    {
        // Agents may only ever see their own numbers.
        if (isAgent()) {
            return [(int) user()->id];
        }

        if ($request->filled('agentId')) {
            return [(int) $request->query('agentId')];
        }

        if ($request->filled('teamId')) {
            return AssignTeamMember::where('teamId', (int) $request->query('teamId'))
                ->pluck('userId')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return null;
    }

    /**
     * All headline metrics as scalars (the KPI tiles / report totals).
     *
     * @param  array{startDate: ?string, endDate: ?string, listingType: ?string, agentIds: ?array<int, int>}  $filters
     * @return array<string, int|float>
     */
    protected function scalarMetrics(array $filters): array
    {
        $today = Carbon::today()->toDateString();

        return [
            'totalActiveEnquiries' => $this->activeEnquiryBase($filters)->count(),
            // The two halves of the headline figure, so "Total" can be trusted at
            // face value while the claimed/unclaimed split stays visible.
            'claimedActiveEnquiries' => $this->activeEnquiryBase($filters)->where('isOpenGame', 0)->count(),
            'openGameActiveEnquiries' => $this->activeEnquiryBase($filters)->where('isOpenGame', 1)->count(),
            // Enquiries still sitting at the "New Lead" stage — the counterpart of
            // enquiriesInProgress, so the two add up to totalActiveEnquiries.
            'newLeads' => $this->activeEnquiryBase($filters)
                ->where('status', Constants::ENQUIRY_NEW_LEAD)->count(),
            'enquiriesInProgress' => $this->activeEnquiryBase($filters)
                ->where('status', '!=', Constants::ENQUIRY_NEW_LEAD)
                ->count(),
            'viewingsLogged' => $this->taskBase($filters)->where('type', 'viewing')->count(),
            'offersSubmitted' => $this->offerBase($filters)->count(),
            'closingsCompleted' => $this->closingBase($filters)->count(),
            'revenueGenerated' => (float) $this->closingBase($filters)->sum('totalCommission'),
            'pendingTasks' => $this->taskBase($filters)->where('status', Constants::TASK_STATUS_PENDING)->count(),
            'overdueTasks' => $this->openTaskBase($filters)->where('dueDate', '<', $today)->count(),
            'completedTasks' => $this->taskBase($filters)
                ->whereIn('status', Constants::TASK_CLOSED_STATUSES)->count(),
        ];
    }

    /**
     * Build the per-agent metric rows for a resolved filter set.
     *
     * One grouped query per metric (keyed by agent id) avoids N+1 across agents.
     *
     * @param  array{startDate: ?string, endDate: ?string, listingType: ?string, agentIds: ?array<int, int>}  $filters
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function agentQuery(array $filters): Builder
    {
        return User::query()
            ->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->where('name', Constants::AGENT))
            ->when($filters['agentIds'] !== null, fn ($q) => $q->whereIn('id', $filters['agentIds']));
    }

    protected function buildAgentRows(array $filters, bool $paginate = false): Collection
    {
        $agents = $this->agentQuery($filters)
            ->with('teams:id,name')
            ->orderBy('name')
            ->when($paginate, fn ($q) => $q->forPage($filters['page'], $filters['perPage']))
            ->get(['id', 'name', 'email', 'agentCode', 'profilePhoto']);

        $today = Carbon::today()->toDateString();

        $activeEnquiries = $this->groupedCount($this->activeEnquiryBase($filters), 'agentId');
        $claimedActive = $this->groupedCount(
            $this->activeEnquiryBase($filters)->where('isOpenGame', 0),
            'agentId'
        );
        $openGameActive = $this->groupedCount(
            $this->activeEnquiryBase($filters)->where('isOpenGame', 1),
            'agentId'
        );
        $newLeads = $this->groupedCount(
            $this->activeEnquiryBase($filters)->where('status', Constants::ENQUIRY_NEW_LEAD),
            'agentId'
        );
        $inProgress = $this->groupedCount(
            $this->activeEnquiryBase($filters)->where('status', '!=', Constants::ENQUIRY_NEW_LEAD),
            'agentId'
        );
        $viewings = $this->groupedCount($this->taskBase($filters)->where('type', 'viewing'), 'assignedTo');
        $offers = $this->groupedCount($this->offerBase($filters), 'assignedAgentId');
        $closings = $this->groupedCount($this->closingBase($filters), 'assignedAgentId');
        $revenue = $this->groupedSum($this->closingBase($filters), 'assignedAgentId', 'totalCommission');
        $pendingTasks = $this->groupedCount(
            $this->taskBase($filters)->where('status', Constants::TASK_STATUS_PENDING),
            'assignedTo'
        );
        $overdueTasks = $this->groupedCount(
            $this->openTaskBase($filters)->where('dueDate', '<', $today),
            'assignedTo'
        );
        $completedTasks = $this->groupedCount(
            $this->taskBase($filters)->whereIn('status', Constants::TASK_CLOSED_STATUSES),
            'assignedTo'
        );

        return $agents->map(function (User $agent) use (
            $activeEnquiries, $claimedActive, $openGameActive, $newLeads, $inProgress,
            $viewings, $offers, $closings, $revenue, $pendingTasks, $overdueTasks, $completedTasks
        ) {
            $activeCount = $activeEnquiries[$agent->id] ?? 0;
            $claimedCount = $claimedActive[$agent->id] ?? 0;
            $closedCount = $closings[$agent->id] ?? 0;
            $firstTeam = $agent->teams->first();

            return [
                'agentId' => $agent->id,
                'agentName' => $agent->name,
                'email' => $agent->email,
                'agentCode' => $agent->agentCode,
                'profilePhoto' => $agent->profilePhoto,
                'teamId' => $firstTeam->id ?? null,
                'teamName' => $firstTeam->name ?? null,
                'teams' => $agent->teams->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
                'totalActiveEnquiries' => $activeCount,
                'claimedActiveEnquiries' => $claimedCount,
                'openGameActiveEnquiries' => $openGameActive[$agent->id] ?? 0,
                'newLeads' => $newLeads[$agent->id] ?? 0,
                'enquiriesInProgress' => $inProgress[$agent->id] ?? 0,
                'viewingsLogged' => $viewings[$agent->id] ?? 0,
                'offersSubmitted' => $offers[$agent->id] ?? 0,
                'closingsCompleted' => $closedCount,
                'revenueGenerated' => (float) ($revenue[$agent->id] ?? 0),
                'pendingTasks' => $pendingTasks[$agent->id] ?? 0,
                'completedTasks' => $completedTasks[$agent->id] ?? 0,
                'overdueTasks' => $overdueTasks[$agent->id] ?? 0,
                // Activity-to-deal ratio: CLAIMED active enquiries per closed deal
                // (null when no deals). Deliberately excludes Open Game leads — an
                // unclaimed lead is not the agent's activity, so counting it here
                // would inflate the ratio without the agent having done anything.
                'activityToDealRatio' => $closedCount > 0 ? round($claimedCount / $closedCount, 2) : null,
            ];
        })->values();
    }

    // ---- Scoped base query builders (one per source model) --------------

    /** @param array<string, mixed> $filters */
    protected function enquiryBase(array $filters): Builder
    {
        // NB: no isOpenGame filter here. Open Game enquiries are real, active
        // enquiries; hiding them made "Total Active Enquiries" report a small
        // fraction of the pipeline. Callers that want one side of the split ask
        // for it explicitly via ->where('isOpenGame', ...).
        $query = Enquiry::query();
        $this->applyScope($query, 'agentId', $filters);
        $this->applyCreatedAtFilter($query, $filters);
        $this->applyListingTypeFilter($query, $filters);

        return $query;
    }

    /**
     * Enquiries in the range that are currently in an active pipeline status —
     * the single source every enquiry figure on this page is derived from, so
     * the KPI tiles and the per-agent table can never disagree.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function activeEnquiryBase(array $filters): Builder
    {
        return $this->enquiryBase($filters)
            ->whereIn('status', Constants::ENQUIRY_ACTIVE_STATUSES);
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

    /** @param array<string, mixed> $filters */
    protected function closingBase(array $filters): Builder
    {
        $query = Closing::query()
            ->whereIn('status', [Constants::CLOSE_STATUS_OPEN, Constants::CLOSE_STATUS_CLOSED]);
        $this->applyScope($query, 'assignedAgentId', $filters);
        $this->applyCreatedAtFilter($query, $filters);
        $this->applyListingTypeFilter($query, $filters);

        return $query;
    }

    /** @param array<string, mixed> $filters */
    protected function taskBase(array $filters): Builder
    {
        $query = Task::query();
        $this->applyScope($query, 'assignedTo', $filters);
        $this->applyDueDateFilter($query, $filters);
        $this->applyTaskListingTypeFilter($query, $filters);

        return $query;
    }

    /**
     * A task has no `listingType` of its own — it is linked (module + moduleId)
     * to a property / enquiry / offer / closing, each of which does. So when a
     * listing-type filter is active we keep only the tasks whose linked record
     * matches. Tasks tied to a record without a listing type (e.g. user/system
     * tasks) are intentionally excluded while the filter is on.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function applyTaskListingTypeFilter(Builder $query, array $filters): void
    {
        if (empty($filters['listingType'])) {
            return;
        }

        // Task `module` value => the table holding the linked record.
        $moduleTables = [
            Constants::MODULE_PROPERTY => 'properties',
            Constants::MODULE_ENQUIRY => 'enquiries',
            'offer' => 'offers',
            'closing' => 'closings',
        ];

        $listingType = $filters['listingType'];

        $query->where(function (Builder $outer) use ($moduleTables, $listingType) {
            foreach ($moduleTables as $module => $table) {
                $outer->orWhere(function (Builder $q) use ($module, $table, $listingType) {
                    $q->where('tasks.module', $module)
                        ->whereExists(function ($sub) use ($table, $listingType) {
                            $sub->selectRaw('1')
                                ->from($table)
                                ->whereColumn("$table.id", 'tasks.moduleId')
                                ->where("$table.listingType", $listingType);
                        });
                });
            }
        });
    }

    /**
     * Tasks still open (not in a closed status) — base for pending/overdue.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function openTaskBase(array $filters): Builder
    {
        return $this->taskBase($filters)
            ->whereNotIn('status', Constants::TASK_CLOSED_STATUSES);
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

    /**
     * dueDate is a plain DATE column, compared against the calendar date as-is.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function applyDueDateFilter(Builder $query, array $filters): void
    {
        if (! empty($filters['startDate'])) {
            $query->where('dueDate', '>=', $filters['startDate']);
        }
        if (! empty($filters['endDate'])) {
            $query->where('dueDate', '<=', $filters['endDate']);
        }
    }

    /** @param array<string, mixed> $filters */
    protected function applyListingTypeFilter(Builder $query, array $filters, string $column = 'listingType'): void
    {
        if (! empty($filters['listingType'])) {
            $query->where($column, $filters['listingType']);
        }
    }

    /**
     * Per-agent revenue across the WHOLE filtered scope, highest first.
     *
     * Deliberately NOT derived from the paginated `agents` rows: it is grouped
     * straight off the same closings query that produces
     * `totals.revenueGenerated`, so SUM(revenueByAgent.revenue) always equals
     * that tile exactly — including commission booked against users who are no
     * longer active agent accounts, which the `agents` table cannot show. A
     * "Top 10 agents" chart is then simply the first 10 rows of this list.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{agentId: int, agentName: string, revenue: float}>
     */
    protected function revenueByAgent(array $filters): array
    {
        $totals = $this->closingBase($filters)
            ->whereNotNull('assignedAgentId')
            ->groupBy('assignedAgentId')
            ->selectRaw('assignedAgentId as _key, COALESCE(SUM(totalCommission), 0) as _value')
            ->pluck('_value', '_key');

        $names = User::whereIn('id', $totals->keys())->pluck('name', 'id');

        return $totals->map(fn ($revenue, $id) => [
            'agentId' => (int) $id,
            'agentName' => $names[$id] ?? 'Unknown',
            'revenue' => (float) $revenue,
        ])->sortByDesc('revenue')->values()->all();
    }

    // ---- Grouped aggregation helpers ------------------------------------

    /**
     * COUNT(*) grouped by $column, returned as [ (int) key => (int) count ].
     * Column names are internal constants, never user input.
     *
     * @return array<int, int>
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
     * SUM($sumColumn) grouped by $column, returned as [ (int) key => (float) sum ].
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
}
