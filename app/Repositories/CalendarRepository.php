<?php

namespace App\Repositories;

use App\Constants\Constants;
use App\Models\CalendarEvent;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CalendarRepository
{
    /**
     * Get all calendar events with filters.
     */
    public function getAll(Request $request)
    {
        $query = CalendarEvent::query()->with(['assignedUser', 'creator']);

        if (isAgent()) {
            $query->where(function ($q) {
                $q->where('createdBy', user()->id)
                    ->orWhere('assignedTo', user()->id);
            });
        }

        // Date range filter
        if ($request->filled('startDate')) {
            $query->where('startDateTime', '>=', Carbon::parse($request->startDate)->startOfDay());
        }
        if ($request->filled('endDate')) {
            $query->where('startDateTime', '<=', Carbon::parse($request->endDate)->endOfDay());
        }

        // Admin filter by agent
        if ($request->filled('assignedTo')) {
            $query->where('assignedTo', $request->assignedTo);
        }

        if ($request->filled('eventType')) {
            $query->where('eventType', $request->eventType);
        }

        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $query->orderBy('startDateTime', 'asc');

        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 50;

        return $query->paginate($perPage);
    }

    /**
     * Create a new calendar event.
     */
    public function create(array $data): CalendarEvent
    {
        // Auto-assign color if not provided
        if (empty($data['color']) && ! empty($data['eventType'])) {
            $data['color'] = Constants::CALENDAR_EVENT_COLORS[$data['eventType']] ?? '#6B7280';
        }

        return CalendarEvent::create($data);
    }

    /**
     * Find a calendar event by ID.
     */
    public function findById(int $id): ?CalendarEvent
    {
        $query = CalendarEvent::query()->with(['assignedUser', 'creator']);

        if (isAgent()) {
            $query->where(function ($q) {
                $q->where('createdBy', user()->id)
                    ->orWhere('assignedTo', user()->id);
            });
        }

        return $query->find($id);
    }

    /**
     * Update a calendar event.
     */
    public function update(int $id, array $data): ?CalendarEvent
    {
        $event = $this->findById($id);

        if (! $event) {
            return null;
        }

        $event->update($data);

        return $event->fresh(['assignedUser', 'creator']);
    }

    /**
     * Reschedule a calendar event (drag & drop).
     */
    public function reschedule(int $id, string $startDateTime, ?string $endDateTime = null): ?CalendarEvent
    {
        $event = $this->findById($id);

        if (! $event) {
            return null;
        }

        $event->update([
            'startDateTime' => $startDateTime,
            'endDateTime' => $endDateTime,
        ]);

        // If event is linked to a task, update the task dueDate too
        if ($event->taskId) {
            Task::where('id', $event->taskId)
                ->update(['dueDate' => Carbon::parse($startDateTime)->toDateString()]);
        }

        return $event->fresh(['assignedUser', 'creator']);
    }

    /**
     * Delete a calendar event.
     */
    public function delete(int $id): bool
    {
        $event = $this->findById($id);

        if (! $event) {
            return false;
        }

        return (bool) $event->delete();
    }
}
