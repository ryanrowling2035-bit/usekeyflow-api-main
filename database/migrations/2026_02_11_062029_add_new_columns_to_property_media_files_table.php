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
            $table->integer('updatedBy')->nullable()->after('createdBy');
            $table->integer('propertyCategoryId')->nullable()->after('propertyId');
            $table->boolean('isWebsite')->default(false)->after('propertyCategoryId');
            $table->boolean('isPortals')->default(false)->after('isWebsite');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_media_files', function (Blueprint $table) {
            $table->dropColumn(['updatedBy', 'propertyCategoryId', 'isWebsite', 'isPortals']);
        });
    }
};
