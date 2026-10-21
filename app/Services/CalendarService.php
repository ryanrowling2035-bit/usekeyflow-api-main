<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\CalendarEvent;
use App\Models\OutlookConnection;
use App\Repositories\CalendarRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CalendarService
{
    public function __construct(
        protected CalendarRepository $calendarRepository,
        protected OutlookCalendarService $outlookCalendarService,
    ) {}

    /**
     * Get all calendar events.
     */
    public function getAll(Request $request)
    {
        return $this->calendarRepository->getAll($request);
    }

    /**
     * Create a new calendar event.
     */
    public function create(array $data): CalendarEvent
    {
        $event = $this->calendarRepository->create($data);
        $this->syncToOutlook($event, 'create');

        return $event;
    }

    /**
     * Get a calendar event by ID.
     */
    public function getById(int $id): ?CalendarEvent
    {
        return $this->calendarRepository->findById($id);
    }

    /**
     * Update a calendar event.
     */
    public function update(int $id, array $data): ?CalendarEvent
    {
        $event = $this->calendarRepository->update($id, $data);

        if ($event) {
            $this->syncToOutlook($event, 'update');
        }

        return $event;
    }

    /**
     * Reschedule a calendar event (drag & drop).
     */
    public function reschedule(int $id, string $startDateTime, ?string $endDateTime = null): ?CalendarEvent
    {
        $event = $this->calendarRepository->reschedule($id, $startDateTime, $endDateTime);

        if ($event) {
            $this->syncToOutlook($event, 'update');
        }

        return $event;
    }

    /**
     * Delete a calendar event.
     */
    public function delete(int $id): bool
    {
        $event = CalendarEvent::find($id);

        // Delete from Outlook first if synced
        if ($event && $event->outlookEventId) {
            $connection = $this->getOutlookConnection($event->createdBy ?? $event->assignedTo);

            if ($connection) {
                $this->outlookCalendarService->deleteEventFromOutlook($event->outlookEventId, $connection);
            }
        }

        return $this->calendarRepository->delete($id);
    }

    /**
     * Get available event types with colors for frontend.
     */
    public function getEventTypes(): array
    {
        return array_map(function ($type) {
            return [
                'type' => $type,
                'color' => Constants::CALENDAR_EVENT_COLORS[$type] ?? '#6B7280',
            ];
        }, Constants::CALENDAR_EVENT_TYPES);
    }

    /**
     * Auto-sync a calendar event to Outlook (non-blocking).
     */
    protected function syncToOutlook(CalendarEvent $event, string $action): void
    {
        try {
            $userId = $event->createdBy ?? $event->assignedTo;
            $connection = $this->getOutlookConnection($userId);

            if (! $connection) {
                return;
            }

            if ($action === 'create') {
                $this->outlookCalendarService->pushEventToOutlook($event, $connection);
            } elseif ($action === 'update') {
                $this->outlookCalendarService->updateEventInOutlook($event, $connection);
            }
        } catch (\Exception $e) {
            // Don't fail the main operation if Outlook sync fails
            Log::warning('Outlook auto-sync failed', [
                'eventId' => $event->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the Outlook connection for a user.
     */
    protected function getOutlookConnection(?int $userId): ?OutlookConnection
    {
        if (! $userId) {
            return null;
        }

        return OutlookConnection::where('userId', $userId)
            ->where('status', Constants::OUTLOOK_SYNC_STATUS['connected'])
            ->first();
    }
}
