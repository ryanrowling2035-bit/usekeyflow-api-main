<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn(['internalNotes', 'module']);
            $table->unsignedBigInteger('enquiryId')->nullable()->after('listingId');
        });

        Schema::table('closings', function (Blueprint $table) {
            $table->dropColumn(['internalNotes', 'module']);
            $table->unsignedBigInteger('enquiryId')->nullable()->after('listingId');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->text('internalNotes')->nullable();
            $table->string('module')->nullable();
            $table->dropColumn('enquiryId');
        });

        Schema::table('closings', function (Blueprint $table) {
            $table->text('internalNotes')->nullable();
            $table->string('module')->nullable();
            $table->dropColumn('enquiryId');
        });
    }
};
