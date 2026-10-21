<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('property_media_files', function (Blueprint $table) {
            $table->boolean('isFlyer')->default(false)->after('isPortals');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_media_files', function (Blueprint $table) {
            $table->dropColumn('isFlyer');
        });
    }
};
