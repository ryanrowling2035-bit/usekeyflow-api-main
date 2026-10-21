<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResources\GetAllTeamResource;
use App\Http\Resources\TeamResources\GetTeamResource;
use App\Services\TeamService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TeamController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TeamService $teamService
    ) {}

    public function selectTeams(Request $request)
    {
        try {
            $teams = $this->teamService->selectTeams($request);

            return $this->success($teams, 'Teams retrieved successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('TeamController : selectTeams', $e);
        }
    }

    public function addTeam(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:'.implode(',', array_keys(Constants::USER_STATUS)),
            'managers' => 'nullable|array',
            'managers.*' => 'integer|exists:users,id',
            'agents' => 'nullable|array',
            'agents.*' => 'integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $team = $this->teamService->create($validator->validated());

            if ($request->has('managers')) {
                $this->teamService->addMembers($team->id, $request->managers, user()->id);
            }

            if ($request->has('agents')) {
                $this->teamService->addMembers($team->id, $request->agents, user()->id);
            }

            $params = [
                'action' => 'created',
                'module' => Constants::MODULE_TEAM,
                'moduleId' => $team->id,
                'description' => "Created team: {$team->name}",
            ];
            logUserActivity($params);

            return $this->success(new GetTeamResource($team->fresh()), 'Team created successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('TeamController : addTeam', $e);
        }
    }

    public function getTeams(Request $request)
    {
        try {
            $teams = $this->teamService->getAll($request);

            return $this->success(GetAllTeamResource::collection($teams), 'Teams retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('TeamController : getTeams', $e);
        }
    }

    public function getTeamById(int $id)
    {
        try {
            $team = $this->teamService->getById($id);

            if (! $team) {
                return $this->error('The requested team was not found.', 422);
            }

            return $this->success(new GetTeamResource($team), 'Team retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('TeamController : getTeamById', $e);
        }
    }

    public function updateTeam(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:'.implode(',', array_keys(Constants::USER_STATUS)),
            'managers' => 'nullable|array',
            'managers.*' => 'integer|exists:users,id',
            'deletedManagers' => 'nullable|array',
            'deletedManagers.*' => 'integer',
            'agents' => 'nullable|array',
            'agents.*' => 'integer|exists:users,id',
            'deletedAgents' => 'nullable|array',
            'deletedAgents.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $team = $this->teamService->update($id, $validator->validated());

            if (! $team) {
                return $this->error('The requested team was not found.', 422);
            }

            if ($request->has('managers')) {
                $this->teamService->addMembers($team->id, $request->managers, user()->id);
            }

            if ($request->has('deletedManagers')) {
                $this->teamService->removeMembersByUserIds($team->id, $request->deletedManagers);
            }

            if ($request->has('agents')) {
                $this->teamService->addMembers($team->id, $request->agents, user()->id);
            }

            if ($request->has('deletedAgents')) {
                $this->teamService->removeMembersByUserIds($team->id, $request->deletedAgents);
            }

            $params = [
                'action' => 'updated',
                'module' => Constants::MODULE_TEAM,
                'moduleId' => $team->id,
                'description' => "Updated team: {$team->name}",
            ];
            logUserActivity($params);

            return $this->success(new GetTeamResource($team->fresh()), 'Team updated successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('TeamController : updateTeam', $e);
        }
    }

    public function deleteTeam(int $id)
    {
        try {
            $team = $this->teamService->getById($id);

            if (! $team) {
                return $this->error('The requested team was not found.', 422);
            }

            $hasAgents = $team->members()->whereHas('user.role', function ($q) {
                $q->where('name', Constants::AGENT);
            })->exists();

            if ($hasAgents) {
                return $this->error('Cannot delete team because it has agents assigned to it.', 422);
            }

            $teamName = $team->name;
            $this->teamService->delete($id);

            $params = [
                'action' => 'deleted',
                'module' => Constants::MODULE_TEAM,
                'moduleId' => $id,
                'description' => "Deleted team: {$teamName}",
            ];
            logUserActivity($params);

            return $this->success([], 'Team deleted successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('TeamController : deleteTeam', $e);
        }
    }

    public function assignTeamMembers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'teamId' => 'required|integer|exists:teams,id',
            'agents' => 'nullable|array',
            'agents.*' => 'integer|exists:users,id',
            'deletedAgents' => 'nullable|array',
            'deletedAgents.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $teamId = $request->teamId;
            $actionType = [];
            if ($request->has('agents')) {
                $this->teamService->addMembers($teamId, $request->agents, user()->id);
                $actionType[] = 'assigned';
            }

            if ($request->has('deletedAgents')) {
                $this->teamService->removeMembersByUserIds($teamId, $request->deletedAgents);
                $actionType[] = 'removed';
            }

            $params = [
                'action' => 'updated',
                'module' => Constants::MODULE_TEAM,
                'moduleId' => $teamId,
                'description' => "Assigned/removed team members for team #{$teamId}",
            ];
            logUserActivity($params);

            return $this->success([], 'Team members assigned successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('TeamController : assignTeamMembers', $e);
        }
    }

    public function getTeamMembers(int $teamId)
    {
        try {
            $members = $this->teamService->getMembers($teamId);

            return $this->success($members, 'Team members retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('TeamController : getTeamMembers', $e);
        }
    }

    public function getUnassignedAgents(Request $request)
    {
        try {
            $agents = $this->teamService->getUnassignedAgents($request);

            return $this->success($agents, 'Unassigned agents retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('TeamController : getUnassignedAgents', $e);
        }
    }
}
