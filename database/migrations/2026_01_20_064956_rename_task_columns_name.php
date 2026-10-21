<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tasks', 'assigned_to')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->renameColumn('assigned_to', 'assignedTo');
            });
        }

        if (Schema::hasColumn('tasks', 'due_date')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->renameColumn('due_date', 'dueDate');
            });
        }

        if (Schema::hasColumn('tasks', 'reminder_set')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->renameColumn('reminder_set', 'reminderSet');
            });
        }

        if (Schema::hasColumn('tasks', 'reminder_date')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->renameColumn('reminder_date', 'reminderDate');
            });
        }

        if (Schema::hasColumn('tasks', 'reminder_time')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->renameColumn('reminder_time', 'reminderTime');
            });
        }

        if (Schema::hasColumn('tasks', 'property_id')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->renameColumn('property_id', 'propertyId');
            });
        }
    }

    public function down(): void {}
};
