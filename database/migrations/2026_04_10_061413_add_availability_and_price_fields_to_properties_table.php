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
        Schema::table('properties', function (Blueprint $table) {
            $table->date('availabilityDate')->nullable()->after('unitNo');
            $table->decimal('annualRent', 15, 2)->nullable()->after('availabilityDate');
            $table->decimal('salePrice', 15, 2)->nullable()->after('annualRent');
            $table->string('titleDeedNumber')->nullable()->after('salePrice');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['availabilityDate', 'annualRent', 'salePrice', 'titleDeedNumber']);
        });
    }
};
