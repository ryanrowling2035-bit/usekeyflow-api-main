<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_videos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('propertyId');
            $table->string('videoUrl');
            $table->string('platform')->nullable();
            $table->string('title')->nullable();
            $table->boolean('isDefault')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_videos');
    }
};
