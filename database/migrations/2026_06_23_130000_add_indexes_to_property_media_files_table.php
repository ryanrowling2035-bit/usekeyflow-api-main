<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_media_files', function (Blueprint $table) {
            // Serves the importer dedup check: where('fileName', ...)->exists()
            $table->index('fileName');
            // Serves per-property reorder lookups and the gallery aggregate
            // (group/join on propertyId, MAX(updated_at)).
            $table->index(['propertyId', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('property_media_files', function (Blueprint $table) {
            $table->dropIndex(['fileName']);
            $table->dropIndex(['propertyId', 'updated_at']);
        });
    }
};
