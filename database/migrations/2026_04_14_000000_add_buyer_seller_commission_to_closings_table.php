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
            $table->decimal('buyerCommission', 15, 2)->nullable()->after('saleRentalPrice');
            $table->decimal('sellerCommission', 15, 2)->nullable()->after('buyerCommission');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('closings', function (Blueprint $table) {
            $table->dropColumn(['buyerCommission', 'sellerCommission']);
        });
    }
};
