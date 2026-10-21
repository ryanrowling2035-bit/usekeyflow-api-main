<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Normalize all module column values to lowercase across tables.
     */
    public function up(): void
    {
        $tables = [
            'follows',
            'notifications',
            'internal_notes',
            'tasks',
            'calendar_events',
            'user_activities',
        ];

        foreach ($tables as $table) {
            DB::table($table)
                ->whereRaw('BINARY module != LOWER(module)')
                ->update(['module' => DB::raw('LOWER(module)')]);
        }
    }

    public function down(): void
    {
        // Data normalization — not reversible
    }
};
