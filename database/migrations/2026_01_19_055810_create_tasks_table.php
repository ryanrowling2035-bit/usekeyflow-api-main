<?php

use App\Constants\Constants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->unsignedBigInteger('assignedTo');
            $table->enum('type', Constants::ACTIVITY_TYPES);
            $table->date('dueDate');
            $table->boolean('reminderSet')->default(false);
            $table->date('reminderDate');
            $table->time('reminderTime');
            $table->text('comments');
            $table->enum('status', Constants::TASK_STATUS)->default('Pending');
            $table->enum('priority', Constants::TASK_PRIORITY);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
