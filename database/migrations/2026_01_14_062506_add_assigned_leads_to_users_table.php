<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('assignedLeads')->nullable()->after('userType');
            $table->integer('activeProperties')->nullable()->after('assignedLeads');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('assignedLeads');
            $table->dropColumn('activeProperties');
        });
    }
};
