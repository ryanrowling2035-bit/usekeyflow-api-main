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
        Schema::table('offers', function (Blueprint $table) {

            if (! Schema::hasColumn('offers', 'startingDate')) {
                $table->date('startingDate')->nullable()->after('status');
            }

            if (! Schema::hasColumn('offers', 'offerDate')) {
                $table->date('offerDate')->nullable()->after('status');
            }

            if (! Schema::hasColumn('offers', 'sellerLandlord')) {
                $table->string('sellerLandlord')->nullable()->after('status');
            }

            if (! Schema::hasColumn('offers', 'managerFormula')) {
                $table->string('managerFormula')->nullable()->after('ownerName');
            }

            if (! Schema::hasColumn('offers', 'listingType')) {
                $table->string('listingType')->nullable()->after('managerFormula');
            }

            if (! Schema::hasColumn('offers', 'listingPrice')) {
                $table->decimal('listingPrice', 15, 2)->nullable()->after('listingType');
            }

            if (! Schema::hasColumn('offers', 'leaseTerms')) {
                $table->string('leaseTerms')->nullable()->after('listingPrice');
            }

            if (! Schema::hasColumn('offers', 'buyerTenantNationality')) {
                $table->integer('buyerTenantNationality')->nullable()->after('leaseTerms');
            }

            if (! Schema::hasColumn('offers', 'potentialCommission')) {
                $table->decimal('potentialCommission', 15, 2)->nullable()->after('buyerTenantNationality');
            }

            if (! Schema::hasColumn('offers', 'deposits')) {
                $table->decimal('deposits', 15, 2)->nullable()->after('potentialCommission');
            }

            if (! Schema::hasColumn('offers', 'internalNotes')) {
                $table->text('internalNotes')->nullable()->after('deposits');
            }

            if (! Schema::hasColumn('offers', 'terms')) {
                $table->text('terms')->nullable()->after('internalNotes');
            }

            if (! Schema::hasColumn('offers', 'createdBy')) {
                $table->integer('createdBy')->nullable()->after('terms');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn([
                'startingDate',
                'offerDate',
                'sellerLandlord',
                'managerFormula',
                'listingType',
                'listingPrice',
                'leaseTerms',
                'buyerTenantNationality',
                'potentialCommission',
                'deposits',
                'internalNotes',
                'terms',
                'createdBy',
            ]);
        });
    }
};
