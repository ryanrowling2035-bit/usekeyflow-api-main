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

        Schema::table('closings', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->string('clientLeadSource')->nullable()->after('assignedAgentId');
            $table->string('sellerLandlord')->nullable()->after('clientLeadSource');
            $table->decimal('saleRentalPrice', 15, 2)->nullable()->after('sellerLandlord');
            $table->decimal('totalCommission', 15, 2)->nullable()->after('saleRentalPrice');
            $table->json('closingChecklist')->nullable()->after('totalCommission');
            $table->text('internalNotes')->nullable()->after('closingChecklist');
            $table->integer('createdBy')->nullable()->after('internalNotes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('closings', function (Blueprint $table) {
            $table->dropColumn([
                'name',
                'clientLeadSource',
                'sellerLandlord',
                'saleRentalPrice',
                'totalCommission',
                'closingChecklist',
                'internalNotes',
                'createdBy',
            ]);
        });
    }
};
