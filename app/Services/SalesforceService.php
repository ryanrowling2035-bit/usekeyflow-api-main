<?php

namespace App\Services;

use App\Models\Property;
use App\Models\SalesforceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SalesforceService
{
    protected string $loginUrl;

    protected string $accessToken;

    protected string $instanceUrl;

    public function __construct()
    {
        $this->loginUrl = config('services.salesforce.login_url');
    }

    public function getSalesforceValueFromDb()
    {
        Log::info('Salesforce: getSalesforceValueFromDb - fetching latest token from DB');
        $token = SalesforceToken::latest()->first();

        if (! $token) {
            Log::info('Salesforce: no token found in DB, refreshing access token');

            return $this->refreshAccessToken();
        }

        // No pre-verification round-trip here: token validity is handled
        // reactively by sfRequest(), which refreshes and retries once when
        // Salesforce reports an expired/invalid session on an actual call.
        return $token;
    }

    protected function refreshAccessToken()
    {
        Log::info('Salesforce: refreshAccessToken - requesting new access token');
        $response = $this->getAccessToken();

        if (! $response->successful()) {
            Log::error('Salesforce: failed to generate access token', ['body' => $response->body()]);
            throw new \Exception(
                'Unable to generate Salesforce token'
            );
        }

        $data = $response->json();
        Log::info('Salesforce: access token generated, storing in database');

        return $this->storeTokenInDatabase($data);
    }

    public function getAccessToken()
    {
        Log::info('Salesforce: getAccessToken - posting OAuth password grant request');
        $response = Http::asForm()->post(
            env('SALESFORCE_LOGIN_URL').'/services/oauth2/token',
            [
                'grant_type' => 'password',
                'client_id' => config('services.salesforce.client_id'),
                'client_secret' => config('services.salesforce.client_secret'),
                'username' => config('services.salesforce.user_name'),
                'password' => config('services.salesforce.password'),
            ]
        );

        return $response;
    }

    public function storeTokenInDatabase($data)
    {
        Log::info('Salesforce: storeTokenInDatabase - persisting token', ['instance_url' => $data['instance_url'] ?? null]);
        $salesforce = SalesforceToken::updateOrCreate(
            ['id' => 1],
            [
                'access_token' => $data['access_token'],
                'instance_url' => $data['instance_url'],
            ]
        );

        return $salesforce;
    }

    protected function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
    }

    protected function setinstanceUrl($instanceUrl)
    {
        $this->instanceUrl = $instanceUrl;
    }

    /**
     * Send an authenticated Salesforce request. If Salesforce reports an
     * expired/invalid session, refresh the access token once and retry, so a
     * stale token never bubbles up as an INVALID_SESSION_ID failure.
     *
     * @param  string  $method  HTTP verb (get|post|...).
     * @param  string  $path  Path appended to the instance URL.
     * @param  array  $data  Query params (GET) or JSON body (POST).
     */
    protected function sfRequest(string $method, string $path, array $data = [])
    {
        $send = fn () => Http::withToken($this->accessToken)
            ->{$method}($this->instanceUrl.$path, $data);

        $response = $send();

        if ($this->isInvalidSession($response)) {
            Log::info('Salesforce: session invalid on request, refreshing token and retrying', ['path' => $path]);
            $salesforce = $this->refreshAccessToken();
            $this->setAccessToken($salesforce->access_token ?? '');
            $this->setinstanceUrl($salesforce->instance_url ?? '');
            $response = $send();
        }

        return $response;
    }

    protected function isInvalidSession($response): bool
    {
        return $response->status() === 401
            || str_contains($response->body(), 'INVALID_SESSION_ID');
    }

    public function createEnquiry($enquiryData)
    {
        // Canonicalise the phone up front so the contact lookup and the create
        // both use the same E.164 value — a format mismatch here would miss the
        // existing Salesforce Contact and spawn a duplicate.
        $enquiryData['contactPhone'] = $this->normalizePhone($enquiryData['contactPhone'] ?? '');

        Log::info('Salesforce: createEnquiry - start', ['phone' => $enquiryData['contactPhone'] ?? null]);

        $salesforce = $this->getSalesforceValueFromDb();
        $this->setAccessToken($salesforce->access_token ?? '');
        $this->setinstanceUrl($salesforce->instance_url ?? '');

        Log::info('Salesforce: createEnquiry - looking up contact by phone');
        $salesForceContact = $this->getContactByPhone($enquiryData['contactPhone'] ?? '');

        if (
            empty($salesForceContact['records']) ||
            empty($salesForceContact['records'][0]['Id'])
        ) {
            Log::info('Salesforce: createEnquiry - no existing contact, creating new contact');
            $newContact = $this->createContact($enquiryData);

            if (empty($newContact['id'])) {
                Log::error('Salesforce: createEnquiry - failed to create contact', ['response' => $newContact]);
                throw new \Exception('Failed to create Salesforce Contact');
            }

            $contactId = $newContact['id'];
            Log::info('Salesforce: createEnquiry - new contact created', ['contactId' => $contactId]);
        } else {
            $contactId = $salesForceContact['records'][0]['Id'];
            Log::info('Salesforce: createEnquiry - existing contact found', ['contactId' => $contactId]);
        }

        Log::info('Salesforce: createEnquiry - creating request for contact', ['contactId' => $contactId]);

        return $this->createRequest($contactId, $enquiryData);

        // $response = Http::withToken($this->accessToken)
        //     ->post(
        //         $this->instanceUrl .
        //             '/services/data/v64.0/sobjects/Lead/',
        //         $payload
        //     );

        // return $response->json();
    }

    public function getContactByPhone(string $phone)
    {
        Log::info('Salesforce: getContactByPhone - querying contact', ['phone' => $phone]);
        $soql = "SELECT Id, MobilePhone FROM Contact WHERE MobilePhone = '{$phone}'";

        $response = $this->sfRequest(
            'get',
            '/services/data/v56.0/query',
            ['q' => $soql]
        );

        if (! $response->successful()) {
            Log::error('Salesforce: getContactByPhone - query failed', ['body' => $response->body()]);
            throw new \Exception($response->body());
        }

        Log::info('Salesforce: getContactByPhone - query successful');

        return $response->json();
    }

    /**
     * Normalise a mobile number to E.164 before it reaches Salesforce, using the
     * shared normalizePhoneE164() helper so the inbound import and this outbound
     * sync agree on format (which is what makes the phone-based contact dedup
     * work). Coalesces null to '' so the result is always a string that is safe
     * to interpolate into the lookup SOQL.
     */
    protected function normalizePhone(?string $phone): string
    {
        return normalizePhoneE164($phone) ?? '';
    }

    public function createContact($enquiryData)
    {
        $contactName = trim($enquiryData['contactName'] ?? '');

        $nameParts = preg_split('/\s+/', $contactName, 2);

        $firstName = ucwords($nameParts[0] ?? 'Contact');
        $lastName = ucwords($nameParts[1] ?? 'Contact');

        $payload = [
            'FirstName' => $firstName ?: 'Contact',
            'LastName' => $lastName ?: 'Contact',
            'MobilePhone' => $enquiryData['contactPhone'] ?? '',
            'Email' => $enquiryData['contactEmail'] ?? '',
        ];

        Log::info('Salesforce: createContact - posting new contact', ['payload' => $payload]);
        $response = $this->sfRequest(
            'post',
            '/services/data/v56.0/sobjects/Contact/',
            $payload
        );

        if (! $response->successful()) {
            Log::error('Salesforce: createContact - failed', ['body' => $response->body()]);
            throw new \Exception('Failed to create Contact');
        }

        Log::info('Salesforce: createContact - contact created successfully');

        return $response->json();
    }

    public function createRequest($contactId, $enquiry)
    {
        // When the enquiry is tied to a property, enrich it with property-derived
        // meta (type, price band, community, etc.) the same way
        // EnquiryController@addEnquiry does when a propertyId is supplied.
        if (! empty($enquiry['propertyReferenceNo'])) {
            $enquiry = array_merge($this->propertyMeta($enquiry['propertyReferenceNo']), $enquiry);
        }

        $payload = [
            'pba__Contact__c' => $contactId,
            'Reference_Number__c' => $enquiry['propertyReferenceNo'] ?? '',
            'Lead_Source__c' => $enquiry['leadSource'] ?? '',
            'pba__TechnicalSourceSystem__c' => $enquiry['technicalSource'] ?? '',
            'pba__ListingAgentName_pb__c' => $enquiry['listingOwnerName'] ?? '',
            'pba__PropertyType__c' => $enquiry['enquiryPropertyType'] ?? '',
            'pba__ListingPrice_pb_max__c' => $enquiry['priceMax'] ?? '',
            'pba__ListingPrice_pb_min__c' => $enquiry['priceMin'] ?? '',
            'Web_URL__c' => $enquiry['webUrl'] ?? '',
            'pba_uaefields__Community_Propertyfinder__c' => $enquiry['community'] ?? '',
            'pba__ListingType__c' => $enquiry['listingType'] ?? '',
            'pba__Type_pb__c' => $enquiry['enquiryType'] ?? '',
            'pba__Comments__c' => 'KeyFlow Reference : '.($enquiry['referenceNo'] ?? ''),
        ];

        Log::info('Salesforce: createRequest - posting new request', ['payload' => $payload]);
        $response = $this->sfRequest(
            'post',
            '/services/data/v56.0/sobjects/pba__Request__c/',
            $payload
        );

        if (! $response->successful()) {
            Log::error('Salesforce: createRequest - failed', ['body' => $response->body()]);
            throw new \Exception(
                'Failed to create Salesforce Request: '.
                    $response->body()
            );
        }

        Log::info('Salesforce: createRequest - request created successfully');

        return $response->json();
    }

    /**
     * Derive enquiry meta from a property's reference number, mirroring the
     * property-based defaults applied in EnquiryController@addEnquiry.
     */
    protected function propertyMeta(string $referenceNo): array
    {
        $property = Property::where('referenceNumber', $referenceNo)->first();

        if (! $property) {
            Log::info('Salesforce: propertyMeta - no property found', ['referenceNo' => $referenceNo]);

            return [];
        }

        Log::info('Salesforce: propertyMeta - property found, deriving meta', [
            'referenceNo' => $referenceNo,
            'propertyId' => $property->id,
        ]);

        $meta = [
            'enquiryPropertyType' => $property->category ?? '',
            'enquiryPropertySubType' => $property->pfPropertyType ?? $property->bayutPropertyType ?? '',
            'community' => $property->pfCommunity ?? '',
            'listingOwnerName' => $property->listingOwner?->name ?? '',
            'listingType' => $property->listingType ?? '',
            'enquiryType' => match ($property->listingType) {
                'sale' => 'buyer',
                'rent' => 'tenant',
                'offPlan' => 'offPlanInvestor',
                default => 'buyer',
            },
        ];

        if ($property->price) {
            $meta['priceMin'] = round($property->price * 0.95);
            $meta['priceMax'] = round($property->price * 1.05);
        }

        return $meta;
    }
}
