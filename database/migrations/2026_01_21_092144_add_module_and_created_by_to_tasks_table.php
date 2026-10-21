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
        Schema::table('tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('tasks', 'module')) {
                $table->string('module')->nullable()->after('moduleId');
            }
            if (! Schema::hasColumn('tasks', 'createdBy')) {
                $table->integer('createdBy')->nullable()->after('priority');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'module')) {
                $table->dropColumn('module');
            }
            if (Schema::hasColumn('tasks', 'createdBy')) {
                $table->dropColumn('createdBy');
            }
        });
    }
};
