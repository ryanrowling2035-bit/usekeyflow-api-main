<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_locations', function (Blueprint $table) {
            $table->id();
            $table->string('locationId')->nullable();
            $table->unsignedBigInteger('parentId')->nullable();
            $table->string('name');
            $table->enum('type', ['city', 'community', 'subCommunity', 'buildingTower']);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();

            $table->foreign('parentId')->references('id')->on('property_locations')->onDelete('cascade');
            $table->index('locationId');
            $table->index('parentId');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_locations');
    }
};
