<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->renameColumn('listingPurposeType', 'listingType');
            $table->renameColumn('numberOfCheques', 'pfCheques');
            $table->renameColumn('occupancyStatus', 'bayutOccupancyStatus');
            $table->renameColumn('minContract', 'bayutMinimumContractPeriod');
            $table->renameColumn('noticePeriod', 'bayutVacatingNoticePeriod');
            $table->renameColumn('maintenanceFee', 'bayutMaintenanceFee');
            $table->renameColumn('maintenancePaidBy', 'bayutMaintenanceFeePaidBy');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->renameColumn('listingType', 'listingPurposeType');
            $table->renameColumn('pfCheques', 'numberOfCheques');
            $table->renameColumn('bayutOccupancyStatus', 'occupancyStatus');
            $table->renameColumn('bayutMinimumContractPeriod', 'minContract');
            $table->renameColumn('bayutVacatingNoticePeriod', 'noticePeriod');
            $table->renameColumn('bayutMaintenanceFee', 'maintenanceFee');
            $table->renameColumn('bayutMaintenanceFeePaidBy', 'maintenancePaidBy');
        });
    }
};
