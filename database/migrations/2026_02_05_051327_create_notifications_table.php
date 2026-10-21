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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('userId')->index();
            $table->string('type', 50)->index();
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('module', 50)->nullable()->index();
            $table->unsignedBigInteger('moduleId')->nullable();
            $table->unsignedBigInteger('actorId')->nullable();
            $table->json('data')->nullable();
            $table->boolean('isRead')->default(false)->index();
            $table->timestamp('readAt')->nullable();
            $table->timestamps();

            $table->foreign('userId')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('actorId')->references('id')->on('users')->onDelete('set null');

            $table->index(['userId', 'isRead']);
            $table->index(['module', 'moduleId']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
