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
        Schema::table('user_meta', function (Blueprint $table) {
            $table->text('metaValue')->nullable()->change();
        });

        Schema::table('property_meta', function (Blueprint $table) {
            $table->longText('metaValue')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_meta', function (Blueprint $table) {
            $table->text('metaValue')->nullable(false)->change();
        });

        Schema::table('property_meta', function (Blueprint $table) {
            $table->longText('metaValue')->nullable(false)->change();
        });
    }
};
