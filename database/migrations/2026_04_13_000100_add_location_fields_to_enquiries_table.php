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
        Schema::table('enquiries', function (Blueprint $table) {
            $table->string('city')->nullable()->after('technicalSource');
            $table->string('community')->nullable()->after('city');
            $table->string('subCommunity')->nullable()->after('community');
            $table->string('building')->nullable()->after('subCommunity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->dropColumn(['city', 'community', 'subCommunity', 'building']);
        });
    }
};
