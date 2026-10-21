<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Boosted by" — who approved the boost or pushed it live. Distinct from
     * createdBy (the requester). Nullable: a boost still awaiting approval, or
     * one rejected/expired, has no one who boosted it.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('boosts', 'approvedBy')) {
            Schema::table('boosts', function (Blueprint $table) {
                $table->unsignedBigInteger('approvedBy')->nullable()->after('createdBy')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('boosts', 'approvedBy')) {
            Schema::table('boosts', function (Blueprint $table) {
                $table->dropColumn('approvedBy');
            });
        }
    }
};
