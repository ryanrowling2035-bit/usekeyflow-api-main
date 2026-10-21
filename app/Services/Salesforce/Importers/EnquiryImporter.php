<?php

namespace App\Services\Salesforce\Importers;

use App\Constants\Constants;
use App\Services\Salesforce\SalesforceCsvReader;
use Illuminate\Support\Facades\DB;

class EnquiryImporter extends AbstractObjectImporter
{
    private const STATUS_MAP = [
        'new lead' => Constants::ENQUIRY_NEW_LEAD,
        'contacting' => Constants::ENQUIRY_CONTACTING,
        'active' => Constants::ENQUIRY_CONTACTING,
        'property search' => Constants::ENQUIRY_PROPERTY_SEARCH,
        'thinking about it' => Constants::ENQUIRY_THINKING_ABOUT_IT,
        'under offer' => Constants::ENQUIRY_UNDER_OFFER,
        'sale progressing' => Constants::ENQUIRY_SALE_PROGRESSING,
        'rented' => Constants::ENQUIRY_RENTED,
        'sold' => Constants::ENQUIRY_SOLD,
        'closed' => Constants::ENQUIRY_SOLD,
        'lost' => Constants::ENQUIRY_LOST,
        'dead' => Constants::ENQUIRY_LOST,
        'found with other broker' => Constants::ENQUIRY_LOST,
        'change of mind' => Constants::ENQUIRY_LOST,
        'not serious' => Constants::ENQUIRY_LOST,
    ];

    private const TYPE_MAP = [
        'tenant' => 'tenant',
        'buyer' => 'buyer',
        'seller' => 'seller',
        'landlord' => 'landlord',
        'rent' => 'tenant',
        'sale' => 'buyer',
        'agent' => 'agent',
    ];

    private const LISTING_TYPE_MAP = [
        'rent' => 'rent',
        'sale' => 'sale',
        'buy' => 'sale',
        'buyer' => 'sale',
        'seller' => 'sale',
    ];

    private const CONTACT_METHOD_MAP = [
        'phone' => 'call',
        'call' => 'call',
        'email' => 'email',
    ];

    /** Funnel stages from "Property Search" onward — these count as qualified leads. */
    private const QUALIFIED_STATUSES = [
        Constants::ENQUIRY_PROPERTY_SEARCH,
        Constants::ENQUIRY_THINKING_ABOUT_IT,
        Constants::ENQUIRY_UNDER_OFFER,
        Constants::ENQUIRY_SALE_PROGRESSING,
        Constants::ENQUIRY_RENTED,
        Constants::ENQUIRY_SOLD,
    ];

    private const COMMERCIAL_KEYWORDS = ['office', 'shop', 'retail', 'warehouse', 'commercial', 'showroom', 'factory', 'labor', 'business', 'clinic', 'restaurant'];

    public function objectType(): string
    {
        return 'pba__Request__c';
    }

    public function module(): string
    {
        return Constants::MODULE_ENQUIRY;
    }

    public function file(): string
    {
        return 'pba__Request__c.csv';
    }

    protected function table(): string
    {
        return 'enquiries';
    }

    /** properties.referenceNumber => property id, to resolve a listing from Reference_Number__c. */
    private array $propertyByRef = [];

    /** contacts.id => phone, to copy the connected contact's phone onto the enquiry. */
    private array $phoneByContactId = [];

    /** pba__Location__c Id => location name, to turn a Community_N__c lookup into a name. */
    private array $locationNameById = [];

    /** lower(community name) => property_communities.communityId, the value the app stores. */
    private array $communityIdByName = [];

    protected function beforeImport(): void
    {
        $this->loadMap('User');
        $this->loadMap('Contact');
        $this->loadMap('Account');
        $this->loadMap('pba__Listing__c');

        $this->propertyByRef = DB::table('properties')
            ->whereNotNull('referenceNumber')
            ->where('referenceNumber', '!=', '')
            ->pluck('id', 'referenceNumber')
            ->map(fn ($v) => (int) $v)
            ->all();

        $this->phoneByContactId = DB::table('contacts')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->pluck('phone', 'id')
            ->all();

        $this->loadCommunityMaps();
    }

    /**
     * Build the maps used to resolve Community_1/2/3__c: pba__Location__c id -> name (from the
     * location CSV) and name -> property_communities.communityId (the value the app stores, the
     * same one listings put in pfCommunity). Public so the standalone enquiry-community backfill
     * command can reuse this resolver as the single source of truth.
     */
    public function loadCommunityMaps(): void
    {
        $path = rtrim($this->sourceDir, '/').'/pba__Location__c.csv';
        if (is_file($path)) {
            foreach (SalesforceCsvReader::rows($path) as $row) {
                $id = trim((string) ($row['Id'] ?? ''));
                $name = trim((string) ($row['Name'] ?? ''));
                if ($id !== '' && $name !== '') {
                    $this->locationNameById[$id] = $name;
                }
            }
        }

        foreach (DB::table('property_communities')->whereNotNull('name')->get(['communityId', 'name']) as $r) {
            $this->communityIdByName[strtolower(trim($r->name))] ??= $r->communityId;
        }
    }

    /**
     * Resolve the row's Community_1/2/3__c lookups to the stored community ids.
     *
     * @return array{community: ?string, community_2: ?string, community_3: ?string}
     */
    public function resolveCommunities(array $row): array
    {
        return [
            'community' => $this->resolveCommunity($row['Community_1__c'] ?? null),
            'community_2' => $this->resolveCommunity($row['Community_2__c'] ?? null),
            'community_3' => $this->resolveCommunity($row['Community_3__c'] ?? null),
        ];
    }

    /** Resolve a Community_N__c location id to the stored community id (id -> name -> communityId). */
    private function resolveCommunity(?string $locationId): ?string
    {
        $locationId = trim((string) ($locationId ?? ''));
        if ($locationId === '') {
            return null;
        }

        $name = $this->locationNameById[$locationId] ?? null;

        return $name === null ? null : ($this->communityIdByName[strtolower($name)] ?? null);
    }

    /**
     * Resolve the enquiry's listing: the Listing__c lookup first, then — when that is empty —
     * the listing whose reference number matches Reference_Number__c (which holds the listing ref).
     */
    private function resolveListing(array $row): ?int
    {
        $id = $this->resolve('pba__Listing__c', $row['Listing__c'] ?? null);
        if ($id !== null) {
            return $id;
        }

        $ref = trim((string) ($row['Reference_Number__c'] ?? ''));

        return $ref === '' ? null : ($this->propertyByRef[$ref] ?? null);
    }

    protected function transform(array $row): ?array
    {
        $amenities = $this->splitList($row['pba_uaefields__Private_Amenities__c'] ?? null);
        $status = $this->lookup($row['pba__Status__c'] ?? null, self::STATUS_MAP, Constants::ENQUIRY_NEW_LEAD);
        $communities = $this->resolveCommunities($row);
        $contactId = $this->resolveContact($row['pba__Contact__c'] ?? null, $row['pba__Account__c'] ?? null);

        return [
            'referenceNo' => $this->str($row['Name'] ?? null),
            'agentId' => $this->resolve('User', $row['OwnerId'] ?? null),
            'contactId' => $contactId,
            'phone' => $contactId !== null ? ($this->phoneByContactId[$contactId] ?? null) : null,
            'listingId' => $this->resolveListing($row),
            'enquiryType' => $this->lookup($row['pba__Type_pb__c'] ?? null, self::TYPE_MAP),
            'status' => $status,
            'listingType' => $this->lookup($row['pba__ListingType__c'] ?? null, self::LISTING_TYPE_MAP),
            'enquiryPropertyType' => $this->resolveCategory($row['pba__PropertyType__c'] ?? null),
            'enquiryPropertySubType' => $this->first($row, ['pba__SubType_pb__c', 'pba_uaefields__Property_Sub_Type__c', 'pba__PropertyType__c']),
            'community' => $communities['community'],
            'community_2' => $communities['community_2'],
            'community_3' => $communities['community_3'],
            'priceMin' => $this->num($this->first($row, ['pba__ListingPrice_pb_min__c', 'pba__MonthlyRentMin_pb__c'])),
            'priceMax' => $this->num($this->first($row, ['pba__ListingPrice_pb_max__c', 'pba__MonthlyRentMax_pb__c'])),
            'sizeMin' => $this->num($row['pba__TotalArea_pb_min__c'] ?? null),
            'sizeMax' => $this->num($row['pba__TotalArea_pb_max__c'] ?? null),
            'bedroomsMin' => $this->intval($row['pba__Bedrooms_pb_min__c'] ?? null),
            'bedroomsMax' => $this->intval($row['pba__Bedrooms_pb_max__c'] ?? null),
            'bathroomsMin' => $this->intval($row['pba__FullBathrooms_pb_min__c'] ?? null),
            'bathroomsMax' => $this->intval($row['pba__FullBathrooms_pb_max__c'] ?? null),
            'yearBuiltMin' => $this->str($row['pba__YearBuilt_pb_min__c'] ?? null, 20),
            'yearBuiltMax' => $this->str($row['pba__YearBuilt_pb_max__c'] ?? null, 20),
            'floorMin' => $this->intval($row['pba_uaefields__Floor_min__c'] ?? null),
            'floorMax' => $this->intval($row['pba_uaefields__Floor_max__c'] ?? null),
            'parkingSpaceMin' => $this->intval($row['pba_uaefields__Parking_Spaces_min__c'] ?? null),
            'parkingSpaceMax' => $this->intval($row['pba_uaefields__Parking_Spaces_max__c'] ?? null),
            'leadSource' => $this->resolveLeadSource($this->first($row, ['Lead_Source__c', 'pba__Enquiry_Source_pb__c'])),
            'technicalSource' => $this->lookup($row['Contact_Method__c'] ?? null, self::CONTACT_METHOD_MAP),
            'contactMethod' => $this->str($row['Contact_Method__c'] ?? null, 100),
            'comments' => $this->first($row, ['pba__Comments__c', 'Activity_Comments__c']),
            'lostReason' => $this->str($row['Lost_Reason__c'] ?? null),
            'referredBy' => $this->str($row['Referred_by__c'] ?? null),
            'webUrl' => $this->str($row['Web_URL__c'] ?? null),
            'propertyPropertyFinder' => $this->str($row['pba_uaefields__Property_Propertyfinder__c'] ?? null),
            'projectName' => $this->str($row['Legacy_Subject__c'] ?? null),
            // extras is a JSON column (json_valid check); encode the raw text as a JSON string.
            'extras' => ($x = $this->str($row['Extras__c'] ?? null)) !== null ? json_encode($x) : null,
            'leadRating' => $this->str($row['Lead_Rating_pb__c'] ?? null, 50),
            'leadOutcome' => $this->str($row['Lead_Outcome__c'] ?? null),
            'outcome' => $this->str($row['OUTCOME__c'] ?? null),
            'filterNumber' => $this->str($row['Filter_Number__c'] ?? null, 50),
            'unitNumber' => $this->str($row['Unit_Number__c'] ?? null, 100),
            'unitType' => $this->str($row['Unit_Type__c'] ?? null),
            'view' => $this->str($row['pba__View_pb__c'] ?? null),
            'furnished' => $this->first($row, ['pba_uaefields__Furnished__c', 'pba__Furnished_pb__c']),
            'elPartners' => $this->str($row['EL_Partners__c'] ?? null),
            'partnersEmail' => $this->str($row['Partners_Email__c'] ?? null, 255),
            'investmentMotivation' => $this->str($row['Investment_Motivation__c'] ?? null),
            'budget' => $this->str($row['Budget__c'] ?? null),
            'dubaiExperience' => $this->str($row['Dubai_Experience__c'] ?? null),
            'timeline' => $this->str($row['Timeline__c'] ?? null),
            'apartmentType' => $this->str($row['Apartment_Type__c'] ?? null),
            'privateAmenities' => $amenities ? json_encode($amenities) : null,
            'smsOptOut' => (int) $this->isTrue($row['SMS_Opt_out__c'] ?? null),
            'isOpenGame' => (int) $this->isTrue($row['Open_Game__c'] ?? null),
            'isActive' => (int) $this->isTrue($row['pba__SystemIsActive__c'] ?? null),
            'isQualified' => (int) in_array($status, self::QUALIFIED_STATUSES, true),
            'flyerSent' => 0,
            'createdBy' => $this->resolve('User', $this->first($row, ['CreatedById', 'OwnerId'])),
        ];
    }

    /**
     * Preserve an enquiry's CRM-managed state on re-import. These columns are driven by the
     * CRM after the enquiry is created — lifecycle status and lead qualification (EnquiryService,
     * TaskService), Open Game ownership/timers (UpdateEnquiryOpenGameStatus, takeOwnership), agent
     * reassignment, the lost reason set when a colleague closes the same client, and the flyer-sent
     * flag — so they must not be reverted to the Salesforce pba__Request__c values on a re-run.
     */
    protected function insertOnlyColumns(): array
    {
        return [
            'status',
            'agentId',
            'isOpenGame',
            'isActive',
            'isQualified',
            'lostReason',
            'flyerSent',
        ];
    }

    private function resolveCategory(?string $raw): ?string
    {
        $raw = strtolower(trim((string) ($raw ?? '')));
        if ($raw === '') {
            return null;
        }
        foreach (self::COMMERCIAL_KEYWORDS as $kw) {
            if (str_contains($raw, $kw)) {
                return Constants::PROPERTY_CATEGORY_COMMERCIAL;
            }
        }

        return Constants::PROPERTY_CATEGORY_RESIDENTIAL;
    }

    private function resolveLeadSource(?string $raw): ?string
    {
        $raw = strtolower(trim((string) ($raw ?? '')));
        if ($raw === '') {
            return null;
        }
        if (str_contains($raw, 'propertyfinder') || str_contains($raw, 'property finder')) {
            return Constants::LEAD_SOURCE_PROPERTY_FINDER;
        }
        if (str_contains($raw, 'bayut')) {
            return Constants::LEAD_SOURCE_BAYUT;
        }
        if (str_contains($raw, 'whatsapp')) {
            return Constants::LEAD_SOURCE_WHATSAPP;
        }
        if (str_contains($raw, 'email')) {
            return Constants::LEAD_SOURCE_EMAIL;
        }
        if (str_contains($raw, 'phone') || str_contains($raw, 'call')) {
            return Constants::LEAD_SOURCE_PHONE_CALL;
        }

        return Constants::LEAD_SOURCE_MANUAL;
    }

    private function splitList(?string $raw): array
    {
        $raw = trim((string) ($raw ?? ''));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/;/', $raw))));
    }
}
