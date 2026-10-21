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
        Schema::table('login_histories', function (Blueprint $table) {
            $table->string('os')->nullable()->after('userAgent');
            $table->string('device')->nullable()->after('os');
            $table->string('location')->nullable()->after('device');
            $table->string('loginType', 50)->nullable()->after('location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('login_histories', function (Blueprint $table) {
            $table->dropColumn(['os', 'device', 'location', 'loginType']);
        });
    }
};
