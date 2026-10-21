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
        Schema::table('assign_team_members', function (Blueprint $table) {
            $table->unsignedBigInteger('createdBy')->nullable()->after('userType');
            $table->foreign('createdBy')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assign_team_members', function (Blueprint $table) {
            $table->dropForeign(['createdBy']);
            $table->dropColumn('createdBy');
        });
    }
};
