<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('overdueAgentNotified')->default(false)->after('reminderSent');
            $table->boolean('overdueManagerNotified')->default(false)->after('overdueAgentNotified');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['overdueAgentNotified', 'overdueManagerNotified']);
        });
    }
};
