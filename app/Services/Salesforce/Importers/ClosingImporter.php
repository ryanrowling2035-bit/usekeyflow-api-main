<?php

namespace App\Services\Salesforce\Importers;

use App\Constants\Constants;
use Illuminate\Support\Facades\DB;

class ClosingImporter extends AbstractObjectImporter
{
    private const STATUS_MAP = [
        'closed' => Constants::CLOSE_STATUS_CLOSED,
        'open' => Constants::CLOSE_STATUS_OPEN,
        'canceled' => Constants::CLOSE_STATUS_CANCELLED,
        'cancelled' => Constants::CLOSE_STATUS_CANCELLED,
    ];

    private const LISTING_TYPE_MAP = [
        'sale' => Constants::CLOSE_LISTING_TYPE_SALE,
        'rent' => Constants::CLOSE_LISTING_TYPE_RENT,
    ];

    public function objectType(): string
    {
        return 'pba__Closing__c';
    }

    public function module(): string
    {
        return Constants::MODULE_CLOSING;
    }

    public function file(): string
    {
        return 'pba__Closing__c.csv';
    }

    protected function table(): string
    {
        return 'closings';
    }

    protected function hasSoftDeletes(): bool
    {
        return false;
    }

    protected function beforeImport(): void
    {
        $this->loadMap('User');
        $this->loadMap('Contact');
        $this->loadMap('Account');
        $this->loadMap('pba__Listing__c');
        $this->loadMap('pba__Offer__c');
    }

    protected function transform(array $row): ?array
    {
        $listingId = $this->resolve('pba__Listing__c', $row['pba__Listing__c'] ?? null);
        $offerId = $this->resolve('pba__Offer__c', $row['pba__Offer__c'] ?? null);
        $buyerId = $this->resolveContact($row['Buyer_Tenant__c'] ?? null)
            ?? $this->resolveContact($row['Renter__c'] ?? null);

        return [
            'referenceNo' => $this->first($row, ['SystemExternalId__c', 'Name']),
            'name' => $this->buildName($buyerId, $listingId, $row),
            'listingId' => $listingId,
            'offerId' => $offerId,
            'buyerId' => $buyerId,
            'buyerTenant' => $this->resolveContact($row['Buyer_Tenant__c'] ?? null)
                ?? $this->resolveContact($row['Renter__c'] ?? null),
            'sellerLandlord' => $this->resolveContact($row['Seller__c'] ?? null),
            'status' => $this->lookup($row['Closing_Status__c'] ?? null, self::STATUS_MAP, Constants::CLOSE_STATUS_OPEN),
            'listingType' => $this->lookup($row['Listing_Type__c'] ?? null, self::LISTING_TYPE_MAP, Constants::CLOSE_LISTING_TYPE_SALE),
            'representing' => $this->str($row['Representing__c'] ?? null, 255),
            'targetCloseDate' => $this->parseDate($row['TargetClosingDate__c'] ?? null),
            'closingDate' => $this->parseDate($row['ClosingDate__c'] ?? null),
            'assignedAgentId' => $this->resolve('User', $row['OwnerId'] ?? null),
            'clientNationality' => $this->first($row, ['Client_s_Nationality__c', 'Buyer_Tenant_Nationality__c']),
            'saleRentalPrice' => $this->num($this->first($row, ['Sale_Price__c', 'Rental_Price__c'])),
            'closingProbability' => $this->num($row['Closing_Probability__c'] ?? null),
            'agentCommission' => $this->num($row['Agent_Commission__c'] ?? null),
            'companyCommission' => $this->num($row['Company_Commission__c'] ?? null),
            'totalCommission' => $this->num($this->first($row, ['Total_Commission__c', 'Total_Commission_EL__c'])),
            'sellerLandlordSideSplitAmount' => $this->num($row['Seller_Landlord_Side_Split_Amount__c'] ?? null),
            'buyerTenantSideSplitAmount' => $this->num($row['Buyer_Tenant_Side_Split_Amount__c'] ?? null),
            'sellerAgentSplitPercentage' => $this->num($row['Seller_Agent_Split_Percent__c'] ?? null),
            'companySellerSplitPercentage' => $this->num($row['Company_Seller_Split_Percent__c'] ?? null),
            'buyerAgentSplitPercentage' => $this->num($row['Buyer_Agent_Split_Percent__c'] ?? null),
            'companyBuyerSplitPercentage' => $this->num($row['Company_Buyer_Split_Percent__c'] ?? null),
            'createdBy' => $this->resolve('User', $this->first($row, ['CreatedById', 'OwnerId'])),
        ];
    }

    /**
     * Closing title = "Buyer name - listing unit number".
     * The unit number is the listing's pfUnitNumber meta value. Falls back to the
     * Salesforce Name when neither part resolves.
     */
    private function buildName(?int $buyerId, ?int $listingId, array $row): ?string
    {
        $buyer = $buyerId !== null
            ? (DB::table('contacts')->where('id', $buyerId)->value('name') ?? '')
            : '';
        $unitNumber = $listingId !== null
            ? (DB::table('property_meta')
                ->where('propertyId', $listingId)
                ->where('metaKey', 'pfUnitNumber')
                ->value('metaValue') ?? '')
            : '';

        $name = trim($buyer.' - '.$unitNumber, ' -');

        return $name !== ''
            ? mb_substr($name, 0, 255)
            : $this->str($row['Name'] ?? null, 255);
    }
}
