<?php

namespace App\Repositories;

use App\Constants\Constants;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class TaskRepository
{
    /**
     * Tasks for the grouped list UI, split into two nodes: `overdue` (open tasks
     * past their due date) and `tasks` (everything else). Both nodes apply every
     * filter below and share the `per_page` size, but each node pages on its own
     * query param — `overdue_page` and `tasks_page` — so paging one list leaves
     * the other where it is.
     *
     * @return array{overdue: LengthAwarePaginator, tasks: LengthAwarePaginator}
     */
    public function getAll(Request $request): array
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 100;

        $today = Carbon::today()->toDateString();

        return [
            'overdue' => $this->applyOverdue($this->filteredQuery($request), $today, true)->paginate($perPage, ['*'], 'overdue_page'),
            'tasks' => $this->applyOverdue($this->filteredQuery($request), $today, false)->paginate($perPage, ['*'], 'tasks_page'),
        ];
    }

    /**
     * Restrict to (or exclude) overdue tasks: open — not closed/done — and past
     * their due date. Same definition as the dashboard's Overdue tile and the
     * overdue follow-up job. A NULL status counts as open so the two nodes stay a
     * true partition; SQL's three-valued logic would otherwise drop such a row
     * from both.
     */
    private function applyOverdue(Builder $query, string $today, bool $overdue): Builder
    {
        $isOverdue = fn ($q) => $q
            ->where(fn ($s) => $s->whereNull('status')->orWhereNotIn('status', Constants::TASK_CLOSED_STATUSES))
            ->whereDate('dueDate', '<', $today);

        return $overdue ? $query->where($isOverdue) : $query->whereNot($isOverdue);
    }

    /** Every filter the tasks list supports, shared by both nodes. */
    private function filteredQuery(Request $request): Builder
    {
        $query = Task::query()->with(['assignedUser.role', 'creator.role']);

        // If agent, filter by assigned or created by user
        if (isAgent()) {
            $query->where(function ($q) {
                $q->where('assignedTo', user()->id)
                    ->orWhere('createdBy', user()->id);
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('subject', 'LIKE', "%{$search}%")
                    ->orWhere('comments', 'LIKE', "%{$search}%")
                    ->orWhereRaw('LOWER(status) LIKE ?', ['%'.strtolower($search).'%'])
                    ->orWhere('priority', 'LIKE', "%{$search}%")
                    ->orWhere('type', 'LIKE', "%{$search}%");
            });
        }
        if ($request->filled('status')) {
            // Single status or a comma-separated list ("closeFollowUp,done").
            $statuses = array_filter(array_map('trim', explode(',', $request->status)));
            count($statuses) > 1 ? $query->whereIn('status', $statuses) : $query->where('status', reset($statuses));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('type')) {
            // Single type or a comma-separated list ("meeting,viewing").
            $types = array_filter(array_map('trim', explode(',', $request->type)));
            count($types) > 1 ? $query->whereIn('type', $types) : $query->where('type', reset($types));
        }
        // Exclude one or more types — the "Tasks/Follow-ups" KPI uses it to drop
        // notes. Untyped (null) tasks are kept (they aren't the excluded type).
        if ($request->filled('excludeType')) {
            $excluded = array_filter(array_map('trim', explode(',', $request->excludeType)));
            if (! empty($excluded)) {
                $query->where(fn ($q) => $q->whereNull('type')->orWhereNotIn('type', $excluded));
            }
        }
        if ($request->filled('assignedTo')) {
            $query->where('assignedTo', $request->assignedTo);
        }
        if ($request->filled('module')) {
            // Single module or a comma-separated list ("property,enquiry"), matching how
            // status and type above already behave — without this, "user,property" was
            // compared as one literal string and always returned nothing.
            $modules = array_filter(array_map('trim', explode(',', $request->module)));
            count($modules) > 1 ? $query->whereIn('module', $modules) : $query->where('module', reset($modules));
        }
        if ($request->filled('moduleId')) {
            $query->where('moduleId', $request->moduleId);
        }
        // Listing-type filter: a task carries no listingType of its own, so match
        // it through its linked record (property/enquiry/offer/closing). Mirrors
        // AgentProductivityReportService::applyTaskListingTypeFilter so the Tasks
        // Overview drill-down count reconciles with that report's tiles.
        if ($request->filled('listingType')) {
            $moduleTables = [
                'property' => 'properties',
                'enquiry' => 'enquiries',
                'offer' => 'offers',
                'closing' => 'closings',
            ];
            $listingType = $request->listingType;
            $query->where(function ($outer) use ($moduleTables, $listingType) {
                foreach ($moduleTables as $module => $table) {
                    $outer->orWhere(function ($q) use ($module, $table, $listingType) {
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
        if ($request->filled('startDate') || $request->filled('endDate')) {
            $startDate = $request->input('startDate', $request->endDate);
            $endDate = $request->input('endDate', $request->startDate);
            $query->whereBetween('dueDate', [$startDate, $endDate]);
        }

        if ($request->filled('id')) {
            $query->orderByRaw(
                'CASE WHEN id = ? THEN 0 ELSE 1 END',
                [$request->id]
            );
        }

        $query->orderBy('id', 'desc');

        return $query;
    }

    public function create(array $data)
    {
        return Task::create($data);
    }

    public function findById(int $id)
    {
        $query = Task::query()->with(['assignedUser.role', 'creator.role']);

        if (isAgent()) {
            $query->where(function ($q) {
                $q->where('assignedTo', user()->id)
                    ->orWhere('createdBy', user()->id);
            });
        }

        return $query->find($id);
    }

    public function update(int $id, array $data)
    {
        $task = $this->findById($id);

        if (! $task) {
            return null;
        }

        $task->update($data);

        return $task;
    }

    public function delete(int $id): bool
    {
        $task = $this->findById($id);

        if (! $task) {
            return false;
        }

        return (bool) $task->delete();
    }
}
