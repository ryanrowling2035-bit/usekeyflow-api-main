<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('userId');
            $table->string('action');
            $table->string('module');
            $table->unsignedBigInteger('moduleId')->nullable();
            $table->text('description')->nullable();
            $table->json('oldValues')->nullable();
            $table->json('newValues')->nullable();
            $table->string('ipAddress')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activities');
    }
};
