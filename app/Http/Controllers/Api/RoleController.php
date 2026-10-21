<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource\GetAllRoleResource;
use App\Http\Resources\RoleResource\GetRoleResource;
use App\Services\RoleService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RoleController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected RoleService $roleService
    ) {}

    private function parsePermissions($permissions)
    {
        if (is_array($permissions)) {
            return $permissions;
        }

        if (is_string($permissions)) {
            $decoded = json_decode($permissions, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    public function selectRoles(Request $request)
    {
        try {
            $includeAgent = filter_var($request->query('includeAgent'), FILTER_VALIDATE_BOOLEAN);
            $roles = $this->roleService->selectRoles($includeAgent);

            return $this->success($roles, 'Roles retrieved successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('RoleController : selectRoles', $e);
        }
    }

    public function roles(Request $request)
    {
        try {
            return $this->success(
                GetAllRoleResource::collection(
                    $this->roleService->listRoles($request)
                ),
                'Roles retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('RoleController : roles', $e);
        }
    }

    public function addRole(Request $request)
    {
        $requestData = $request->all();

        if (isset($requestData['permissions'])) {
            $requestData['permissions'] = $this->parsePermissions($requestData['permissions']);
        }

        $validator = Validator::make($requestData, [
            'name' => 'required|string|unique:roles,name',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:'.implode(',', array_keys(Constants::ROLE_STATUS)),
            'permissions' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $data = $validator->validated();

            if (isset($data['status'])) {
                $data['status'] = strtolower($data['status']);
            }

            $permissionIds = [];
            if (! empty($data['permissions'])) {
                $firstItem = $data['permissions'][0];
                if (is_numeric($firstItem)) {
                    $permissionIds = $data['permissions'];
                } elseif (is_array($firstItem) && isset($firstItem['module'])) {
                    $permissionNames = Permissions::convertRequestPermissionsToNames($data['permissions']);
                    $permissionIds = $this->roleService->getPermissionIdsByNames($permissionNames);
                }
            }

            unset($data['permissions']);
            $data['createdBy'] = user()->id;
            $role = $this->roleService->createRoles($data, $permissionIds);

            $params = [
                'action' => 'created',
                'module' => Constants::MODULE_ROLE,
                'moduleId' => $role->id,
                'description' => "Created role: {$role->name}",
            ];
            logUserActivity($params);

            return $this->success([
                'id' => $role->id,
                'name' => $role->name,
                'status' => $role->status,
                'description' => $role->description,
                'permissions' => $requestData['permissions'] ?? [],
                'all_permissions' => 0,
            ], 'Role created successfully');

        } catch (\Exception $e) {
            return $this->exceptionResponse('RoleController : addRole', $e);
        }
    }

    public function updateRole(Request $request, $id)
    {
        $requestData = $request->all();

        if (isset($requestData['permissions'])) {
            $requestData['permissions'] = $this->parsePermissions($requestData['permissions']);
        }

        $validator = Validator::make($requestData, [
            'name' => 'sometimes|required|string|unique:roles,name,'.$id,
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:'.implode(',', array_keys(Constants::ROLE_STATUS)),
            'permissions' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $role = $this->roleService->findRole($id);
            if (! $role) {
                return $this->error('The requested role was not found.', 422);
            }

            $data = $validator->validated();

            $protectedRoles = ['SuperAdmin', 'Manager', 'Agent', 'HR'];
            if (isset($data['name']) && in_array($role->name, $protectedRoles)) {
                unset($data['name']);
            }

            if (isset($data['status'])) {
                $data['status'] = strtolower($data['status']);
            }
            $permissionIds = [];
            $hasPermissions = isset($requestData['permissions']);
            if (! empty($requestData['permissions'])) {
                $firstItem = $requestData['permissions'][0];
                if (is_numeric($firstItem)) {
                    $permissionIds = $requestData['permissions'];
                } elseif (is_array($firstItem) && isset($firstItem['module'])) {
                    $permissionNames = Permissions::convertRequestPermissionsToNames($requestData['permissions']);
                    $permissionIds = $this->roleService->getPermissionIdsByNames($permissionNames);
                }
            }

            unset($data['permissions']);

            $role = $this->roleService->updateRoles($role, $data, $permissionIds, $hasPermissions);

            $params = [
                'action' => 'updated',
                'module' => Constants::MODULE_ROLE,
                'moduleId' => $role->id,
                'description' => "Updated role: {$role->name}",
            ];
            logUserActivity($params);

            return $this->success([
                'id' => $role->id,
                'name' => $role->name,
                'status' => $role->status,
                'description' => $role->description,
                'permissions' => $requestData['permissions'] ?? [],
                'all_permissions' => 0,
            ], 'Role updated successfully');

        } catch (\Exception $e) {
            return $this->exceptionResponse('RoleController : updateRole', $e);
        }
    }

    public function getRoleById($id)
    {
        try {
            $role = $this->roleService->findRole($id);

            if (! $role) {
                return $this->error('The requested role was not found.', 422);
            }

            return $this->success(new GetRoleResource($role->load('permissions')), 'Role retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('RoleController : getRoleById', $e);
        }
    }

    public function deleteRole(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:roles,id',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $protectedRoles = ['SuperAdmin', 'Manager', 'Agent', 'HR'];
            $deletedRoles = [];
            $skippedRoles = [];

            foreach ($request->ids as $id) {
                $role = $this->roleService->findRole($id);

                if (! $role) {
                    $skippedRoles[] = "Role #{$id} not found";

                    continue;
                }

                if (in_array($role->name, $protectedRoles)) {
                    $skippedRoles[] = "{$role->name} cannot be deleted";

                    continue;
                }

                $roleName = $role->name;
                $roleId = $role->id;
                $this->roleService->deleteRole($role);

                $params = [
                    'action' => 'deleted',
                    'module' => Constants::MODULE_ROLE,
                    'moduleId' => $roleId,
                    'description' => "Deleted role: {$roleName}",
                ];
                logUserActivity($params);

                $deletedRoles[] = $roleName;
            }

            return $this->success([
                'deleted' => $deletedRoles,
                'skipped' => $skippedRoles,
            ], count($deletedRoles).' role(s) deleted successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('RoleController : deleteRole', $e);
        }
    }
}
