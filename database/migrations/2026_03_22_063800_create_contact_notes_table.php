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
        Schema::create('contact_notes', function (Blueprint $table) {
            $table->id();
            $table->integer('contactId');
            $table->longText('content')->nullable();
            $table->string('type')->default('note');
            $table->string('callStatus')->nullable();
            $table->integer('parent')->default(0);
            $table->integer('createdBy')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_notes');
    }
};
