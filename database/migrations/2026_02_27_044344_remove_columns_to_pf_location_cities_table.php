<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pf_location_cities', function (Blueprint $table) {

            $table->dropForeign('property_locations_parentid_foreign');
            $table->dropColumn('parentId');

            if (Schema::hasColumn('pf_location_cities', 'communityCode')) {
                $table->dropColumn('communityCode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pf_location_cities', function (Blueprint $table) {

            $table->integer('parentId')->nullable();
            $table->integer('communityCode')->nullable();

            $table->foreign('parentId')
                ->references('id')
                ->on('property_locations')
                ->onDelete('cascade');
        });
    }
};
