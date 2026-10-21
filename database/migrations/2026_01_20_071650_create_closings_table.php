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
        Schema::create('closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listingId')->nullable();
            $table->foreignId('offerId')->nullable();
            $table->foreignId('buyerId')->nullable();
            $table->string('status');
            $table->string('listingType');
            $table->date('targetCloseDate')->nullable();
            $table->date('closingDate')->nullable();
            $table->foreignId('assignedAgentId')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('closings');
    }
};
