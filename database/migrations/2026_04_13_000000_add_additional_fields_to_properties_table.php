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
            $table->string('access')->nullable();
            $table->string('listingLeadSource2')->nullable();
            $table->string('view')->nullable();
            $table->string('developmentText')->nullable();
            $table->string('projectText')->nullable();
            $table->string('priceOnRequest')->nullable();
            $table->decimal('deposit', 15, 2)->nullable();
            $table->decimal('downPayment', 15, 2)->nullable();
            $table->string('paymentMethod')->nullable();
            $table->boolean('sold')->default(false);
            $table->decimal('agencyFee', 15, 2)->nullable();
            $table->decimal('agencyFeePercent', 8, 2)->nullable();
            $table->string('ownershipTerm')->nullable();
            $table->string('developerName')->nullable();
            $table->string('stories')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'access',
                'listingLeadSource2',
                'view',
                'developmentText',
                'projectText',
                'priceOnRequest',
                'deposit',
                'downPayment',
                'paymentMethod',
                'sold',
                'agencyFee',
                'agencyFeePercent',
                'ownershipTerm',
                'developerName',
                'stories',
            ]);
        });
    }
};
