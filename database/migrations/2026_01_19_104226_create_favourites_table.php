<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favourites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('userId');
            $table->unsignedBigInteger('moduleId');
            $table->string('module');
            $table->timestamps();
            $table->unique(['userId', 'moduleId', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favourites');
    }
};
