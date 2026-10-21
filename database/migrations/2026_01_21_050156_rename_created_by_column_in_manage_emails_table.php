<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manage_emails', function (Blueprint $table) {
            $table->renameColumn('created_by', 'createdBy');
        });
    }

    public function down(): void
    {
        Schema::table('manage_emails', function (Blueprint $table) {
            $table->renameColumn('createdBy', 'created_by');
        });
    }
};
