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
        Schema::create('enquiries', function (Blueprint $table) {
            $table->id();
            $table->integer('agentId')->nullable();
            $table->integer('contactId')->nullable();
            $table->string('propertyCategoryId')->nullable();
            $table->string('propertySubCategoryId')->nullable();
            $table->string('enquiryType')->nullable();
            $table->string('phone')->nullable();
            $table->string('status')->nullable();
            $table->string('referredBy')->nullable();
            $table->string('priceMin')->nullable();
            $table->string('priceMax')->nullable();
            $table->string('sizeMin')->nullable();
            $table->string('sizeMax')->nullable();
            $table->string('bedroomsMin')->nullable();
            $table->string('bedroomsMax')->nullable();
            $table->string('bathroomsMin')->nullable();
            $table->string('bathroomsMax')->nullable();
            $table->string('listingType')->nullable();
            $table->string('lostReason')->nullable();
            $table->string('leadSource')->nullable();
            $table->string('technicalSource')->nullable();
            $table->longText('comments')->nullable();
            $table->boolean('isOpenGame')->default(false);
            $table->integer('createdBy')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enquiries');
    }
};
