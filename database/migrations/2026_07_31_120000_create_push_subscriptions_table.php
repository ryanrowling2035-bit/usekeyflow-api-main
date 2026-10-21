<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Browser Web Push (VAPID) subscriptions. One row per browser/device a user
     * has granted notification permission on, so a single user can hold several.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('userId')->index();
            // Push-service URL the browser hands us. Unique so a re-subscribe from
            // the same browser updates the row instead of duplicating it.
            $table->string('endpoint', 500)->unique();
            // Client keys used to encrypt the payload end-to-end.
            $table->string('publicKey', 255);
            $table->string('authToken', 255);
            $table->string('contentEncoding', 50)->default('aesgcm');
            $table->string('userAgent', 512)->nullable();
            $table->timestamp('lastUsedAt')->nullable();
            $table->timestamps();

            $table->foreign('userId')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
