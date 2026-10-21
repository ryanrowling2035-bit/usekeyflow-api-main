<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_notes', function (Blueprint $table) {
            $table->id();
            $table->string('module');
            $table->unsignedBigInteger('moduleId');
            $table->longText('content')->nullable();
            $table->integer('parent')->default(0);
            $table->integer('createdBy')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['module', 'moduleId']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_notes');
    }
};
