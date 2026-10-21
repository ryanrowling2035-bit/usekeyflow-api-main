<?php

namespace Database\Seeders;

use App\Constants\Constants;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $roles = [
            [
                'name' => 'SuperAdmin',
                'status' => Constants::ROLE_STATUS['active'],
            ],
            [
                'name' => 'Manager',
                'status' => Constants::ROLE_STATUS['active'],
            ],
            [
                'name' => 'Agent',
                'status' => Constants::ROLE_STATUS['active'],
            ],
            [
                'name' => 'HR',
                'status' => Constants::ROLE_STATUS['active'],
            ],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role['name']],
                array_merge($role, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }
}
