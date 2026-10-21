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
        Schema::table('boosts', function (Blueprint $table) {
            $table->text('rejectionReason')->nullable()->after('notes');
            $table->date('startDate')->nullable()->after('rejectionReason');
            $table->date('endDate')->nullable()->after('startDate');
        });
    }

    public function down(): void
    {
        Schema::table('boosts', function (Blueprint $table) {
            $table->dropColumn(['rejectionReason', 'startDate', 'endDate']);
        });
    }
};
