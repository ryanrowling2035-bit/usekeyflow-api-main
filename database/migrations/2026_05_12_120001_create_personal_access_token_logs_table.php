<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_token_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tokenId')->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->foreignId('userId')->constrained('users')->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('endpoint');
            $table->unsignedSmallInteger('statusCode')->nullable();
            $table->string('ipAddress', 45)->nullable();
            $table->text('userAgent')->nullable();
            $table->timestamp('requested_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_token_logs');
    }
};
