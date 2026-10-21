<?php

namespace App\Repositories;

use App\Models\Role;
use Illuminate\Http\Request;

class RoleRepository
{
    public function getAll(Request $request)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        $query = Role::where('name', '!=', 'Agent');
        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhereRaw('LOWER(status) LIKE ?', ['%'.strtolower($search).'%']);
            });
        }

        if ($request->filled('id')) {
            $query->orderByRaw(
                'CASE WHEN id = ? THEN 0 ELSE 1 END',
                [$request->id]
            );
        }

        $query->orderBy('id', 'desc');

        return $query->paginate($perPage);
    }

    public function findById($id)
    {
        return Role::with('creator')->find($id);
    }

    public function create(array $data)
    {
        return Role::create($data);
    }

    public function update(Role $role, array $data)
    {
        $role->update($data);

        return $role;
    }

    public function delete(Role $role)
    {
        return $role->delete();
    }
}
