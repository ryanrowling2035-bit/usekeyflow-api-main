<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarResources\CalendarEventResource;
use App\Services\CalendarService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CalendarController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected CalendarService $calendarService,
    ) {}

    public function getEvents(Request $request)
    {
        try {
            $events = $this->calendarService->getAll($request);

            return $this->success(
                CalendarEventResource::collection($events),
                'Calendar events retrieved successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('CalendarController : getEvents', $e);
        }
    }

    public function createEvent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'eventType' => 'nullable|in:'.implode(',', array_keys(Constants::CALENDAR_EVENT_TYPES)),
            'startDateTime' => 'nullable|date',
            'endDateTime' => 'nullable|date|after_or_equal:startDateTime',
            'allDay' => 'nullable|boolean',
            'location' => 'nullable|string|max:255',
            'module' => 'nullable|in:'.implode(',', array_keys(Constants::CALENDAR_MODULES)),
            'moduleId' => 'nullable|integer',
            'taskId' => 'nullable|integer|exists:tasks,id',
            'assignedTo' => 'nullable|integer|exists:users,id',
            'status' => 'nullable|in:'.implode(',', array_keys(Constants::CALENDAR_EVENT_STATUS)),
            'priority' => 'nullable|in:'.implode(',', array_keys(Constants::TASK_PRIORITY)),
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'reminderSet' => 'nullable|boolean',
            'reminderDate' => 'nullable|date',
            'reminderTime' => 'nullable',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $data = array_filter($validator->validated(), fn ($value) => ! is_null($value));
            $data['createdBy'] = user()->id;

            if (empty($data['startDateTime'])) {
                $data['startDateTime'] = now();
            }

            if (empty($data['assignedTo'])) {
                $data['assignedTo'] = user()->id;
            }

            $event = $this->calendarService->create($data);
            $event->load('assignedUser', 'creator');

            $params = [
                'action' => 'created',
                'module' => Constants::MODULE_CALENDAR_EVENT,
                'moduleId' => $event->id,
                'description' => "Created calendar event: {$event->title}",
            ];
            logUserActivity($params);

            return $this->success(
                new CalendarEventResource($event),
                'Calendar event created successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('CalendarController : createEvent', $e);
        }
    }

    public function getEventById(int $id)
    {
        try {
            $event = $this->calendarService->getById($id);

            if (! $event) {
                return $this->error('The requested calendar event was not found.', 422);
            }

            return $this->success(
                new CalendarEventResource($event),
                'Calendar event retrieved successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('CalendarController : getEventById', $e);
        }
    }

    public function updateEvent(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'eventType' => 'nullable|in:'.implode(',', array_keys(Constants::CALENDAR_EVENT_TYPES)),
            'startDateTime' => 'sometimes|nullable|date',
            'endDateTime' => 'nullable|date|after_or_equal:startDateTime',
            'allDay' => 'nullable|boolean',
            'location' => 'nullable|string|max:255',
            'module' => 'nullable|in:'.implode(',', array_keys(Constants::CALENDAR_MODULES)),
            'moduleId' => 'nullable|integer',
            'taskId' => 'nullable|integer|exists:tasks,id',
            'assignedTo' => 'nullable|integer|exists:users,id',
            'status' => 'nullable|in:'.implode(',', array_keys(Constants::CALENDAR_EVENT_STATUS)),
            'priority' => 'nullable|in:'.implode(',', array_keys(Constants::TASK_PRIORITY)),
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'reminderSet' => 'nullable|boolean',
            'reminderDate' => 'nullable|date',
            'reminderTime' => 'nullable',
        ]);
        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $data = array_filter($validator->validated(), fn ($value) => ! is_null($value));

            if (empty($data['startDateTime']) && $request->has('startDateTime')) {
                $data['startDateTime'] = now();
            }

            $event = $this->calendarService->update($id, $data);

            if (! $event) {
                return $this->error('The requested calendar event was not found.', 422);
            }

            $params = [
                'action' => 'updated',
                'module' => Constants::MODULE_CALENDAR_EVENT,
                'moduleId' => $event->id,
                'description' => "Updated calendar event: {$event->title}",
            ];
            logUserActivity($params);

            return $this->success(
                new CalendarEventResource($event),
                'Calendar event updated successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('CalendarController : updateEvent', $e);
        }
    }

    public function rescheduleEvent(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'startDateTime' => 'required|date',
            'endDateTime' => 'nullable|date|after_or_equal:startDateTime',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $event = $this->calendarService->reschedule(
                $id,
                $request->startDateTime,
                $request->endDateTime
            );

            if (! $event) {
                return $this->error('The requested calendar event was not found.', 422);
            }

            $params = [
                'action' => 'rescheduled',
                'module' => Constants::MODULE_CALENDAR_EVENT,
                'moduleId' => $event->id,
                'description' => "Rescheduled calendar event: {$event->title}",
            ];
            logUserActivity($params);

            return $this->success(
                new CalendarEventResource($event),
                'Calendar event rescheduled successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('CalendarController : rescheduleEvent', $e);
        }
    }

    public function deleteEvent(int $id)
    {
        try {
            $event = $this->calendarService->getById($id);

            if (! $event) {
                return $this->error('The requested calendar event was not found.', 422);
            }

            $eventTitle = $event->title;
            $this->calendarService->delete($id);

            $params = [
                'action' => 'deleted',
                'module' => Constants::MODULE_CALENDAR_EVENT,
                'moduleId' => $id,
                'description' => "Deleted calendar event: {$eventTitle}",
            ];
            logUserActivity($params);

            return $this->success([], 'Calendar event deleted successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('CalendarController : deleteEvent', $e);
        }
    }

    public function getEventTypes()
    {
        try {
            $types = $this->calendarService->getEventTypes();

            return $this->success($types, 'Event types retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('CalendarController : getEventTypes', $e);
        }
    }
}
