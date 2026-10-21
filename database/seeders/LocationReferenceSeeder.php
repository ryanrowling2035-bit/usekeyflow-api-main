<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the property location reference data (cities, communities,
 * sub-communities and towers) from the apndxmain dump.
 *
 * The rows (~1,240) are kept verbatim in data/apndxmain_locations.sql so the
 * decimals/nulls/ids round-trip exactly; this seeder just truncates the four
 * tables and replays those INSERTs. Idempotent — safe to re-run.
 */
class LocationReferenceSeeder extends Seeder
{
    /** Child-to-parent order; truncated in this order, loaded in reverse. */
    private const TABLES = [
        'property_towers',
        'property_sub_communities',
        'property_communities',
        'pf_location_cities',
    ];

    public function run(): void
    {
        $sql = file_get_contents(__DIR__.'/data/apndxmain_locations.sql');

        Schema::disableForeignKeyConstraints();
        try {
            foreach (self::TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                }
            }

            DB::unprepared($sql);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
}
