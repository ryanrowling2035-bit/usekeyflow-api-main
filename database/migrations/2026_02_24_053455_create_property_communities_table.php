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
        Schema::create('property_communities', function (Blueprint $table) {
            $table->id();
            $table->integer('cityId')->nullable();
            $table->string('communityId')->nullable()->unique();
            $table->string('name');
            $table->string('communityCode')->nullable();
            $table->string('type')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_communities');
    }
};
