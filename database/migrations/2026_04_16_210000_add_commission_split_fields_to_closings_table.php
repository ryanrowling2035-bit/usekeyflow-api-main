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
        Schema::table('closings', function (Blueprint $table) {
            $table->string('representing')->nullable()->after('totalCommission');
            $table->decimal('closingProbability', 5, 2)->nullable()->after('representing');
            $table->decimal('agentCommission', 15, 2)->nullable()->after('closingProbability');
            $table->decimal('companyCommission', 15, 2)->nullable()->after('agentCommission');
            $table->decimal('sellerLandlordSideSplitAmount', 15, 2)->nullable()->after('companyCommission');
            $table->decimal('buyerTenantSideSplitAmount', 15, 2)->nullable()->after('sellerLandlordSideSplitAmount');
            $table->decimal('sellerAgentSplitPercentage', 5, 2)->nullable()->after('buyerTenantSideSplitAmount');
            $table->decimal('companySellerSplitPercentage', 5, 2)->nullable()->after('sellerAgentSplitPercentage');
            $table->decimal('sellerAgentSplitAmount', 15, 2)->nullable()->after('companySellerSplitPercentage');
            $table->decimal('companySellerSplitAmount', 15, 2)->nullable()->after('sellerAgentSplitAmount');
            $table->decimal('buyerAgentSplitPercentage', 5, 2)->nullable()->after('companySellerSplitAmount');
            $table->decimal('companyBuyerSplitPercentage', 5, 2)->nullable()->after('buyerAgentSplitPercentage');
            $table->decimal('buyerAgentSplitAmount', 15, 2)->nullable()->after('companyBuyerSplitPercentage');
            $table->decimal('companyBuyerSplitAmount', 15, 2)->nullable()->after('buyerAgentSplitAmount');
            $table->string('escrowTitleContact')->nullable()->after('companyBuyerSplitAmount');
            $table->string('buyerTenantAgencyContact')->nullable()->after('escrowTitleContact');
            $table->string('mortgageBrokerContact')->nullable()->after('buyerTenantAgencyContact');
            $table->string('sellerLandlordSource')->nullable()->after('mortgageBrokerContact');
            $table->string('closingUrlRecord')->nullable()->after('sellerLandlordSource');
            $table->string('owner')->nullable()->after('closingUrlRecord');
            $table->unsignedBigInteger('buyerTenant')->nullable()->after('owner');
            $table->string('listingAgencyContact')->nullable()->after('buyerTenant');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('closings', function (Blueprint $table) {
            $table->dropColumn([
                'representing',
                'closingProbability',
                'agentCommission',
                'companyCommission',
                'sellerLandlordSideSplitAmount',
                'buyerTenantSideSplitAmount',
                'sellerAgentSplitPercentage',
                'companySellerSplitPercentage',
                'sellerAgentSplitAmount',
                'companySellerSplitAmount',
                'buyerAgentSplitPercentage',
                'companyBuyerSplitPercentage',
                'buyerAgentSplitAmount',
                'companyBuyerSplitAmount',
                'escrowTitleContact',
                'buyerTenantAgencyContact',
                'mortgageBrokerContact',
                'sellerLandlordSource',
                'closingUrlRecord',
                'owner',
                'buyerTenant',
                'listingAgencyContact',
            ]);
        });
    }
};
