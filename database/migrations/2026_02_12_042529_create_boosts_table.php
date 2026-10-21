<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('boosts', function (Blueprint $table) {
            $table->id();
            $table->boolean('isPropertyFinder')->nullable();
            $table->integer('propertyId');
            $table->boolean('isBayut')->nullable();
            $table->string('duration')->nullable();
            $table->string('boostType')->nullable();
            $table->decimal('credits', 10, 2)->nullable();
            $table->string('status')->nullable();
            $table->text('notes')->nullable();
            $table->integer('createdBy')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boosts');
    }
};
