<?php

use App\Constants\Constants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move the "date this listing entered its current status" stamps into property_meta:
     * pocketListingDate off its dedicated column, plus a new upcomingDate key. Both feed
     * app:send-listing-aged-reminder, which nudges the agent after 2 weeks.
     */
    public function up(): void
    {
        $pocketKey = Constants::PROPERTY_META_KEYS['pocketListingDate'];
        $upcomingKey = Constants::PROPERTY_META_KEYS['upcomingDate'];

        // Carry the existing column values over as-is.
        DB::statement('
            INSERT INTO property_meta (propertyId, metaKey, metaValue, created_at, updated_at)
            SELECT p.id, ?, DATE(p.pocketListingDate), NOW(), NOW()
            FROM properties p
            WHERE p.pocketListingDate IS NOT NULL
              AND p.status = ?
              AND NOT EXISTS (
                  SELECT 1 FROM property_meta pm WHERE pm.propertyId = p.id AND pm.metaKey = ?
              )
        ', [$pocketKey, Constants::PROPERTY_STATUS_POCKET_LISTING, $pocketKey]);

        // Upcoming has never been stamped, so seed it from created_at — Upcoming is the
        // status a listing is created in, making created_at the best available proxy.
        DB::statement('
            INSERT INTO property_meta (propertyId, metaKey, metaValue, created_at, updated_at)
            SELECT p.id, ?, DATE(p.created_at), NOW(), NOW()
            FROM properties p
            WHERE p.status = ?
              AND NOT EXISTS (
                  SELECT 1 FROM property_meta pm WHERE pm.propertyId = p.id AND pm.metaKey = ?
              )
        ', [$upcomingKey, Constants::PROPERTY_STATUS_UPCOMING, $upcomingKey]);

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('pocketListingDate');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->date('pocketListingDate')->nullable()->after('bua');
        });

        DB::statement('
            UPDATE properties p
            JOIN property_meta pm ON pm.propertyId = p.id AND pm.metaKey = ?
            SET p.pocketListingDate = DATE(pm.metaValue)
        ', [Constants::PROPERTY_META_KEYS['pocketListingDate']]);

        DB::table('property_meta')
            ->whereIn('metaKey', [
                Constants::PROPERTY_META_KEYS['pocketListingDate'],
                Constants::PROPERTY_META_KEYS['upcomingDate'],
            ])
            ->delete();
    }
};
