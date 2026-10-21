<?php

namespace App\Repositories;

use App\Models\AssignTeamMember;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;

class TeamRepository
{
    public function getAll(Request $request)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        $query = Team::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $query->with(['members:id,teamId,userId', 'members.user:id,name,roleId', 'members.user.role:id,name'])
            ->orderBy('id', 'desc');

        return $query->paginate($perPage);
    }

    public function create(array $data)
    {
        return Team::create([
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);
    }

    public function findById(int $id)
    {
        return Team::with(['members:id,teamId,userId,createdBy,created_at', 'members.user:id,name,email,phone,roleId', 'members.user.role:id,name', 'members.creator:id,name'])->find($id);
    }

    public function update(int $id, array $data)
    {
        $team = Team::find($id);

        if (! $team) {
            return null;
        }

        $team->update([
            'name' => $data['name'] ?? $team->name,
            'description' => $data['description'] ?? $team->description,
            'status' => $data['status'] ?? $team->status,
        ]);

        return $team;
    }

    public function delete(int $id): bool
    {
        $team = Team::find($id);

        if (! $team) {
            return false;
        }

        return (bool) $team->delete();
    }

    public function getMembers(int $teamId)
    {
        return AssignTeamMember::where('teamId', $teamId)
            ->with('user:id,name')
            ->get();
    }

    public function addMembers(int $teamId, array $userIds, ?int $createdBy = null): void
    {
        foreach ($userIds as $userId) {
            AssignTeamMember::updateOrCreate(
                ['teamId' => $teamId, 'userId' => $userId],
                ['createdBy' => $createdBy]
            );
        }
    }

    public function removeMembersByUserIds(int $teamId, array $userIds): int
    {
        return AssignTeamMember::where('teamId', $teamId)
            ->whereIn('userId', $userIds)
            ->delete();
    }

    public function getUnassignedAgents(Request $request)
    {
        $query = User::select('id', 'name')
            ->whereHas('role', function ($q) {
                $q->where('name', 'Agent');
            })
            ->whereNotIn('id', AssignTeamMember::pluck('userId'));

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }
        $query->where('status', 'active');

        return $query->get();
    }
}
