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
        Schema::dropIfExists('property_linked_listings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('property_linked_listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('propertyId');
            $table->unsignedBigInteger('enquiryId');
            $table->integer('createdBy')->nullable();
            $table->timestamps();
        });
    }
};
