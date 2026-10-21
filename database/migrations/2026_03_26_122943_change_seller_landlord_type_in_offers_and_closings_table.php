<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clean non-numeric values before changing column type
        DB::table('offers')
            ->whereNotNull('sellerLandlord')
            ->whereRaw("sellerLandlord NOT REGEXP '^[0-9]+$'")
            ->update(['sellerLandlord' => null]);

        DB::table('closings')
            ->whereNotNull('sellerLandlord')
            ->whereRaw("sellerLandlord NOT REGEXP '^[0-9]+$'")
            ->update(['sellerLandlord' => null]);

        Schema::table('offers', function (Blueprint $table) {
            $table->unsignedBigInteger('sellerLandlord')->nullable()->change();
        });

        Schema::table('closings', function (Blueprint $table) {
            $table->unsignedBigInteger('sellerLandlord')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->string('sellerLandlord')->nullable()->change();
        });

        Schema::table('closings', function (Blueprint $table) {
            $table->string('sellerLandlord')->nullable()->change();
        });
    }
};
