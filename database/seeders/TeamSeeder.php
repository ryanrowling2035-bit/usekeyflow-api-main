<?php

namespace Database\Seeders;

use App\Models\Team;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TeamSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('assign_team_members')->truncate();
        DB::table('teams')->truncate();

        $teams = [
            ['name' => 'Exclusive Team', 'description' => null, 'status' => 'active'],
            ['name' => 'Vacation Homes Team', 'description' => null, 'status' => 'active'],
            ['name' => 'Offplan Team', 'description' => null, 'status' => 'active'],
            ['name' => 'Dubai Marina Team', 'description' => null, 'status' => 'active'],
            ['name' => 'Dubai Land Team', 'description' => null, 'status' => 'active'],
        ];

        foreach ($teams as $team) {
            Team::create($team);
        }
    }
}
