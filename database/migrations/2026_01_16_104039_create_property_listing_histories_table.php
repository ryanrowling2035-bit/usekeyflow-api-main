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
        Schema::create('property_listing_histories', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('field')->nullable();
            $table->integer('propertyId')->nullable();
            $table->integer('userId')->nullable();
            $table->text('originalValue')->nullable();
            $table->text('newValue')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_listing_histories');
    }
};
