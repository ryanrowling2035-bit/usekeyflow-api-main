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
        Schema::table('users', function (Blueprint $table) {
            // Change status from enum to tinyint (0 = InActive, 1 = Active)
            $table->tinyInteger('status')->default(1)->change();

            // Add createdBy column
            $table->integer('createdBy')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Revert status back to enum
            $table->enum('status', ['Active', 'InActive'])->default('Active')->change();

            // Drop createdBy column
            $table->dropColumn('createdBy');
        });
    }
};
