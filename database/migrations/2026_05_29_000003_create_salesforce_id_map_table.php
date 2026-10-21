<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('salesforce_id_map', function (Blueprint $table) {
            $table->id();
            $table->string('objectType');           // Salesforce object: Account, Contact, Lead, Opportunity, User, etc.
            $table->string('salesforceId', 18);      // 18-char Salesforce record id
            $table->string('module');                // Target CRM module: Contact, Enquiry, Property, Offer, User
            $table->unsignedBigInteger('moduleId');  // Primary key of the record within that CRM module
            $table->timestamps();

            $table->unique(['objectType', 'salesforceId']);
            $table->index('salesforceId');
            $table->index(['module', 'moduleId']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('salesforce_id_map');
    }
};
