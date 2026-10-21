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
        Schema::create('marketing_assets', function (Blueprint $table) {
            $table->id();
            $table->string('assetName');
            $table->string('category')->nullable();
            $table->string('assetType')->nullable();
            $table->text('description')->nullable();
            $table->integer('uploadedBy')->nullable();
            $table->string('fileName')->nullable();
            $table->string('filePath')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_assets');
    }
};
