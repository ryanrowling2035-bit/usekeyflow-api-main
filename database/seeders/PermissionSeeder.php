<?php

namespace Database\Seeders;

use App\Constants\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $permissionsData = Permissions::all();
        $existingPermissions = DB::table('permissions')->pluck('name')->toArray();
        $newCount = 0;

        // Insert only new permissions (never delete existing ones)
        foreach ($permissionsData as $permission) {
            if (! in_array($permission['name'], $existingPermissions)) {
                DB::table('permissions')->insert([
                    'name' => $permission['name'],
                    'module' => $permission['module'],
                    'action' => $permission['action'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $newCount++;
            }
        }

        // Sync role permissions from MAP — only add missing ones, never remove existing
        foreach (Permissions::MAP as $roleName => $modules) {
            $role = DB::table('roles')->where('name', $roleName)->first();

            // The role row may not exist yet (e.g. this seeder run on its own,
            // before RoleSeeder). Skip rather than fataling on $role->id.
            if (! $role) {
                continue;
            }

            $existingRolePermissionIds = DB::table('role_permissions')
                ->where('roleId', $role->id)
                ->pluck('permissionId')
                ->toArray();

            foreach ($modules as $moduleKey => $moduleData) {
                if (isset($moduleData['roles']) && is_array($moduleData['roles'])) {
                    foreach ($moduleData['roles'] as $permissionName) {
                        $permission = DB::table('permissions')->where('name', $permissionName)->first();

                        if ($permission && ! in_array($permission->id, $existingRolePermissionIds)) {
                            DB::table('role_permissions')->insert([
                                'roleId' => $role->id,
                                'permissionId' => $permission->id,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                            $existingRolePermissionIds[] = $permission->id;
                        }
                    }
                }
            }
        }
    }
}
