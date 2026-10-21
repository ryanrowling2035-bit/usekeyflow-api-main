<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('ownerName')->nullable()->after('pfOwnerId');
            $table->string('ownerPhone')->nullable()->after('ownerName');
            $table->string('ownerEmail')->nullable()->after('ownerPhone');
            $table->text('ownerAddress')->nullable()->after('ownerEmail');
            $table->string('ownerCountry')->nullable()->after('ownerAddress');
            $table->string('ownerState')->nullable()->after('ownerCountry');
            $table->string('ownerCity')->nullable()->after('ownerState');
            $table->string('ownerZipCode')->nullable()->after('ownerCity');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'ownerName',
                'ownerPhone',
                'ownerEmail',
                'ownerAddress',
                'ownerCountry',
                'ownerState',
                'ownerCity',
                'ownerZipCode',
            ]);
        });
    }
};
