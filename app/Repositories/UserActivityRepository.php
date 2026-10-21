<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\UserActivity;
use Illuminate\Http\Request;

class UserActivityRepository
{
    public function create(array $data)
    {
        return UserActivity::create($data);
    }

    public function getAll(Request $request)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        $query = UserActivity::with(['user:id,name,email,profilePhoto,thumbnailPath']);

        if (isAgent()) {
            $query->where('userId', user()->id);
        } elseif ($request->filled('userId')) {
            $query->where('userId', $request->userId);
        }

        // Accept either param name — the frontend sends `roleId`, older callers
        // may send `userRole`. Whichever is present filters activities to users
        // holding that role.
        $roleFilter = $request->filled('roleId')
            ? $request->roleId
            : ($request->filled('userRole') ? $request->userRole : null);

        if ($roleFilter) {
            $query->whereHas('user', function ($q) use ($roleFilter) {
                $q->where('roleId', $roleFilter);
            });
        }

        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }

        if ($request->filled('moduleId')) {
            $query->where('moduleId', $request->moduleId);
        }

        if ($request->filled('startDate')) {
            $query->where('created_at', '>=', $request->startDate.' 00:00:00');
        }

        if ($request->filled('endDate')) {
            $query->where('created_at', '<=', $request->endDate.' 23:59:59');
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'LIKE', "%{$search}%")
                    ->orWhere('module', 'LIKE', "%{$search}%")
                    ->orWhere('action', 'LIKE', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        // Sorting — allowlist the columns the UI exposes so we never order by an
        $sortOrder = strtolower((string) $request->sortOrder) === 'asc' ? 'asc' : 'desc';
        $sortBy = $request->filled('sortBy') ? (string) $request->sortBy : 'created_at';

        $allowedSortColumns = ['id', 'module', 'action', 'ipAddress', 'created_at'];

        if ($sortBy === 'user') {
            // Sort by the related user's name via a correlated subquery, so the
            $query->orderBy(
                User::select('name')->whereColumn('users.id', 'user_activities.userId'),
                $sortOrder
            );
        } elseif (in_array($sortBy, $allowedSortColumns, true)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        return $query->paginate($perPage);
    }
}
