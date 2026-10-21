<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            // Marks a manually-set lead score. When true, automation never
            // overwrites leadRating (Manual Override = highest priority).
            $table->boolean('leadScoreLocked')->default(false)->after('leadRating');
        });

        // --- Backfill leadRating as the single source of truth ------------------
        // 1. Existing valid ratings (e.g. Salesforce imports) are treated as a
        //    manual lock so they are preserved and no longer auto-overwritten.
        foreach (['hot', 'warm', 'cold'] as $band) {
            DB::table('enquiries')
                ->whereRaw('LOWER(TRIM(leadRating)) = ?', [$band])
                ->update(['leadRating' => $band, 'leadScoreLocked' => true]);
        }

        // 2. Everything still unlocked (null or non-standard rating) is put into
        //    auto mode: leadRating is derived from the current status.
        $statuses = DB::table('enquiries')
            ->where('leadScoreLocked', false)
            ->distinct()
            ->pluck('status');

        foreach ($statuses as $status) {
            DB::table('enquiries')
                ->where('leadScoreLocked', false)
                ->where('status', $status)
                ->update(['leadRating' => leadScoreForStatus($status)]);
        }

        // Handle rows whose status is NULL separately (the where above skips NULL).
        DB::table('enquiries')
            ->where('leadScoreLocked', false)
            ->whereNull('status')
            ->update(['leadRating' => null]);
    }

    public function down(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->dropColumn('leadScoreLocked');
        });
    }
};
