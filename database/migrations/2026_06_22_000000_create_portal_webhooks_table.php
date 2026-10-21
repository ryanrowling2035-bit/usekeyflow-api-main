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
        Schema::create('portal_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('portal')->index();                 // propertyFinder / bayut
            $table->string('eventId')->nullable();             // provider event id — idempotency key
            $table->string('type')->nullable()->index();       // event type (e.g. listing.published, listing.action)
            $table->string('listingId')->nullable()->index();  // portal listing id (matches externalListingId)
            $table->integer('propertyId')->nullable()->index(); // resolved CRM property, when matched
            $table->string('actionType')->nullable();          // e.g. listing_trakheesi_invalid
            $table->string('status')->nullable();              // pending / expired / ...
            $table->text('reason')->nullable();                // action reason / English message
            $table->string('eventTimestamp')->nullable();      // provider timestamp (raw ISO-8601)
            $table->longText('payload')->nullable();           // full raw webhook body
            $table->timestamps();

            // Idempotency is per-portal: event ids are only unique within a provider.
            $table->unique(['portal', 'eventId']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_webhooks');
    }
};
