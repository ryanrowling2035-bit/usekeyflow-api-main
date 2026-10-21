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
            $table->string('mediaType')->nullable()->after('filePath');
            $table->integer('ordering')->nullable()->after('mediaType');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_media_files', function (Blueprint $table) {
            $table->dropColumn('mediaType');
            $table->dropColumn('ordering');
        });
    }
};
