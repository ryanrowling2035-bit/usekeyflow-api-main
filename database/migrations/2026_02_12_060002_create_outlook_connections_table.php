<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlook_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('userId')->unique();
            $table->text('accessToken')->nullable();
            $table->text('refreshToken')->nullable();
            $table->timestamp('tokenExpiresAt')->nullable();
            $table->string('outlookEmail')->nullable();
            $table->string('status')->default('connected'); // connected, disconnected
            $table->timestamps();

            $table->index('userId');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlook_connections');
    }
};
