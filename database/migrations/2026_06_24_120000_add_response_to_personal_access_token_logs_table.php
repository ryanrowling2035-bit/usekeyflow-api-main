<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_token_logs', function (Blueprint $table) {
            $table->json('response')->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_token_logs', function (Blueprint $table) {
            $table->dropColumn('response');
        });
    }
};
