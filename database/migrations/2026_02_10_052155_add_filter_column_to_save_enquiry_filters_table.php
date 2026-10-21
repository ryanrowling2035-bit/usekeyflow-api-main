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
        Schema::table('save_enquiry_filters', function (Blueprint $table) {
            $table->string('module')->nullable()->after('filters');
            $table->json('fields')->nullable()->after('module');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('save_enquiry_filters', function (Blueprint $table) {
            $table->dropColumn('module');
            $table->dropColumn('fields');
        });
    }
};
