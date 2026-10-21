<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enquiry_histories', function (Blueprint $table) {
            $table->json('comments')->nullable()->after('newValue');
        });

        Schema::table('property_listing_histories', function (Blueprint $table) {
            $table->longText('originalValue')->nullable()->change();
            $table->longText('newValue')->nullable()->change();
            $table->json('comments')->nullable()->after('newValue');
        });
    }

    public function down(): void
    {
        Schema::table('enquiry_histories', function (Blueprint $table) {
            $table->dropColumn('comments');
        });

        Schema::table('property_listing_histories', function (Blueprint $table) {
            $table->text('originalValue')->nullable()->change();
            $table->text('newValue')->nullable()->change();
            $table->dropColumn('comments');
        });
    }
};
