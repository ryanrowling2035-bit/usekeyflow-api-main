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
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'pfOwnerId',
                'ownerName',
                'ownerPhone',
                'ownerEmail',
                'ownerAddress',
                'ownerCountry',
                'ownerState',
                'ownerCity',
                'ownerZipCode',
            ]);

            $table->foreignId('physicalOwnerId')->nullable()->constrained('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['physicalOwnerId']);
            $table->dropColumn('physicalOwnerId');

            $table->integer('pfOwnerId')->nullable();
            $table->string('ownerName')->nullable();
            $table->string('ownerPhone')->nullable();
            $table->string('ownerEmail')->nullable();
            $table->text('ownerAddress')->nullable();
            $table->string('ownerCountry')->nullable();
            $table->string('ownerState')->nullable();
            $table->string('ownerCity')->nullable();
            $table->string('ownerZipCode')->nullable();
        });
    }
};
