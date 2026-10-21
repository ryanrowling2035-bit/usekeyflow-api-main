<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_custom_email_templates', function (Blueprint $table) {
            $table->id();
            $table->integer('propertyId')->nullable();
            $table->string('ownerEmail')->nullable();
            $table->string('subject')->nullable();
            $table->longText('emailContent')->nullable();
            $table->string('fileName')->nullable();
            $table->string('filePath')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_custom_email_templates');
    }
};
