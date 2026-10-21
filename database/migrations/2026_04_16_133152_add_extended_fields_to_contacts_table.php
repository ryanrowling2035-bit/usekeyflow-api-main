<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('contactOwner')->nullable()->after('residencyStatus')->constrained('users')->nullOnDelete();
            $table->string('leadSourceDetails', 255)->nullable()->after('contactOwner');
            $table->boolean('bayutWhatsappInitiated')->default(false)->after('leadSourceDetails');
            $table->boolean('tenantWhatsappInitiated')->default(false)->after('bayutWhatsappInitiated');
            $table->boolean('offPlanWhatsappInitiated')->default(false)->after('tenantWhatsappInitiated');
            $table->date('lastContacted')->nullable()->after('offPlanWhatsappInitiated');
            $table->string('routingSource', 255)->nullable()->after('lastContacted');
            $table->string('outcome', 100)->nullable()->after('routingSource');
            $table->string('qualificationContact', 100)->nullable()->after('outcome');
            $table->string('leadRating', 50)->nullable()->after('qualificationContact');
            $table->boolean('whatsappLead')->default(false)->after('leadRating');
            $table->string('referralSource', 255)->nullable()->after('whatsappLead');
            $table->string('referralCompany', 255)->nullable()->after('referralSource');
            $table->string('referralPercentDue', 50)->nullable()->after('referralCompany');
            $table->string('referralContact', 255)->nullable()->after('referralPercentDue');
            $table->string('referralFeePaidThru', 255)->nullable()->after('referralContact');
            $table->string('country', 255)->nullable()->after('referralFeePaidThru');
            $table->string('state', 255)->nullable()->after('country');
            $table->string('city', 255)->nullable()->after('state');
            $table->string('zipCode', 20)->nullable()->after('city');
            $table->text('otherAddress')->nullable()->after('zipCode');
            $table->string('otherCountry', 255)->nullable()->after('otherAddress');
            $table->string('otherState', 255)->nullable()->after('otherCountry');
            $table->string('otherCity', 255)->nullable()->after('otherState');
            $table->string('otherZipCode', 20)->nullable()->after('otherCity');
            $table->string('company', 255)->nullable()->after('otherZipCode');
            $table->boolean('pastClient')->default(false)->after('company');
            $table->string('title', 255)->nullable()->after('pastClient');
            $table->json('communityPreference')->nullable()->after('title');
            $table->string('companyName', 255)->nullable()->after('communityPreference');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['contactOwner']);
            $table->dropColumn([
                'contactOwner',
                'leadSourceDetails',
                'bayutWhatsappInitiated',
                'tenantWhatsappInitiated',
                'offPlanWhatsappInitiated',
                'lastContacted',
                'routingSource',
                'outcome',
                'qualificationContact',
                'leadRating',
                'whatsappLead',
                'referralSource',
                'referralCompany',
                'referralPercentDue',
                'referralContact',
                'referralFeePaidThru',
                'country',
                'state',
                'city',
                'zipCode',
                'otherAddress',
                'otherCountry',
                'otherState',
                'otherCity',
                'otherZipCode',
                'company',
                'pastClient',
                'title',
                'communityPreference',
                'companyName',
            ]);
        });
    }
};
