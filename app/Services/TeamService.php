<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\Team;
use App\Repositories\TeamRepository;
use Illuminate\Http\Request;

class TeamService
{
    public function __construct(
        protected TeamRepository $teamRepository
    ) {}

    public function selectTeams(?Request $request = null)
    {
        $query = Team::where('status', 'active')->select('id', 'name');

        $forceAllTeam = filter_var($request?->query('forceAllTeam'), FILTER_VALIDATE_BOOLEAN);

        if (! $forceAllTeam) {
            if (isAgent()) {
                $query->whereHas('members', function ($q) {
                    $q->where('userId', user()->id);
                });
            }
            if (isManager()) {
                $query->whereHas('members', function ($q) {
                    $q->where('userId', user()->id);
                });
            }
        }

        $minAgent = $request?->query('minAgent');
        if ($minAgent !== null && $minAgent !== '') {
            $query->whereHas('members', function ($q) {
                $q->whereHas('user.role', fn ($r) => $r->where('name', Constants::AGENT));
            }, '>=', (int) $minAgent);
        }

        return $query->get();
    }

    public function getAll(Request $request)
    {
        return $this->teamRepository->getAll($request);
    }

    public function create(array $data)
    {
        return $this->teamRepository->create($data);
    }

    public function getById(int $id)
    {
        return $this->teamRepository->findById($id);
    }

    public function update(int $id, array $data)
    {
        return $this->teamRepository->update($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->teamRepository->delete($id);
    }

    public function getMembers(int $teamId)
    {
        return $this->teamRepository->getMembers($teamId);
    }

    public function addMembers(int $teamId, array $userIds, ?int $createdBy = null): void
    {
        $this->teamRepository->addMembers($teamId, $userIds, $createdBy);
    }

    public function removeMembersByUserIds(int $teamId, array $userIds): int
    {
        return $this->teamRepository->removeMembersByUserIds($teamId, $userIds);
    }

    public function getUnassignedAgents(Request $request)
    {
        return $this->teamRepository->getUnassignedAgents($request);
    }
}
