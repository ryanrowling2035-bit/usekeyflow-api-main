<?php

use App\Constants\Constants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {

            $table->integer('assignedTo')->change();
            $table->string('type')->nullable()->change();
            $table->string('status')->nullable()->change();
            $table->string('priority')->nullable()->change();
        });

        // Optional but recommended: normalize existing data
        DB::table('tasks')
            ->whereNull('status')
            ->update(['status' => 'Pending']);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {

            $table->unsignedBigInteger('assignedTo')->change();
            $table->enum('type', Constants::ACTIVITY_TYPES)->change();
            $table->enum('status', Constants::TASK_STATUS)
                ->default('Pending')
                ->change();
            $table->enum('priority', Constants::TASK_PRIORITY)->change();
        });
    }
};
