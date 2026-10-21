<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PortalSettingSeeder extends Seeder
{
    public function run()
    {
        $portals = [
            ['portal' => 'bayut', 'branchName' => 'Bayut Feed', 'slug' => 'bayut-feed'],
            ['portal' => 'bayut', 'branchName' => 'Dubai Land Bayut Feed', 'slug' => 'dubai-land-bayut-feed'],
            ['portal' => 'bayut', 'branchName' => 'Dubai Marina Bayut Feed', 'slug' => 'dubai-marina-bayut-feed'],
            ['portal' => 'bayut', 'branchName' => 'VH BayutDubizzle', 'slug' => 'vh-bayut-dubizzle'],
            ['portal' => 'propertyFinder', 'branchName' => 'Property Finder', 'slug' => 'property-finder-main-branch'],
            ['portal' => 'propertyFinder', 'branchName' => 'Dubai Marina Branch Property Finder', 'slug' => 'dubai-marina-branch-property-finder'],
            ['portal' => 'propertyFinder', 'branchName' => 'Dubai Land Branch Property Finder', 'slug' => 'dubai-land-branch-property-finder'],
            ['portal' => 'propertyFinder', 'branchName' => 'Property Finder  Vacation Homes', 'slug' => 'property-finder-vacation-homes'],
            ['portal' => 'generic', 'branchName' => 'Generic', 'slug' => 'generic'],
            ['portal' => 'website', 'branchName' => 'Exclusive Links Website', 'slug' => 'exclusive-links-website'],
        ];

        foreach ($portals as $portal) {
            $existing = DB::table('portal_settings')->where('slug', $portal['slug'])->first();

            if ($existing) {
                DB::table('portal_settings')
                    ->where('slug', $portal['slug'])
                    ->update([
                        'branchName' => $portal['branchName'],
                        'updatedAt' => now(),
                    ]);
            } else {
                DB::table('portal_settings')->insert([
                    'portal' => $portal['portal'],
                    'branchName' => $portal['branchName'],
                    'slug' => $portal['slug'],
                    'status' => 'active',
                    'createdAt' => now(),
                    'updatedAt' => now(),
                ]);
            }
        }
    }
}
