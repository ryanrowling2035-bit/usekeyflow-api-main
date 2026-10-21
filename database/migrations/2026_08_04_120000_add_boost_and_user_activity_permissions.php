<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Adds the Boost Center and User Activity permission rows (and grants them to
 * SuperAdmin) so both appear in the Add/Edit Role permission list — matching the
 * sidebar. This mirrors what PermissionSeeder does, packaged as a migration for
 * environments where seeders can't be run. Idempotent: only inserts what's missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        $permissions = [
            ['name' => 'view dashboard',        'module' => 'dashboard',     'action' => 'view'],
            ['name' => 'view boost',            'module' => 'boost',         'action' => 'view'],
            ['name' => 'create boost',          'module' => 'boost',         'action' => 'create'],
            ['name' => 'edit boost',            'module' => 'boost',         'action' => 'edit'],
            ['name' => 'delete boost',          'module' => 'boost',         'action' => 'delete'],
            ['name' => 'view user_activity',    'module' => 'user_activity', 'action' => 'view'],
        ];

        foreach ($permissions as $permission) {
            // Insert the permission only if it isn't already present.
            if (! DB::table('permissions')->where('name', $permission['name'])->exists()) {
                DB::table('permissions')->insert(array_merge($permission, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }

        // Grant them to SuperAdmin so the role actually holds the permissions.
        $superAdmin = DB::table('roles')->where('name', 'SuperAdmin')->first();

        if ($superAdmin) {
            $permissionIds = DB::table('permissions')
                ->whereIn('name', array_column($permissions, 'name'))
                ->pluck('id');

            foreach ($permissionIds as $permissionId) {
                $alreadyLinked = DB::table('role_permissions')
                    ->where('roleId', $superAdmin->id)
                    ->where('permissionId', $permissionId)
                    ->exists();

                if (! $alreadyLinked) {
                    DB::table('role_permissions')->insert([
                        'roleId' => $superAdmin->id,
                        'permissionId' => $permissionId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $names = ['view dashboard', 'view boost', 'create boost', 'edit boost', 'delete boost', 'view user_activity'];

        $permissionIds = DB::table('permissions')->whereIn('name', $names)->pluck('id');

        DB::table('role_permissions')->whereIn('permissionId', $permissionIds)->delete();
        DB::table('permissions')->whereIn('name', $names)->delete();
    }
};
