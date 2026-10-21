<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->string('referenceNumber')->nullable();

            $table->string('category')->nullable();
            $table->string('listingPurposeType')->nullable();

            $table->string('sizeArea')->nullable();
            $table->string('plotSize')->nullable();
            $table->integer('bedrooms')->nullable();
            $table->integer('bathrooms')->nullable();
            $table->string('furnishing')->nullable();
            $table->string('completionStatus')->nullable();

            $table->decimal('price', 15, 2)->nullable();
            $table->string('reraPermitNo')->nullable();

            $table->integer('agentId')->nullable();
            $table->integer('pfOwnerId')->nullable();

            $table->string('pfPropertyType')->nullable();
            $table->string('bayutPropertyType')->nullable();

            $table->string('pfCity')->nullable();
            $table->string('pfCommunity')->nullable();
            $table->string('pfSubCommunity')->nullable();
            $table->string('pfBuildingTower')->nullable();
            $table->decimal('pfLatitude', 10, 7)->nullable();
            $table->decimal('pfLongitude', 10, 7)->nullable();

            $table->json('pfAmenities')->nullable();
            $table->string('bayutLocation')->nullable();
            $table->json('bayutAmenities')->nullable();

            $table->string('pfPriceFrequency')->nullable();
            $table->string('numberOfCheques')->nullable();

            $table->string('bayutRentFrequency')->nullable();
            $table->string('occupancyStatus')->nullable();
            $table->string('minContract')->nullable();
            $table->string('noticePeriod')->nullable();
            $table->string('maintenanceFee')->nullable();
            $table->string('maintenancePaidBy')->nullable();

            $table->integer('createdBy')->nullable();
            $table->string('status')->nullable();
            $table->boolean('requestPermit')->default(false);
            $table->string('approvalStatus')->nullable();

            $table->timestamps();
            $table->date('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
