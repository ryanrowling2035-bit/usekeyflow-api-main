<?php

namespace App\Services\Salesforce\Importers;

use App\Constants\Constants;
use Illuminate\Support\Facades\DB;

class OfferImporter extends AbstractObjectImporter
{
    private const STATUS_MAP = [
        'accepted' => Constants::OFFER_STATUS_ACCEPTED,
        'active' => Constants::OFFER_STATUS_NEW,
        'new' => Constants::OFFER_STATUS_NEW,
        'cancelled' => Constants::OFFER_STATUS_CANCELLED,
        'rejected' => Constants::OFFER_STATUS_REJECTED,
    ];

    private const LISTING_TYPE_MAP = [
        'rent' => 'rent',
        'sale' => 'sale',
        'off plan' => 'sale',
    ];

    public function objectType(): string
    {
        return 'pba__Offer__c';
    }

    public function module(): string
    {
        return Constants::MODULE_OFFER;
    }

    public function file(): string
    {
        return 'pba__Offer__c.csv';
    }

    protected function table(): string
    {
        return 'offers';
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
        $this->loadMap('pba__Request__c');
    }

    protected function transform(array $row): ?array
    {
        // offers.buyerId is required; skip offers whose buyer can't be resolved.
        $buyerId = $this->resolve('Contact', $row['pba__Contact__c'] ?? null)
            ?? $this->resolve('Contact', $row['Potential_Renter__c'] ?? null);
        if ($buyerId === null) {
            return null;
        }

        $listingId = $this->resolve('pba__Listing__c', $row['pba__Listing__c'] ?? null);

        return [
            'name' => $this->buildName($buyerId, $listingId, $row),
            'listingId' => $listingId,
            'enquiryId' => $this->resolve('pba__Request__c', $row['pba__Request__c'] ?? null),
            'buyerId' => $buyerId,
            'sellerLandlord' => $this->resolveContact($row['Seller_Landlord__c'] ?? null),
            'offeredAmount' => $this->num($this->first($row, ['pba__OfferedAmount_pb__c', 'Price__c'])),
            'listingPrice' => $this->num($row['Price__c'] ?? null),
            'status' => $this->resolveStatus($row),
            'listingType' => $this->lookup($row['Listing_Type__c'] ?? null, self::LISTING_TYPE_MAP),
            'offerDate' => $this->parseDate($row['Offer_Date__c'] ?? null),
            'offerExpirationDate' => $this->parseDate($row['Offer_Expiration_Date__c'] ?? null),
            'targetDate' => $this->parseDate($this->first($row, ['Close_Date__c', 'StartingDate__c'])),
            'leaseTerms' => $this->first($row, ['Lease_Terms__c', 'Terms__c']),
            'terms' => $this->str($row['Terms__c'] ?? null),
            'deposits' => $this->num($row['Deposit__c'] ?? null),
            'referenceNo' => $this->first($row, ['SystemExternalId__c', 'Name']),
            'assignedAgentId' => $this->resolve('User', $row['OwnerId'] ?? null),
            'createdBy' => $this->resolve('User', $this->first($row, ['CreatedById', 'OwnerId'])),
        ];
    }

    /**
     * Offer name = "Buyer & Listing reference" (same format the app uses when an
     * offer is created without an explicit name). Falls back to the Salesforce
     * Name when neither part is available, since the column is required.
     */
    private function buildName(int $buyerId, ?int $listingId, array $row): string
    {
        $buyer = DB::table('contacts')->where('id', $buyerId)->value('name') ?? '';
        $reference = $listingId !== null
            ? (DB::table('properties')->where('id', $listingId)->value('referenceNumber') ?? '')
            : '';
        $name = trim($buyer.' & '.$reference, ' &');

        return $name !== ''
            ? mb_substr($name, 0, 255)
            : ($this->str($row['Name'] ?? null, 255) ?? 'Offer');
    }

    private function resolveStatus(array $row): string
    {
        if ($this->isTrue($row['pba__SystemIsCancelled__c'] ?? null)) {
            return Constants::OFFER_STATUS_CANCELLED;
        }
        if ($this->isTrue($row['pba__SystemIsAccepted__c'] ?? null)) {
            return Constants::OFFER_STATUS_ACCEPTED;
        }

        return $this->lookup($row['pba__Status__c'] ?? null, self::STATUS_MAP, Constants::OFFER_STATUS_NEW);
    }
}
