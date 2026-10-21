<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('eventType');
            $table->dateTime('startDateTime');
            $table->dateTime('endDateTime')->nullable();
            $table->boolean('allDay')->default(false);
            $table->string('location')->nullable();
            $table->string('module')->nullable();
            $table->integer('moduleId')->nullable();
            $table->integer('taskId')->nullable();
            $table->integer('assignedTo')->nullable();
            $table->integer('createdBy')->nullable();
            $table->string('status')->default('Scheduled');
            $table->string('priority')->nullable();
            $table->string('color')->nullable();
            $table->boolean('reminderSet')->default(false);
            $table->date('reminderDate')->nullable();
            $table->time('reminderTime')->nullable();
            $table->string('outlookEventId')->nullable();
            $table->boolean('isFromOutlook')->default(false);
            $table->boolean('isSyncedToOutlook')->default(false);
            $table->boolean('isPrivate')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('assignedTo');
            $table->index('createdBy');
            $table->index('eventType');
            $table->index('module');
            $table->index('startDateTime');
            $table->index('outlookEventId');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
