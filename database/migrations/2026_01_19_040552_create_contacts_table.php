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
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->nullable();
            $table->string('salutation', 10)->nullable();
            $table->string('contactType')->nullable();

            $table->string('phone', 20)->nullable();
            $table->string('alternatePhone', 20)->nullable();

            $table->string('email')->nullable()->unique();

            $table->string('source', 50)->default('Manual');

            $table->date('dob')->nullable();

            $table->foreignId('assignedAgentId')
                ->nullable();

            $table->foreignId('createdBy')
                ->nullable();

            $table->text('address')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
