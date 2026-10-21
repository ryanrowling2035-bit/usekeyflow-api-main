<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Placeholder phones ("0", "-", etc.) carry no real number and would
        // collide under a UNIQUE index. Null them so duplicates collapse
        // (MySQL allows multiple NULLs in a unique index).
        DB::statement("UPDATE contacts SET phone = NULL WHERE phone IS NOT NULL AND phone NOT REGEXP '[1-9]'");

        Schema::table('contacts', function (Blueprint $table) {
            // Salesforce reuses generic emails across distinct people, so email
            // is no longer the unique identifier — the phone (mobile) is.
            $table->dropUnique('contacts_email_unique');
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique('contacts_phone_unique');
            $table->unique('email');
        });
    }
};
