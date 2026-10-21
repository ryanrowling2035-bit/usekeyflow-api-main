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
        Schema::table('enquiries', function (Blueprint $table) {
            $table->string('leadQualification')->nullable()->after('building');
            $table->string('projectName')->nullable()->after('leadQualification');
            $table->string('contactMethod')->nullable()->after('projectName');
            $table->string('webUrl')->nullable()->after('contactMethod');
            $table->string('propertyPropertyFinder')->nullable()->after('webUrl');
            $table->string('recordType')->nullable()->after('propertyPropertyFinder');
            $table->string('extras')->nullable()->after('recordType');
            $table->string('leadRating')->nullable()->after('extras');
            $table->string('leadOutcome')->nullable()->after('leadRating');
            $table->string('outcome')->nullable()->after('leadOutcome');
            $table->string('enquiryRecordUrl')->nullable()->after('outcome');
            $table->string('filterNumber')->nullable()->after('enquiryRecordUrl');
            $table->boolean('smsOptOut')->default(false)->after('filterNumber');
            $table->string('unitNumber')->nullable()->after('smsOptOut');
            $table->string('unitType')->nullable()->after('unitNumber');
            $table->integer('yearBuiltMin')->nullable()->after('unitType');
            $table->integer('yearBuiltMax')->nullable()->after('yearBuiltMin');
            $table->integer('floorMin')->nullable()->after('yearBuiltMax');
            $table->integer('floorMax')->nullable()->after('floorMin');
            $table->integer('parkingSpaceMin')->nullable()->after('floorMax');
            $table->integer('parkingSpaceMax')->nullable()->after('parkingSpaceMin');
            $table->json('privateAmenities')->nullable()->after('parkingSpaceMax');
            $table->string('view')->nullable()->after('privateAmenities');
            $table->string('furnished')->nullable()->after('view');
            $table->string('elPartners')->nullable()->after('furnished');
            $table->string('partnersEmail')->nullable()->after('elPartners');
            $table->string('partnerEmailCc')->nullable()->after('partnersEmail');
            $table->string('investmentMotivation')->nullable()->after('partnerEmailCc');
            $table->string('budget')->nullable()->after('investmentMotivation');
            $table->string('dubaiExperience')->nullable()->after('budget');
            $table->string('timeline')->nullable()->after('dubaiExperience');
            $table->string('apartmentType')->nullable()->after('timeline');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->dropColumn([
                'leadQualification',
                'projectName',
                'contactMethod',
                'webUrl',
                'propertyPropertyFinder',
                'recordType',
                'extras',
                'leadRating',
                'leadOutcome',
                'outcome',
                'enquiryRecordUrl',
                'filterNumber',
                'smsOptOut',
                'unitNumber',
                'unitType',
                'yearBuiltMin',
                'yearBuiltMax',
                'floorMin',
                'floorMax',
                'parkingSpaceMin',
                'parkingSpaceMax',
                'privateAmenities',
                'view',
                'furnished',
                'elPartners',
                'partnersEmail',
                'partnerEmailCc',
                'investmentMotivation',
                'budget',
                'dubaiExperience',
                'timeline',
                'apartmentType',
            ]);
        });
    }
};
