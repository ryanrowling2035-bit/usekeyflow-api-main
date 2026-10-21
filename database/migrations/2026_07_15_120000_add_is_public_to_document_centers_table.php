<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Visibility flag driving where a document's bytes live: false => private bucket, served via
     * short-lived signed URLs; true => public bucket, served via a permanent URL. Defaults false so
     * every existing row (and every new upload) is private until explicitly made public — the safe
     * posture, since the table mixes marketing docs with passports/title deeds/contracts.
     */
    public function up(): void
    {
        Schema::table('document_centers', function (Blueprint $table) {
            $table->boolean('isPublic')->default(false)->after('filePath');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_centers', function (Blueprint $table) {
            $table->dropColumn('isPublic');
        });
    }
};
