<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::rename('property_locations', 'pf_location_cities');
    }

    public function down(): void
    {
        Schema::rename('pf_location_cities', 'property_locations');
    }
};
