<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        foreach (['daily_listing_emails', 'daily_enquiry_emails'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['sent_at', 'meta', 'attempts', 'status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        foreach (['daily_listing_emails', 'daily_enquiry_emails'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->timestamp('sent_at')->nullable()->index();
                $table->json('meta')->nullable();
                $table->smallInteger('attempts')->default(0);
                $table->enum('status', ['sent', 'failed'])->default('sent');
            });
        }
    }
};
