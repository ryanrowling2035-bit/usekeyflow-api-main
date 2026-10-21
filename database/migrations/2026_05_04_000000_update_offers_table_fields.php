<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            if (! Schema::hasColumn('offers', 'offerExpirationDate')) {
                $table->date('offerExpirationDate')->nullable()->after('offerDate');
            }

            if (Schema::hasColumn('offers', 'startingDate')) {
                $table->dropColumn('startingDate');
            }

            if (Schema::hasColumn('offers', 'offerType')) {
                $table->dropColumn('offerType');
            }
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('offerExpirationDate');
            $table->date('startingDate')->nullable()->after('status');
            $table->string('offerType')->nullable()->after('listingType');
        });
    }
};
