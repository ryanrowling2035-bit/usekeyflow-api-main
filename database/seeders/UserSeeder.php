<?php

namespace Database\Seeders;

use App\Constants\Constants;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $superAdminRoleId = DB::table('roles')->where('name', 'SuperAdmin')->value('id');

        DB::table('users')->updateOrInsert(
            ['email' => 'admin@gmail.com'],
            [
                'roleId' => $superAdminRoleId,
                'name' => 'Super Admin',
                'password' => Hash::make('12345678'),
                'status' => Constants::USER_STATUS['active'],
                'email_verified_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        // Set Super Admin as default admin
        $adminUserId = DB::table('users')->where('email', 'admin@gmail.com')->value('id');

        if ($adminUserId) {
            DB::table('user_meta')->updateOrInsert(
                [
                    'userId' => $adminUserId,
                    'metaKey' => Constants::USER_META_KEYS['defaultAdmin'],
                ],
                [
                    'metaValue' => '1',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}
