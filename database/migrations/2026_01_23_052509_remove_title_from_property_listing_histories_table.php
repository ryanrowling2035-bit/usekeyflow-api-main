<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_listing_histories', function (Blueprint $table) {
            if (Schema::hasColumn('property_listing_histories', 'title')) {
                $table->dropColumn('title');
            }
        });
    }

    public function down(): void
    {
        Schema::table('property_listing_histories', function (Blueprint $table) {
            $table->string('title')->nullable();
        });
    }
};
