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
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->integer('userId')->nullable();
            $table->integer('moduleId')->nullable();
            $table->string('module')->nullable();
            $table->timestamps();

            // Add unique constraint to prevent duplicate follows
            $table->unique(['userId', 'moduleId', 'module']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};
