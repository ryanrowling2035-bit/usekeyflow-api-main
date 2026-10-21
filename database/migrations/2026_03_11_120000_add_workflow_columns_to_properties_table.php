<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedBigInteger('approvedBy')->nullable()->after('approvalStatus');
            $table->unsignedBigInteger('publishedBy')->nullable()->after('approvedBy');
            $table->timestamp('permitRequestedAt')->nullable()->after('publishedBy');
            $table->timestamp('approvedAt')->nullable()->after('permitRequestedAt');
            $table->timestamp('publishedAt')->nullable()->after('approvedAt');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['approvedBy', 'publishedBy', 'permitRequestedAt', 'approvedAt', 'publishedAt']);
        });
    }
};
