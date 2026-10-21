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
        Schema::create('property_approval_histories', function (Blueprint $table) {
            $table->id();
            $table->integer('listingId')->nullable();
            $table->string('status')->nullable();
            $table->longText('comment')->nullable();
            $table->integer('auditBy')->nullable();
            $table->date('auditDate')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_approval_histories');
    }
};
