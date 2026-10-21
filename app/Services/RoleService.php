<?php

namespace App\Services;

use App\Constants\Constants;
use App\Exceptions\UserFacingException;
use App\Models\Permission;
use App\Models\Role;
use App\Repositories\RoleRepository;
use Illuminate\Http\Request;

class RoleService
{
    public function __construct(
        protected RoleRepository $roleRepository
    ) {}

    // ================= selectRoles =================
    public function selectRoles(bool $includeAgent = false)
    {
        $query = Role::where('status', Constants::ROLE_STATUS['active'])
            ->select('id', 'name');

        if (! $includeAgent) {
            $query->where('name', '!=', Constants::AGENT);
        }

        return $query->get();
    }

    // ================= listRoles =================
    public function listRoles(Request $request)
    {
        return $this->roleRepository->getAll($request);
    }

    // ================= findRole =================
    public function findRole(int $id)
    {
        return $this->roleRepository->findById($id);
    }

    // ================= createRoles =================
    public function createRoles(array $data, array $permissions = [])
    {
        $role = $this->roleRepository->create($data);
        // Attach permissions if provided
        if (! empty($permissions)) {
            $role->permissions()->sync($permissions);
        }

        return $role;
    }

    // ================= updateRoles =================

    // public function updateRoles(Role $role, array $data, array $permissions = [])
    // {
    //     $role = $this->roleRepository->update($role, $data);
    //     // Update permissions if provided
    //     if (! empty($permissions)) {
    //         $role->permissions()->sync($permissions);
    //     }

    //     return $role;
    // }
    public function updateRoles(Role $role, array $data, array $permissionIds = [], bool $hasPermissions = false)
    {
        $role = $this->roleRepository->update($role, $data);

        // Sync permissions — even if empty (to allow removing all)
        if ($hasPermissions) {
            $role->permissions()->sync($permissionIds);
        }

        return $role;
    }

    /**
     * Convert permission names to permission IDs
     */
    public function getPermissionIdsByNames(array $permissionNames): array
    {
        return Permission::whereIn('name', $permissionNames)
            ->pluck('id')
            ->toArray();
    }

    // ================= deleteRole =================
    public function deleteRole(Role $role)
    {
        if ($role->id === 1) {
            throw new UserFacingException('Super Admin cannot be deleted');
        }

        return $this->roleRepository->delete($role);
    }
}
