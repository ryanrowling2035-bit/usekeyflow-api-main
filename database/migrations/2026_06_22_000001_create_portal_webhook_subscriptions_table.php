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
        Schema::create('portal_webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('portal')->index();              // propertyFinder / bayut
            $table->string('slug')->index();                // portal account slug
            $table->string('eventId');                      // event subscribed (e.g. listing.published)
            $table->string('webhookId')->nullable();        // provider subscription id, when returned
            $table->string('callbackUrl')->nullable();      // URL the provider posts events to
            $table->string('status')->nullable();           // subscribed / failed
            $table->integer('statusCode')->nullable();      // provider HTTP status
            $table->text('error')->nullable();              // provider error, when failed
            $table->longText('response')->nullable();       // full per-event result for later inspection
            $table->timestamps();

            // One subscription row per account + event; re-subscribing updates it.
            $table->unique(['portal', 'slug', 'eventId']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_webhook_subscriptions');
    }
};
