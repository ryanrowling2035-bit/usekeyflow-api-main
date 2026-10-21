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
            $table->dropColumn(['subCommunity', 'building']);
        });

        Schema::table('enquiries', function (Blueprint $table) {
            $table->string('community_2')->nullable()->after('community');
            $table->string('community_3')->nullable()->after('community_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->dropColumn(['community_2', 'community_3']);
        });

        Schema::table('enquiries', function (Blueprint $table) {
            $table->string('subCommunity')->nullable()->after('community');
            $table->string('building')->nullable()->after('subCommunity');
        });
    }
};
