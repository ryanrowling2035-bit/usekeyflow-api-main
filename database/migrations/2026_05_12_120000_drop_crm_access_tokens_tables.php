<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('crm_access_token_logs');
        Schema::dropIfExists('crm_access_tokens');
    }

    public function down(): void
    {
        Schema::create('crm_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('userId')->constrained('users')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('name')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->enum('status', ['active', 'revoked'])->default('active');
            $table->timestamps();
        });

        Schema::create('crm_access_token_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tokenId')->constrained('crm_access_tokens')->cascadeOnDelete();
            $table->foreignId('userId')->constrained('users')->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('endpoint');
            $table->unsignedSmallInteger('statusCode')->nullable();
            $table->string('ipAddress', 45)->nullable();
            $table->text('userAgent')->nullable();
            $table->timestamp('requested_at')->useCurrent();
        });
    }
};
