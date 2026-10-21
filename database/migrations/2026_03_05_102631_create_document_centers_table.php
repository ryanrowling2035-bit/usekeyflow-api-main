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
        Schema::create('document_centers', function (Blueprint $table) {
            $table->id();
            $table->string('module');
            $table->unsignedBigInteger('moduleId');
            $table->string('title');
            $table->string('filePath');
            $table->string('type')->nullable();
            $table->string('size')->nullable();
            $table->string('docType')->nullable();
            $table->json('tags')->nullable();
            $table->unsignedBigInteger('createdBy');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('createdBy')->references('id')->on('users')->onDelete('cascade');
            $table->index(['module', 'moduleId']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_centers');
    }
};
