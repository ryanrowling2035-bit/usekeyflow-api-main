<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen enquiries.webUrl from VARCHAR(255) to TEXT — portal response links
     * (e.g. PropertyFinder lead responseLink) can exceed 255 characters.
     */
    public function up(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->text('webUrl')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->string('webUrl')->nullable()->change();
        });
    }
};
