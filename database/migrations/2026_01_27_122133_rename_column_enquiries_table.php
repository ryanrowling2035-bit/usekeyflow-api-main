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
            if (Schema::hasColumn('enquiries', 'propertyCategoryId')) {
                $table->renameColumn('propertyCategoryId', 'enquiryPropertyType');
            }

            if (Schema::hasColumn('enquiries', 'propertySubCategoryId')) {
                $table->renameColumn('propertySubCategoryId', 'enquiryPropertySubType');
            }
        });
    }

    public function down(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            if (Schema::hasColumn('enquiries', 'enquiryPropertyType')) {
                $table->renameColumn('enquiryPropertyType', 'propertyCategoryId');
            }

            if (Schema::hasColumn('enquiries', 'enquiryPropertySubType')) {
                $table->renameColumn('enquiryPropertySubType', 'propertySubCategoryId');
            }
        });
    }
};
