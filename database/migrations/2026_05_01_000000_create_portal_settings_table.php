<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('portal_settings', function (Blueprint $table) {
            $table->id();
            $table->string('portal', 50);
            $table->string('branchName');
            $table->text('bayutSecretToken')->nullable();
            $table->string('bayutSecretTokenPublic', 4)->nullable();
            $table->text('pfApiKey')->nullable();
            $table->string('pfApiKeyPublic', 4)->nullable();
            $table->text('pfApiSecret')->nullable();
            $table->string('pfApiSecretPublic', 4)->nullable();
            $table->timestamp('createdAt')->nullable();
            $table->timestamp('updatedAt')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('portal_settings');
    }
};
