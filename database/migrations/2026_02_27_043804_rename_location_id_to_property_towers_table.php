<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_towers', function (Blueprint $table) {
            $table->renameColumn('locationId', 'propertyTowerId');
        });
    }

    public function down(): void
    {
        Schema::table('property_towers', function (Blueprint $table) {
            $table->renameColumn('propertyTowerId', 'locationId');
        });
    }
};
