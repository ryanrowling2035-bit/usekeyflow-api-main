<?php

namespace App\Services\Salesforce\Importers;

use App\Constants\Constants;
use App\Services\Salesforce\SalesforceCsvReader;
use Illuminate\Support\Facades\DB;

class ListingImporter extends AbstractObjectImporter
{
    protected const STATUS_MAP = [
        'unpublished' => Constants::PROPERTY_STATUS_UNPUBLISHED,
        'archived' => Constants::PROPERTY_STATUS_UNPUBLISHED,
        'pocket listing' => Constants::PROPERTY_STATUS_POCKET_LISTING,
        'upcoming' => Constants::PROPERTY_STATUS_UPCOMING,
        'rented' => Constants::PROPERTY_STATUS_RENTED_SOLD,
        'sold' => Constants::PROPERTY_STATUS_RENTED_SOLD,
        'active' => Constants::PROPERTY_STATUS_PUBLISHED,
        'draft' => Constants::PROPERTY_STATUS_DRAFT,
        'live on website' => Constants::PROPERTY_STATUS_LIVE_ON_WEBSITE,
        'under offer' => Constants::PROPERTY_STATUS_UNDER_OFFER,
        'under contract' => Constants::PROPERTY_STATUS_UNDER_OFFER,
        'pending' => Constants::PROPERTY_STATUS_PENDING,
    ];

    private const PURPOSE_MAP = [
        'sale' => Constants::PROPERTY_PURPOSE_SALE,
        'rent' => Constants::PROPERTY_PURPOSE_RENT,
        'off plan' => Constants::PROPERTY_PURPOSE_OFFPLAN,
        'international' => Constants::PROPERTY_PURPOSE_INTERNATIONAL,
    ];

    // Salesforce "Price <Period>" picklist -> our pfPriceFrequency keys
    // (Constants::PROPERTY_PRICE_FREQUENCIES).
    private const PRICE_UNIT_MAP = [
        'price yearly' => 'yearly',
        'price monthly' => 'monthly',
        'price quarterly' => 'quarterly',
        'price semiannually' => 'semiannually',
        'price weekly' => 'weekly',
        'price daily' => 'daily',
    ];

    // Salesforce pba_uaefields__Furnished__c (Yes/No/Partly) -> furnishing key, matching the
    // keys BayutService and the CRM resource expect (Constants::PROPERTY_FURNISHING_TYPE_BAYUT).
    private const BAYUT_FURNISHING_MAP = [
        'yes' => 'furnished',
        'no' => 'unfurnished',
        'partly' => 'semi-furnished',
    ];

    private const MANAGED_MAP = [
        'managed' => Constants::MANAGED_TYPE_MANAGED,
        'vacation homes' => Constants::MANAGED_TYPE_VACATION_HOMES,
        'exclusive' => Constants::MANAGED_TYPE_EXCLUSIVE,
        'elite' => Constants::MANAGED_TYPE_ELITE,
        'special offer' => Constants::MANAGED_TYPE_SPECIAL_OFFERS,
        'hot' => Constants::MANAGED_TYPE_FEATURE,
        'feature' => Constants::MANAGED_TYPE_FEATURE,
        'arabic' => Constants::MANAGED_TYPE_ARABIC,
        'french' => Constants::MANAGED_TYPE_FRENCH,
        'polish' => Constants::MANAGED_TYPE_POLISH,
        'chinese' => Constants::MANAGED_TYPE_CHINESE,
        'russian' => Constants::MANAGED_TYPE_RUSSIAN,
    ];

    /**
     * Salesforce amenity label (normalized: lowercased, alphanumeric only) => CRM portal keys.
     * Drives the pfAmenities / bayutAmenities columns from the Private/Commercial Amenities CSV
     * columns. 'pf' is a {@see Constants::PF_AMENITIES} slug, 'bayut' a {@see Constants::PROPERTY_BAYUT_AMENITIES_KEYS}
     * key; null = no portal equivalent. Bayut labels with no native key route to its other* buckets.
     */
    public const AMENITY_MAP = [
        // Core residential
        'balcony' => ['pf' => 'balcony', 'bayut' => 'balconyOrTerrace'],
        'coveredparking' => ['pf' => 'covered-parking', 'bayut' => 'parkingSpaces'],
        'builtinwardrobes' => ['pf' => 'built-in-wardrobes', 'bayut' => 'otherMainFeatures'],
        'sharedpool' => ['pf' => 'shared-pool', 'bayut' => 'swimmingPool'],
        'sharedgym' => ['pf' => 'shared-gym', 'bayut' => 'gymOrHealthClub'],
        'builtinkitchenappliances' => ['pf' => 'kitchen-appliances', 'bayut' => 'otherMainFeatures'],
        'security' => ['pf' => 'security', 'bayut' => 'securityStaff'],
        'petsallowed' => ['pf' => 'pets-allowed', 'bayut' => 'petPolicy'],
        'viewoflandmark' => ['pf' => 'view-of-landmark', 'bayut' => 'view'],
        'centralacheating' => ['pf' => 'central-ac', 'bayut' => 'centrallyAirConditioned'],
        'shops' => ['pf' => 'shops', 'bayut' => 'otherFacilities'],
        'restaurants' => ['pf' => 'restaurants', 'bayut' => 'cafeteriaOrCanteen'],
        'walkincloset' => ['pf' => 'walk-in-closet', 'bayut' => 'otherMainFeatures'],
        'sharedspa' => ['pf' => 'shared-spa', 'bayut' => 'otherFacilities'],
        'neartoshoppingmall' => ['pf' => 'nearby-shopping-mall', 'bayut' => 'nearbyShoppingMalls'],
        'maidsroom' => ['pf' => 'maids-room', 'bayut' => 'maidsRoom'],
        'conciergeservice' => ['pf' => 'concierge', 'bayut' => 'hours24Concierge'],
        'viewofthewater' => ['pf' => 'view-of-water', 'bayut' => 'view'],
        'privategarden' => ['pf' => 'private-garden', 'bayut' => 'lawnOrGarden'],
        'viewofwater' => ['pf' => 'view-of-water', 'bayut' => 'view'],
        'metrostation' => ['pf' => 'metro-station', 'bayut' => 'nearbyPublicTransport'],
        'study' => ['pf' => 'study', 'bayut' => 'studyRoom'],
        'dininginbuilding' => ['pf' => 'dining-in-building', 'bayut' => 'cafeteriaOrCanteen'],
        '24hrsecuritypersonnel' => ['pf' => 'security', 'bayut' => 'securityStaff'],
        'neartomosque' => ['pf' => 'nearby-mosque', 'bayut' => 'otherNearbyPlaces'],
        'privatepool' => ['pf' => 'private-pool', 'bayut' => 'swimmingPool'],
        'maidservice' => ['pf' => 'maid-service', 'bayut' => 'cleaningServices'],
        'childrensnursery' => ['pf' => 'childrens-nursery', 'bayut' => 'dayCareCenter'],
        'conferenceroom' => ['pf' => 'conference-room', 'bayut' => 'conferenceRoom'],
        '24hrconciergeservices' => ['pf' => 'concierge', 'bayut' => 'hours24Concierge'],
        'squashcourt' => ['pf' => 'squash-court', 'bayut' => 'otherFacilities'],
        'prayerroom' => ['pf' => 'prayer-room', 'bayut' => 'prayerRoom'],
        'privatejacuzzi' => ['pf' => 'private-jacuzzi', 'bayut' => 'jacuzzi'],
        'privategym' => ['pf' => 'private-gym', 'bayut' => 'gymOrHealthClub'],

        // Variants / lower-frequency labels
        'centralairconditioning' => ['pf' => 'central-ac', 'bayut' => 'centrallyAirConditioned'],
        'sharedswimmingpool' => ['pf' => 'shared-pool', 'bayut' => 'swimmingPool'],
        'broadbandready' => ['pf' => null, 'bayut' => 'broadbandInternet'],
        'communityview' => ['pf' => null, 'bayut' => 'view'],
        'privategarage' => ['pf' => 'covered-parking', 'bayut' => 'parkingSpaces'],
        'gymnasium' => ['pf' => 'shared-gym', 'bayut' => 'gymOrHealthClub'],
        'childrensplayarea' => ['pf' => 'childrens-play-area', 'bayut' => 'kidsPlayArea'],
        'fullyfittedkitchen' => ['pf' => 'kitchen-appliances', 'bayut' => 'otherMainFeatures'],
        'kitchenwhitegoods' => ['pf' => 'kitchen-appliances', 'bayut' => 'otherMainFeatures'],
        'viewofseawater' => ['pf' => 'view-of-water', 'bayut' => 'view'],
        'publicparking' => ['pf' => 'covered-parking', 'bayut' => 'parkingSpaces'],
        'marblefloors' => ['pf' => null, 'bayut' => 'flooring'],
        'basementparking' => ['pf' => 'covered-parking', 'bayut' => 'parkingSpaces'],
        'privateswimmingpool' => ['pf' => 'private-pool', 'bayut' => 'swimmingPool'],
        'bbqarea' => ['pf' => 'barbecue-area', 'bayut' => 'barbequeArea'],
        'sauna' => ['pf' => null, 'bayut' => 'sauna'],
        'steamroom' => ['pf' => null, 'bayut' => 'steamRoom'],
        'shoppingmall' => ['pf' => 'nearby-shopping-mall', 'bayut' => 'nearbyShoppingMalls'],
        'jacuzzi' => ['pf' => 'private-jacuzzi', 'bayut' => 'jacuzzi'],
        'publictransport' => ['pf' => null, 'bayut' => 'nearbyPublicTransport'],
        'viewofgardens' => ['pf' => null, 'bayut' => 'view'],
        'mosque' => ['pf' => 'nearby-mosque', 'bayut' => 'otherNearbyPlaces'],
        'onhighfloor' => ['pf' => null, 'bayut' => 'floor'],
        'onmidfloor' => ['pf' => null, 'bayut' => 'floor'],
        'onlowfloor' => ['pf' => null, 'bayut' => 'floor'],
        'fullyfurnished' => ['pf' => 'furnished', 'bayut' => 'furnished'],
        'communalgardens' => ['pf' => null, 'bayut' => 'lawnOrGarden'],
        'publicpark' => ['pf' => null, 'bayut' => 'otherNearbyPlaces'],
        'upgradedinterior' => ['pf' => null, 'bayut' => 'otherMainFeatures'],
        'resturants' => ['pf' => 'restaurants', 'bayut' => 'cafeteriaOrCanteen'],
        'golfclubandclubhouse' => ['pf' => null, 'bayut' => 'otherFacilities'],
        'tenniscourts' => ['pf' => null, 'bayut' => 'otherFacilities'],
        'squashcourts' => ['pf' => 'squash-court', 'bayut' => 'otherFacilities'],
        'driversroom' => ['pf' => null, 'bayut' => 'otherRooms'],
        'gazeboandoutdoorentertainingarea' => ['pf' => null, 'bayut' => 'otherFacilities'],
        'solidwoodfloors' => ['pf' => null, 'bayut' => 'flooring'],
        'professionallylandscapedgarden' => ['pf' => 'private-garden', 'bayut' => 'lawnOrGarden'],
        'poloclubandclubhouse' => ['pf' => null, 'bayut' => 'otherFacilities'],

        // Commercial-only labels
        'retailinbuilding' => ['pf' => 'retail-in-building', 'bayut' => 'otherFacilities'],
        'availablefurnished' => ['pf' => 'furnished', 'bayut' => 'furnished'],
        'availabenetworked' => ['pf' => 'networked', 'bayut' => 'otherMainFeatures'],
    ];

    /** label (lowercase) => canonical type key; first definition wins (residential precedes commercial). */
    private array $typeByLabel = [];

    /** "category|label" (lowercase) => canonical type key, for prefix-qualified lookups. */
    private array $typeByCategoryLabel = [];

    /** pba__Property__c.Id => ['developer'=>?, 'description'=>?, 'name'=>?] */
    private array $projectEnrich = [];

    /** lowercased user email => CRM user id, for agent/owner resolution by email. */
    private array $userIdByEmail = [];

    /**
     * PropertyFinder location lookups, resolving the Salesforce name columns to the
     * external ids stored in pfCity/pfCommunity/pfSubCommunity/pfBuilding. Each level
     * keeps a parent-scoped map ("parentId|name") plus a name-only fallback.
     */
    private array $cityIdByName = [];

    private array $communityIdByName = [];

    private array $communityIdByKey = [];

    private array $subCommunityIdByName = [];

    private array $subCommunityIdByKey = [];

    private array $towerIdByName = [];

    private array $towerIdByKey = [];

    public function objectType(): string
    {
        return 'pba__Listing__c';
    }

    public function module(): string
    {
        return Constants::MODULE_PROPERTY;
    }

    public function file(): string
    {
        return 'pba__Listing__c.csv';
    }

    protected function table(): string
    {
        return 'properties';
    }

    protected function beforeImport(): void
    {
        $this->loadMap('User');
        $this->loadMap('Contact');
        $this->loadMap('Account');

        foreach (Constants::PROPERTY_TYPE_MAPPING as $canonical => $row) {
            $label = strtolower($row['label']);
            $this->typeByCategoryLabel[$row['category'].'|'.$label] = $canonical;
            // First definition wins so a bare, prefix-less label (e.g. "Villa") favours the
            // residential entry over the later commercial duplicate.
            $this->typeByLabel[$label] ??= $canonical;
        }

        foreach (DB::table('users')->whereNotNull('email')->get(['id', 'email']) as $u) {
            $this->userIdByEmail[mb_strtolower($u->email)] = (int) $u->id;
        }

        $this->loadProjectEnrichment();
        $this->loadLocationLookups();
    }

    /**
     * Preload the synced PropertyFinder location tree into in-memory maps so each
     * listing resolves city/community/sub-community/building names without per-row
     * queries. Names are matched case-insensitively; first definition wins.
     */
    private function loadLocationLookups(): void
    {
        foreach (DB::table('pf_location_cities')->whereNotNull('name')->get(['locationId', 'name']) as $r) {
            $this->cityIdByName[mb_strtolower(trim($r->name))] ??= $r->locationId;
        }

        foreach (DB::table('property_communities')->whereNotNull('name')->get(['communityId', 'cityId', 'name']) as $r) {
            $n = mb_strtolower(trim($r->name));
            $this->communityIdByName[$n] ??= $r->communityId;
            if ($r->cityId !== null) {
                $this->communityIdByKey[$r->cityId.'|'.$n] ??= $r->communityId;
            }
        }

        foreach (DB::table('property_sub_communities')->whereNotNull('name')->get(['propertySubCommunityId', 'communityId', 'name']) as $r) {
            $n = mb_strtolower(trim($r->name));
            $this->subCommunityIdByName[$n] ??= $r->propertySubCommunityId;
            if ($r->communityId !== null) {
                $this->subCommunityIdByKey[$r->communityId.'|'.$n] ??= $r->propertySubCommunityId;
            }
        }

        foreach (DB::table('property_towers')->whereNotNull('name')->get(['propertyTowerId', 'subCommunityId', 'name']) as $r) {
            $n = mb_strtolower(trim($r->name));
            $this->towerIdByName[$n] ??= $r->propertyTowerId;
            if ($r->subCommunityId !== null) {
                $this->towerIdByKey[$r->subCommunityId.'|'.$n] ??= $r->propertyTowerId;
            }
        }
    }

    /**
     * Map the Salesforce PropertyFinder name columns to the external location ids
     * stored on the property. Resolution is hierarchical: a child prefers the match
     * scoped to its resolved parent, falling back to a name-only match. Unknown names
     * resolve to null rather than guessing.
     */
    private function resolveLocation(array $row): array
    {
        $pfCity = null;
        if ($cityName = $this->str($row['pba_uaefields__City_Propertyfinder__c'] ?? null)) {
            $pfCity = $this->cityIdByName[mb_strtolower($cityName)] ?? null;
        }

        $pfCommunity = null;
        if ($name = $this->str($row['pba_uaefields__Community_Propertyfinder__c'] ?? null)) {
            $n = mb_strtolower($name);
            $pfCommunity = ($pfCity !== null ? ($this->communityIdByKey[$pfCity.'|'.$n] ?? null) : null)
                ?? ($this->communityIdByName[$n] ?? null);
        }

        $pfSubCommunity = null;
        if ($name = $this->str($row['pba_uaefields__Sub_Community_Propertyfinder__c'] ?? null)) {
            $n = mb_strtolower($name);
            $pfSubCommunity = ($pfCommunity !== null ? ($this->subCommunityIdByKey[$pfCommunity.'|'.$n] ?? null) : null)
                ?? ($this->subCommunityIdByName[$n] ?? null);
        }

        $pfBuilding = null;
        if ($name = $this->str($row['pba_uaefields__Property_Propertyfinder__c'] ?? null)) {
            $n = mb_strtolower($name);
            $pfBuilding = ($pfSubCommunity !== null ? ($this->towerIdByKey[$pfSubCommunity.'|'.$n] ?? null) : null)
                ?? ($this->towerIdByName[$n] ?? null);
        }

        return [
            'pfCity' => $pfCity,
            'pfCommunity' => $pfCommunity,
            'pfSubCommunity' => $pfSubCommunity,
            'pfBuilding' => $pfBuilding,
        ];
    }

    /** Resolve the CRM user id for a listing-agent email, or null when unknown. */
    private function resolveUserByEmail(?string $email): ?int
    {
        $email = mb_strtolower(trim((string) ($email ?? '')));

        return $email === '' ? null : ($this->userIdByEmail[$email] ?? null);
    }

    private function loadProjectEnrichment(): void
    {
        $path = rtrim($this->sourceDir, '/').'/pba__Property__c.csv';
        if (! is_file($path)) {
            return;
        }
        // First pass: keep each project's raw developer value and collect the developer Account
        // ids so they can be resolved to names in one Account.csv scan. pba_uaefields__Developer__c
        // is an Account lookup (a "001…" id, e.g. OMNIYAT/Emaar), not a text name.
        $rows = [];
        $developerIds = [];
        foreach (SalesforceCsvReader::rows($path) as $row) {
            $id = trim((string) ($row['Id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $developer = $this->str($row['pba_uaefields__Developer__c'] ?? null);
            if ($developer !== null) {
                $developerIds[$developer] = true;
            }
            $rows[$id] = [
                'developer' => $developer,
                'description' => $this->str($row['pba__Description_pb__c'] ?? null),
                'name' => $this->str($row['Name'] ?? null),
            ];
        }

        $developerNames = $this->loadAccountNames(array_keys($developerIds));
        foreach ($rows as $id => $r) {
            // Resolve the developer Account id to its name; leave a value that isn't a known
            // Account id as-is (older projects may store a plain developer name).
            $r['developer'] = $r['developer'] !== null
                ? ($developerNames[$r['developer']] ?? $r['developer'])
                : null;
            $this->projectEnrich[$id] = $r;
        }
    }

    /**
     * Resolve the given Salesforce Account ids to their names from Account.csv in a single scan,
     * for developer-name resolution. Returns id => name for the ids that were found.
     *
     * @param  array<int, string>  $ids
     * @return array<string, string|null>
     */
    private function loadAccountNames(array $ids): array
    {
        $path = rtrim($this->sourceDir, '/').'/Account.csv';
        if ($ids === [] || ! is_file($path)) {
            return [];
        }
        $want = array_flip($ids);
        $names = [];
        foreach (SalesforceCsvReader::rows($path) as $row) {
            $id = trim((string) ($row['Id'] ?? ''));
            if (isset($want[$id])) {
                $names[$id] = $this->str($row['Name'] ?? null);
                if (count($names) === count($want)) {
                    break;
                }
            }
        }

        return $names;
    }

    protected function transform(array $row): ?array
    {
        $type = $this->resolveType($this->first($row, ['pba_uaefields__Property_Sub_Type__c', 'Property_Type__c', 'pba__PropertyType__c']));
        $status = $this->lookup($row['pba__Status__c'] ?? null, self::STATUS_MAP, Constants::PROPERTY_STATUS_DRAFT);
        $project = $this->projectEnrich[trim((string) ($row['pba__Property__c'] ?? ''))] ?? [];

        $sold = $status === Constants::PROPERTY_STATUS_RENTED_SOLD || $this->isTrue($row['Is_Sold__c'] ?? null);

        // Agent and listing owner both resolve to the CRM user matched by the
        // listing agent's email rather than the Salesforce User id.
        $agentUserId = $this->resolveUserByEmail($row['pba__Listing_Agent_Email__c'] ?? null);

        $location = $this->resolveLocation($row);

        $amenities = $this->resolveAmenities($row);

        return [
            'title' => mb_substr($this->first($row, ['Marketing_Title__c', 'Name']) ?? 'Untitled', 0, 255),
            'description' => $this->first($row, ['Description_Rich__c', 'pba__Description_pb__c', 'Short_Description__c'])
                ?? ($project['description'] ?? null),
            'referenceNumber' => $this->first($row, ['pba_uaefields__Broker_s_Listing_ID__c', 'Old_prop_ref_no__c', 'Name']),
            'category' => $type['category'],
            'listingType' => $this->lookup($row['pba__ListingType__c'] ?? null, self::PURPOSE_MAP, Constants::PROPERTY_PURPOSE_SALE),
            'pfPropertyType' => $type['pf'],
            'bayutPropertyType' => $type['bayut'],
            'sizeArea' => $this->first($row, ['pba__TotalArea_pb__c', 'pba__SqFt_pb__c', 'Net_Area__c']),
            'plotSize' => $this->first($row, ['pba_uaefields__Plot_Size__c', 'pba__LotSize_pb__c']),
            'bedrooms' => $this->resolveBedrooms($row),
            'bathrooms' => $this->intval($this->first($row, ['Num_Baths__c', 'pba__FullBathrooms_pb__c'])),
            'completionStatus' => $this->resolveCompletion($row),
            'price' => $this->num($row['pba__ListingPrice_pb__c'] ?? null),
            'salePrice' => $this->num($row['Projected_Sales_Price__c'] ?? null),
            'annualRent' => $this->num($row['Annual_Rent__c'] ?? null),
            'pfPriceFrequency' => $this->lookup($row['pba_uaefields__Price_Unit__c'] ?? null, self::PRICE_UNIT_MAP),
            'reraPermitNo' => $this->str($row['pba_uaefields__RERA_Permit_Number__c'] ?? null),
            'govtQR' => $this->str($row['QR_Code__c'] ?? null),
            'titleDeedNumber' => $this->str($row['pba_uaefields__Title_Deed__c'] ?? null),
            'pfLatitude' => $this->coord($row['pba__Latitude_pb__c'] ?? null, 90),
            'pfLongitude' => $this->coord($row['pba__Longitude_pb__c'] ?? null, 180),
            'pfCity' => $location['pfCity'],
            'pfCommunity' => $location['pfCommunity'],
            'pfSubCommunity' => $location['pfSubCommunity'],
            'pfBuilding' => $location['pfBuilding'],
            'pfCheques' => $this->str($row['pba_uaefields__Number_Of_Cheques__c'] ?? null, 50),
            'pfAmenities' => $amenities['pf'] ? json_encode($amenities['pf']) : null,
            'bayutAmenities' => $amenities['bayut'] ? json_encode($amenities['bayut']) : null,
            'status' => $status,
            'approvalStatus' => $this->isTrue($row['Approval_Completed__c'] ?? null) ? Constants::APPROVAL_COMPLETED : null,
            'managedType' => $this->resolveManagedType($row['Managed_Type__c'] ?? null),
            'sold' => (int) $sold,
            'access' => $this->str($row['Access__c'] ?? null),
            'listingLeadSource2' => $this->str($row['Listing_Lead_Source_2__c'] ?? null),
            'view' => $this->first($row, ['pba__View_pb__c', 'pba_uaefields__View__c']),
            'developmentText' => $this->first($row, ['Development_Name__c', 'Add_Development_Text__c'])
                ?? ($project['developer'] ?? $project['name'] ?? null),
            'projectText' => $this->first($row, ['Description_Project__c', 'Add_Project_Text__c']),
            'deposit' => $this->num($this->first($row, ['Deposit__c', 'Security_Deposit__c'])),
            'agencyFee' => $this->num($row['Agency_Fee_Commission__c'] ?? null),
            'agencyFeePercent' => $this->num($row['Agency_Fee_Comm_Percent__c'] ?? null),
            'ownershipTerm' => $this->str($row['Ownership_Term__c'] ?? null, 100),
            'yearBuilt' => $this->validYear($row['pba__YearBuilt_pb__c'] ?? null),
            'parkingSpaces' => $this->intval($this->first($row, ['pba__NumberOfParkingSpaces__c', 'pba_uaefields__Parking__c'])),
            'pubDate' => $this->parseDate($this->first($row, ['pba__ListedDate_pb__c', 'pba__OnMarketDate_pb__c'])),
            'agentId' => $agentUserId,
            'listingOwnerId' => $agentUserId,
            'physicalOwnerId' => $this->resolve('Contact', $row['pba__PropertyOwnerContact_pb__c'] ?? null),
            'createdBy' => $this->resolve('User', $this->first($row, ['CreatedById', 'OwnerId'])),
        ];
    }

    /**
     * Resolve the Private + Commercial Amenities CSV columns into deduplicated pfAmenities /
     * bayutAmenities key lists via {@see self::AMENITY_MAP}. Unmapped labels are dropped.
     *
     * @return array{pf: list<string>, bayut: list<string>}
     */
    public function resolveAmenities(array $row): array
    {
        $labels = array_merge(
            $this->splitList($row['pba_uaefields__Private_Amenities__c'] ?? null),
            $this->splitList($row['pba_uaefields__Commercial_Amenities__c'] ?? null),
        );

        $pf = [];
        $bayut = [];

        foreach ($labels as $label) {
            $map = self::AMENITY_MAP[$this->normalizeAmenity($label)] ?? null;
            if (! $map) {
                continue;
            }
            if ($map['pf']) {
                $pf[$map['pf']] = true;
            }
            if ($map['bayut']) {
                $bayut[$map['bayut']] = true;
            }
        }

        return ['pf' => array_keys($pf), 'bayut' => array_keys($bayut)];
    }

    /** Normalize an amenity label for AMENITY_MAP lookup: lowercase, strip non-alphanumerics. */
    private function normalizeAmenity(string $label): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($label));
    }

    /** Split a Salesforce semicolon-delimited multi-select into trimmed, non-empty values. */
    private function splitList(?string $raw): array
    {
        $raw = trim((string) ($raw ?? ''));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/;/', $raw))));
    }

    /**
     * Preserve a listing's status on re-import: a property's lifecycle status is managed in
     * the CRM and must not be reverted to the Salesforce pba__Status__c value on a re-run.
     */
    protected function insertOnlyColumns(): array
    {
        return ['status', 'referenceNumber', 'created_at'];
    }

    /**
     * Meta keys (and internal-note types) this importer is allowed to write; null writes them
     * all. Update-only importers narrow this to a configured subset so a re-sync touches only
     * the fields it is meant to maintain.
     *
     * @return array<int, string>|null
     */
    protected function metaKeysAllowed(): ?array
    {
        return null;
    }

    protected function afterSave(int $crmId, array $row, bool $isNew): void
    {
        // flyerData is a named-key object: the non-empty Flyer Highlight 1-5 values keyed
        // flyerHighlightN, plus the rich template description. Empty highlights are omitted
        // (so a blank Highlight 1 simply doesn't appear, rather than shifting the others).
        $flyer = [];
        for ($i = 1; $i <= 5; $i++) {
            $highlight = $this->str($row["Flyer_Highlight_{$i}__c"] ?? null);
            if ($highlight !== null) {
                $flyer["flyerHighlight{$i}"] = $highlight;
            }
        }
        $templateDescription = $this->str($row['Template_Description_rich__c'] ?? null);
        if ($templateDescription !== null) {
            $flyer['templateDescription'] = $templateDescription;
        }

        // Developer name for the website/portal export (PropertyCrmResource reads this back as
        // pba_uaefields__developer_name__c). The listing's own developer field is usually blank in
        // the feed, so fall back to the linked project's developer (pba_uaefields__Developer__c,
        // preloaded into projectEnrich).
        $project = $this->projectEnrich[trim((string) ($row['pba__Property__c'] ?? ''))] ?? [];

        // Bulk upserts bypass model events, so the status-date stamps Property::syncStatusDateMeta
        // writes on normal saves have to be produced here too.
        $status = $this->lookup($row['pba__Status__c'] ?? null, self::STATUS_MAP, Constants::PROPERTY_STATUS_DRAFT);
        $statusDate = $this->parseDate($row['CreatedDate'] ?? null) ?? now()->toDateString();

        $meta = [
            'pocketListingDate' => $status === Constants::PROPERTY_STATUS_POCKET_LISTING ? $statusDate : null,
            'upcomingDate' => $status === Constants::PROPERTY_STATUS_UPCOMING ? $statusDate : null,
            'pfDeveloper' => $this->first($row, ['pba_uaefields__Developer_Name__c']) ?? ($project['developer'] ?? null),
            'keyFeaturesForWebsite' => $this->str($row['Key_Feature_for_Website__c'] ?? null),
            'locationProximityForWebsite' => $this->str($row['Location_Proximity_for_website__c'] ?? null),
            'facilities' => $this->str($row['Facilities__c'] ?? null),
            'completionDate' => $this->parseDate($row['pba__CompletionDate_pb__c'] ?? null),
            'trakheesiPermit' => $this->str($row['Trakheesi_Permit_Number__c'] ?? null),
            'expireOn' => $this->parseDate($row['Expire_On__c'] ?? null),
            'websiteUrl' => $this->str($row['pba__Listing_Website__c'] ?? null),
            'transactionNumber' => $this->str($row['Transaction_Number__c'] ?? null),
            'templateDescription' => $templateDescription,
            'flyerData' => $flyer ? json_encode($flyer) : null,
            // CRM stores the annual rental yield under the annualRentalPrice meta key.
            'annualRentalPrice' => $this->str($row['ANNUAL_RENTAL_YEILD__c'] ?? null),
            'pfFurnishingType' => $this->furnishingKey($row['Furnished_Type__c'] ?? null),
            // Drives the pba_uaefields__furnished__c / pba__furnished_pb__c portal outputs.
            'bayutFurnishingStatus' => $this->lookup($row['pba_uaefields__Furnished__c'] ?? null, self::BAYUT_FURNISHING_MAP),
            'pfRegion' => $this->str($row['pba_uaefields__Propertyfinder_Region__c'] ?? null),
            'propertyAddress' => $this->str($row['pba__Address_pb__c'] ?? null),
            'geocodeAccuracy' => $this->str($row['pba__GeocodeAccuracy_pb__c'] ?? null),
            'recordTypeId' => $this->str($row['RecordTypeId'] ?? null),
            'locationId' => $this->str($row['pba__Location_Id__c'] ?? null),
            'pfUnitNumber' => $this->str($row['pba__UnitNumber__c'] ?? null),
            // Salesforce "allowed for portals" flag, stored as an explicit '1'/'0' string so the
            // false case ('0') still persists (a null/empty value is skipped on the write below)
            // and the export's pba__systemallowedforportals__c can read it back.
            'systemAllowedForPortals' => $this->isTrue($row['pba__SystemAllowedForPortals__c'] ?? null) ? '1' : '0',
        ];

        for ($i = 1; $i <= 6; $i++) {
            $meta["unitType{$i}"] = $this->str($row["Unit_Type_{$i}__c"] ?? null);
            $meta["unitTypePrice{$i}"] = $this->str($row["Price_Starting_From_Unit_Type_{$i}__c"] ?? null);
            $meta["unitTypeBuildUpArea{$i}"] = $this->str($row["Built_Up_Area_Unit_Type_{$i}__c"] ?? null);
        }

        $paymentPlans = $this->paymentPlans($row);
        if ($paymentPlans) {
            $meta['paymentPlans'] = json_encode($paymentPlans);
        }

        // Update-only importers may narrow meta/note writes to a configured subset of keys.
        $allowed = $this->metaKeysAllowed();
        if ($allowed !== null) {
            $meta = array_intersect_key($meta, array_flip($allowed));
        }

        $now = now();
        foreach ($meta as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $this->upsertChanged(
                'property_meta',
                ['propertyId' => $crmId, 'metaKey' => $key],
                'metaValue', $value,
                ['updated_at' => $now, 'created_at' => $now],
            );
        }

        $notes = [
            'portalNotes' => $this->str($row['Portal_Notes__c'] ?? null),
            'adminComments' => $this->str($row['Admin_Comments__c'] ?? null),
        ];
        foreach ($notes as $type => $content) {
            if ($content === null || $content === '') {
                continue;
            }
            if ($allowed !== null && ! in_array($type, $allowed, true)) {
                continue;
            }
            $this->upsertChanged(
                'internal_notes',
                ['module' => Constants::MODULE_PROPERTY, 'moduleId' => $crmId, 'type' => $type],
                'content', $content,
                ['updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    /** yearBuilt is a MySQL YEAR column (1901-2155); drop junk values out of range. */
    private function validYear(?string $v): ?int
    {
        $y = $this->intval($v);

        return ($y !== null && $y >= 1901 && $y <= 2155) ? $y : null;
    }

    /** Drop junk coordinates (lat ±90, lon ±180) that also overflow the decimal column. */
    private function coord(?string $v, float $max): ?float
    {
        $n = $this->num($v);

        return ($n !== null && $n >= -$max && $n <= $max) ? $n : null;
    }

    private function paymentPlans(array $row): array
    {
        $plans = [];
        for ($i = 1; $i <= 8; $i++) {
            $value = $this->str($row["Payment_Plan_{$i}__c"] ?? null);
            if ($value === null) {
                continue;
            }
            $plans[] = [
                'value' => $value,
                'type' => $this->str($row["Payment_Plan_{$i}_Type__c"] ?? null),
            ];
        }

        return $plans;
    }

    private function resolveType(?string $raw): array
    {
        $empty = ['category' => null, 'pf' => null, 'bayut' => null];
        $raw = trim((string) ($raw ?? ''));
        if ($raw === '') {
            return $empty;
        }

        // Take the leading value of a "Villa;Townhouse" multi-select.
        $raw = trim(preg_split('/[;,]/', $raw)[0]);

        // Split an optional "Category - Type" prefix (e.g. "Residential - Villa") so the
        // category can disambiguate labels shared by a residential and a commercial type
        // (Villa, Whole Building, Full Floor, Land). Only split when the prefix is a real
        // category word, so labels with their own dash (e.g. "Co-working Space") survive.
        $category = null;
        if (str_contains($raw, '-')) {
            [$prefix, $rest] = array_map('trim', explode('-', $raw, 2));
            $cat = $this->canonicalCategory($prefix);
            if ($cat !== null) {
                $category = $cat;
                $raw = $rest;
            }
        }

        $label = strtolower(trim($raw));
        $canonical = ($category !== null ? ($this->typeByCategoryLabel["{$category}|{$label}"] ?? null) : null)
            ?? $this->typeByLabel[$label]
            ?? null;
        if (! $canonical) {
            return $empty;
        }
        $row = Constants::PROPERTY_TYPE_MAPPING[$canonical];

        return [
            'category' => $row['category'] ?? null,
            'pf' => $row['pf'] ?? null,
            'bayut' => $row['bayut'] ?? null,
        ];
    }

    /** Map a Salesforce type prefix ("Residential"/"Commercial") to a category constant. */
    private function canonicalCategory(string $prefix): ?string
    {
        $prefix = strtolower($prefix);
        if (str_contains($prefix, 'commercial')) {
            return Constants::PROPERTY_CATEGORY_COMMERCIAL;
        }
        if (str_contains($prefix, 'residential')) {
            return Constants::PROPERTY_CATEGORY_RESIDENTIAL;
        }

        return null;
    }

    private function resolveCompletion(array $row): ?string
    {
        $raw = strtolower((string) ($this->first($row, ['pba_uaefields__Completion_Status__c', 'pba__CompletionStatus_pb__c']) ?? ''));
        if ($raw === '') {
            return null;
        }
        if (str_contains($raw, 'off') && str_contains($raw, 'primary')) {
            return 'offPlanPrimary';
        }
        if (str_contains($raw, 'off')) {
            return 'offPlan';
        }
        if (str_contains($raw, 'primary')) {
            return 'completedPrimary';
        }
        if (str_contains($raw, 'complete') || str_contains($raw, 'ready')) {
            return 'completedReady';
        }

        return null;
    }

    /**
     * Store the furnishing type as its CRM key rather than the Salesforce label, by reverse-mapping
     * the Furnished_Type__c value through {@see Constants::PROPERTY_FURNISHING_TYPE}
     * (e.g. "Semi Furnished" -> "semi-furnished"). Labels not in the constant resolve to null.
     */
    private function furnishingKey(?string $raw): ?string
    {
        $keyByLabel = [];
        foreach (Constants::PROPERTY_FURNISHING_TYPE as $key => $label) {
            $keyByLabel[strtolower($label)] = $key;
        }

        return $this->lookup($raw, $keyByLabel);
    }

    /**
     * Bedroom count from the feed. Studio units arrive as the literal text "Studio" (not 0),
     * which intval() would drop to null and hide the listing's bedroom count; map those to 0
     * so studios keep a count. Every other value parses as an integer.
     */
    private function resolveBedrooms(array $row): ?int
    {
        $raw = $this->first($row, ['Num_Beds__c', 'pba__Bedrooms_pb__c']);
        if ($raw !== null && str_contains(strtolower($raw), 'studio')) {
            return 0;
        }

        return $this->intval($raw);
    }

    private function resolveManagedType(?string $raw): ?string
    {
        $raw = trim((string) ($raw ?? ''));
        if ($raw === '') {
            return null;
        }
        $first = trim(preg_split('/[;,]/', $raw)[0]);

        return $this->lookup($first, self::MANAGED_MAP);
    }
}
