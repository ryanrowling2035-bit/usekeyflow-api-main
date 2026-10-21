<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('portal_settings', function (Blueprint $table) {
            $table->string('slug')->unique()->after('branchName');
            $table->string('status')->default('inActive')->after('pfApiSecretPublic');
        });
    }

    public function down()
    {
        Schema::table('portal_settings', function (Blueprint $table) {
            $table->dropColumn(['slug', 'status']);
        });
    }
};
