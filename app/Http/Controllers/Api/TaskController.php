<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResources\TaskResource;
use App\Models\AssignTeamMember;
use App\Models\Task;
use App\Services\MailService;
use App\Services\NotificationService;
use App\Services\PropertyService;
use App\Services\TaskService;
use App\Traits\ApiResponse;
use App\Traits\WordFormatter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;

class TaskController extends Controller
{
    use ApiResponse, WordFormatter;

    public function __construct(
        protected TaskService $taskService,
        protected PropertyService $propertyService,
        protected MailService $mailService,
        protected NotificationService $notificationService
    ) {}

    public function addTask(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required',
            'moduleId' => 'nullable',
            'module' => 'nullable',
            'assignedTo' => 'nullable',
            'assignmentType' => 'nullable|in:team,individual',
            'type' => 'nullable|in:'.implode(',', array_keys(Constants::TASK_TYPE)),
            'dueDate' => 'nullable',
            'endDate' => 'nullable',
            'startTime' => 'nullable|string|max:20',
            'endTime' => 'nullable|string|max:20',
            'reminderSet' => 'nullable',
            'reminderDate' => 'nullable',
            'reminderTime' => 'nullable',
            'comments' => 'nullable',
            'priority' => 'nullable|in:'.implode(',', array_keys(Constants::TASK_PRIORITY)),
            'status' => 'nullable|in:'.implode(',', array_keys(Constants::TASK_STATUS)),
            'isQualified' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $validatedData = $validator->validated();
            $assignmentType = $validatedData['assignmentType'] ?? 'individual';
            unset($validatedData['assignmentType']);
            $validatedData['createdBy'] = user()->id;

            if (! empty($validatedData['reminderDate']) && ! empty($validatedData['reminderTime'])) {
                $utc = localDateTimeToUtc($validatedData['reminderDate'], $validatedData['reminderTime']);
                $validatedData['reminderDate'] = $utc['date'];
                $validatedData['reminderTime'] = $utc['time'];
            }

            $this->normalizeTaskEventTimes($validatedData);

            if ($assignmentType === 'team') {
                if (empty($validatedData['assignedTo'])) {
                    return $this->error('Validation failed', 422, ['assignedTo' => ['Team id is required when assignmentType is team.']]);
                }

                // assignedTo may hold one or more team ids (comma-separated string
                // or array) so the same task can be assigned to several teams at
                // once. Gather the active agents across all selected teams.
                $teamIds = $this->parseAssignedIds($validatedData['assignedTo']);

                $memberIds = AssignTeamMember::whereIn('teamId', $teamIds)
                    ->whereHas('user', fn ($q) => $q->where('status', Constants::USER_STATUS_ACTIVE)
                        ->whereHas('role', fn ($r) => $r->where('name', Constants::AGENT)))
                    ->pluck('userId')
                    ->unique()
                    ->values()
                    ->all();

                if (empty($memberIds)) {
                    return $this->error('Selected team(s) have no active agents assigned.', 422);
                }

                return $this->createTasksForUsers($validatedData, $memberIds);
            }

            // Individual assignment: assignedTo may name several users (comma-separated
            // string or array) — create one task per user. A single (or no) assignee
            // falls through to the single-task create below.
            $userIds = $this->parseAssignedIds($validatedData['assignedTo'] ?? null);

            if (count($userIds) > 1) {
                return $this->createTasksForUsers($validatedData, $userIds);
            }

            if (! empty($userIds)) {
                $validatedData['assignedTo'] = $userIds[0];
            }

            $task = $this->taskService->create($validatedData);
            $task->load('assignedUser.role', 'creator.role');

            logUserActivity([
                'action' => 'created',
                'module' => Constants::MODULE_TASK,
                'moduleId' => $task->id,
                'description' => "Created task: {$task->subject}",
            ]);

            $this->logTaskModuleHistory($validatedData, $task);

            return $this->success(
                new TaskResource($task),
                'Task created successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('TaskController : addTask', $e);
        }
    }

    protected function logTaskModuleHistory(array $validatedData, Task $task): void
    {
        $module = $validatedData['module'] ?? null;
        $moduleId = $validatedData['moduleId'] ?? null;

        if (! $module || empty($moduleId)) {
            return;
        }

        if ($module === Constants::MODULE_PROPERTY) {
            logPropertyHistory([
                'propertyId' => $moduleId,
                'field' => 'task_created',
                'newValue' => json_encode(['message' => "Task created: {$task->subject}"]),
            ]);

            return;
        }

        if ($module !== Constants::MODULE_ENQUIRY) {
            return;
        }

        logEnquiryHistory([
            'enquiryId' => $moduleId,
            'field' => 'task_created',
            'originalValue' => null,
            'newValue' => json_encode(['message' => "Task created: {$task->subject}"]),
        ]);

    }

    protected function normalizeTaskEventTimes(array &$data): void
    {
        foreach (['startTime', 'endTime'] as $field) {
            if (array_key_exists($field, $data) && ! empty($data[$field])) {
                $data[$field] = Carbon::parse($data[$field])->format('H:i:s');
            }
        }
    }

    /**
     * Parse an assignedTo value (comma-separated string or array) into a clean,
     * de-duplicated list of ids. Used for both team ids and individual user ids.
     *
     * @return array<int, string>
     */
    protected function parseAssignedIds($value): array
    {
        if (is_array($value)) {
            $value = implode(',', $value);
        }

        $ids = array_map('trim', explode(',', (string) $value));

        return array_values(array_unique(array_filter($ids, fn ($id) => $id !== '')));
    }

    /**
     * Create one task per user id (notifications deferred to a queued job) and
     * return the collection response. Shared by the team assignment path and the
     * multi-user individual assignment path.
     */
    protected function createTasksForUsers(array $validatedData, array $userIds)
    {
        $tasks = $this->taskService->createForUsers($validatedData, $userIds);

        foreach ($tasks as $task) {
            $task->load('assignedUser.role', 'creator.role');
        }

        $this->logTaskModuleHistory($validatedData, $tasks[0]);

        return $this->success(
            TaskResource::collection(collect($tasks)),
            'Tasks created successfully'
        );
    }

    public function getTasks(Request $request)
    {
        try {
            // Two nodes so the grouped list UI can render its Overdue section
            // without re-deriving "overdue" client-side, and so each node carries
            // its own totals instead of counts that only describe the current page.
            ['overdue' => $overdue, 'tasks' => $tasks] = $this->taskService->getAll($request);

            return response()->json([
                'status' => true,
                'message' => 'Tasks retrieved successfully',
                'data' => [
                    'overdue' => TaskResource::collection($overdue->items()),
                    'tasks' => TaskResource::collection($tasks->items()),
                ],
                'meta' => [
                    'overdue' => $this->paginationMeta($overdue),
                    'tasks' => $this->paginationMeta($tasks),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->exceptionResponse('TaskController : getTasks', $e);
        }
    }

    public function getTaskById(int $id)
    {
        try {
            $task = $this->taskService->getById($id);

            if (! $task) {
                return $this->error('The requested task was not found.', 422);
            }

            return $this->success(
                new TaskResource($task),
                'Task retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('TaskController : getTaskById', $e);
        }
    }

    public function updateTask(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'sometimes|required',
            'moduleId' => 'nullable',
            'module' => 'nullable',
            'assignedTo' => 'nullable',
            'type' => 'nullable|in:'.implode(',', array_keys(Constants::TASK_TYPE)),
            'dueDate' => 'nullable',
            'endDate' => 'nullable',
            'startTime' => 'nullable|string|max:20',
            'endTime' => 'nullable|string|max:20',
            'reminderSet' => 'nullable',
            'reminderEnabled' => 'nullable',
            'reminderDate' => 'nullable',
            'reminderTime' => 'nullable',
            'comments' => 'nullable',
            'priority' => 'nullable|in:'.implode(',', array_keys(Constants::TASK_PRIORITY)),
            'status' => 'nullable|in:'.implode(',', array_keys(Constants::TASK_STATUS)),
            'isQualified' => 'nullable|boolean',
            'createdBy' => 'nullable',
        ]);
        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $validatedData = $validator->validated();

            if (! empty($validatedData['reminderDate']) && ! empty($validatedData['reminderTime'])) {
                $utc = localDateTimeToUtc($validatedData['reminderDate'], $validatedData['reminderTime']);
                $validatedData['reminderDate'] = $utc['date'];
                $validatedData['reminderTime'] = $utc['time'];
            }

            $this->normalizeTaskEventTimes($validatedData);

            $task = $this->taskService->update($id, $validatedData);

            if (! $task) {
                return $this->error('The requested task was not found.', 422);
            }

            $params = [
                'action' => 'updated',
                'module' => Constants::MODULE_TASK,
                'moduleId' => $task->id,
                'description' => "Updated task: {$task->subject}",
            ];
            logUserActivity($params);
            $taskLabel = Constants::TASK_STATUS[$task->status];
            if ($task->module === Constants::MODULE_PROPERTY && $task->moduleId) {
                logPropertyHistory([
                    'propertyId' => $task->moduleId,
                    'field' => 'task_updated',
                    'newValue' => json_encode(['message' => "Task is changed to {$taskLabel}"]),
                ]);
            } elseif ($task->module === Constants::MODULE_ENQUIRY && $task->moduleId) {
                logEnquiryHistory([
                    'enquiryId' => $task->moduleId,
                    'field' => 'task_updated',
                    'originalValue' => null,
                    'newValue' => json_encode(['message' => "Task is changed to {$taskLabel}"]),
                ]);
            }

            return $this->success(
                new TaskResource($task),
                'Task updated successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('TaskController : updateTask', $e);
        }
    }

    public function deleteTask(int $id)
    {
        try {
            $task = $this->taskService->getById($id);
            if (! $task) {
                return $this->error('The requested task was not found.', 422);
            }

            $taskSubject = $task->subject;
            $taskModule = $task->module;
            $taskModuleId = $task->moduleId;
            $this->taskService->delete($id);

            $params = [
                'action' => 'deleted',
                'module' => Constants::MODULE_TASK,
                'moduleId' => $id,
                'description' => "Deleted task: {$taskSubject}",
            ];
            logUserActivity($params);

            if ($taskModule === Constants::MODULE_PROPERTY && $taskModuleId) {
                logPropertyHistory([
                    'propertyId' => $taskModuleId,
                    'field' => 'task_deleted',
                    'originalValue' => json_encode(['message' => "Task: {$taskSubject}"]),
                    'newValue' => json_encode(['message' => 'Task deleted']),
                ]);
            } elseif ($taskModule === Constants::MODULE_ENQUIRY && $taskModuleId) {
                logEnquiryHistory([
                    'enquiryId' => $taskModuleId,
                    'field' => 'task_deleted',
                    'originalValue' => json_encode(['message' => "Task: {$taskSubject}"]),
                    'newValue' => json_encode(['message' => 'Task deleted']),
                ]);
            }

            return $this->success([], 'Task deleted successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('TaskController : deleteTask', $e);
        }
    }

    public function getCalendarEvents(Request $request)
    {
        try {
            $query = Task::query()
                ->with([
                    'assignedUser:id,name,roleId',
                    'assignedUser.role:id,name',
                    'creator:id,name,roleId',
                    'creator.role:id,name',
                ]);

            if (isAgent()) {
                $query->where(function ($q) {
                    $q->where('createdBy', user()->id)
                        ->orWhere('assignedTo', user()->id);
                });
            }

            // My Schedule shows what's still pending — completed ("done") tasks
            // are dropped so they don't clutter the calendar.
            $query->where('status', '!=', Constants::TASK_STATUS_DONE);

            if ($request->filled('startDate')) {
                // Anchor the range on whichever of dueDate/endDate is later. Rescheduling
                // moves dueDate forward without touching endDate (follow-up clones and
                // calendar drags both do this), so a plain COALESCE(endDate, dueDate)
                // judged those tasks on a stale past endDate and dropped them from the
                // month they are actually due in.
                $query->whereRaw('GREATEST(dueDate, COALESCE(endDate, dueDate)) >= ?', [$request->startDate]);
            }

            if ($request->filled('endDate')) {
                $query->where('dueDate', '<=', $request->endDate);
            }

            if ($request->filled('assignedTo')) {
                $query->where('assignedTo', $request->assignedTo);
            }

            if ($request->filled('teamId')) {
                $memberIds = AssignTeamMember::where('teamId', $request->teamId)
                    ->pluck('userId')
                    ->all();
                $query->whereIn('assignedTo', $memberIds ?: [0]);
            }

            if ($request->filled('type')) {
                $types = is_array($request->type)
                    ? $request->type
                    : array_filter(array_map('trim', explode(',', $request->type)));
                $query->whereIn('type', $types);
            }

            if ($request->filled('linkedModule')) {
                $query->where('module', $request->linkedModule);
            }

            $query->orderBy('dueDate', 'asc')
                ->orderByRaw('startTime IS NULL')
                ->orderBy('startTime', 'asc')
                ->orderByDesc('id');

            $tasks = ($request->filled('per_page') && (int) $request->per_page > 0)
                ? $query->paginate((int) $request->per_page)
                : $query->get();

            $events = $tasks instanceof LengthAwarePaginator ? $tasks->getCollection() : $tasks;

            $events->each(function ($task) {
                // Attach the client contact (physical owner / enquiry contact / buyer,
                // depending on each task's source module) to every calendar event.
                $task->append('client');

                // Only Meetings and Viewings run across a date range; every other task
                // type is a single-day follow-up, so the key is dropped for those —
                // mirrors TaskResource.
                if (! in_array($task->type, ['meeting', 'viewing'], true)) {
                    $task->makeHidden('endDate');
                }
            });

            return $this->success($tasks, 'Calendar events retrieved successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('TaskController : getCalendarEvents', $e);
        }
    }
}
