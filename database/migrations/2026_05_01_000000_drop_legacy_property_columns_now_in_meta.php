<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'furnishing',
                'unitNo',
                'stories',
                'availabilityDate',
                'availabilityToDate',
                'developerName',
                'downPayment',
                'paymentMethod',
                'priceOnRequest',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('furnishing')->nullable();
            $table->string('unitNo')->nullable();
            $table->string('stories')->nullable();
            $table->date('availabilityDate')->nullable();
            $table->date('availabilityToDate')->nullable();
            $table->string('developerName')->nullable();
            $table->decimal('downPayment', 15, 2)->nullable();
            $table->string('paymentMethod')->nullable();
            $table->string('priceOnRequest')->nullable();
        });
    }
};
