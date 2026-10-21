<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_sub_communities', function (Blueprint $table) {
            $table->renameColumn('locationId', 'propertySubCommunityId');
        });
    }

    public function down(): void
    {
        Schema::table('property_sub_communities', function (Blueprint $table) {
            $table->renameColumn('propertySubCommunityId', 'locationId');
        });
    }
};
