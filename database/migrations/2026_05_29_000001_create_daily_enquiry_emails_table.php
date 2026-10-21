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
        Schema::create('daily_enquiry_emails', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agent_id')->index();
            $table->unsignedBigInteger('enquiry_id')->index();
            $table->unsignedBigInteger('property_id')->nullable()->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->enum('status', ['sent', 'failed'])->default('sent');
            $table->timestamps();

            $table->foreign('agent_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('enquiry_id')->references('id')->on('enquiries')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('daily_enquiry_emails');
    }
};
