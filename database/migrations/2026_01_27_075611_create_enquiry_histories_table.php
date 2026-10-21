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

        Schema::create('enquiry_histories', function (Blueprint $table) {
            $table->id();
            $table->integer('enquiryId')->nullable();
            $table->integer('userId')->nullable();
            $table->string('field')->nullable();
            $table->longText('originalValue')->nullable();
            $table->longText('newValue')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enquiry_histories');
    }
};
