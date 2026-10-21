<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * contactType becomes multi-value: a contact can be buyer + seller + agent, etc.
     * Existing single (scalar) values are migrated into 1-element JSON arrays so that
     * single-role contacts keep working unchanged.
     */
    public function up(): void
    {
        // 1) Normalise empty strings to NULL.
        DB::statement("UPDATE contacts SET contactType = NULL WHERE contactType IS NULL OR TRIM(contactType) = ''");

        // 2) Wrap existing scalar values into a JSON array string while still a varchar
        //    (skip anything already shaped like a JSON array, in case of re-run).
        DB::statement("UPDATE contacts SET contactType = JSON_ARRAY(contactType) WHERE contactType IS NOT NULL AND LEFT(contactType, 1) <> '['");

        // 3) Now every value is valid JSON — switch the column type to JSON.
        DB::statement('ALTER TABLE contacts MODIFY contactType JSON NULL');
    }

    public function down(): void
    {
        // Collapse the array back to its first element, then revert to varchar.
        DB::statement('ALTER TABLE contacts MODIFY contactType VARCHAR(255) NULL');
        DB::statement("UPDATE contacts SET contactType = JSON_UNQUOTE(JSON_EXTRACT(contactType, '$[0]')) WHERE contactType IS NOT NULL AND LEFT(contactType, 1) = '['");
    }
};
