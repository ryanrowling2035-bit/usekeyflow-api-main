<?php

namespace App\Http\Controllers\Api\V1;

use App\Constants\Constants;
use App\Http\Resources\V1\LeadResource;
use App\Models\Contact;
use App\Models\Enquiry;
use App\Models\Property;
use App\Models\User;
use App\Services\EnquiryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LeadController extends BaseV1Controller
{
    /**
     * GET /v1/leads
     * Filters: from, to, agent_id, status, source, marketing_os_lead_id, search, updated_after, page, limit.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'agent_id' => 'nullable|integer',
            'status' => 'nullable|string',
            'source' => 'nullable|string',
            'marketing_os_lead_id' => 'nullable|string',
            'search' => 'nullable|string',
            'updated_after' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error.', 422, $validator->errors()->toArray());
        }

        try {
            $limit = $this->resolveLimit($request);

            $query = Enquiry::query()
                ->with(['agent', 'contact', 'listing']);

            if ($request->filled('agent_id')) {
                $query->where('agentId', $request->agent_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('source')) {
                $source = $request->source;
                $query->where(function ($q) use ($source) {
                    $q->where('leadSource', $source)
                        ->orWhere('technicalSource', $source);
                });
            }

            if ($request->filled('marketing_os_lead_id')) {
                $mosId = $request->marketing_os_lead_id;
                $query->where(function ($q) use ($mosId) {
                    $q->where('extras', 'LIKE', '%"mid":"'.$mosId.'"%')
                        ->orWhere('extras', 'LIKE', '%"marketing_os_lead_id":"'.$mosId.'"%');
                });
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('referenceNo', 'LIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%")
                        ->orWhere('leadSource', 'LIKE', "%{$search}%")
                        ->orWhere('technicalSource', 'LIKE', "%{$search}%")
                        ->orWhere('comments', 'LIKE', "%{$search}%")
                        ->orWhere('extras', 'LIKE', "%{$search}%")
                        ->orWhereHas('contact', function ($sub) use ($search) {
                            $sub->where('name', 'LIKE', "%{$search}%")
                                ->orWhere('email', 'LIKE', "%{$search}%")
                                ->orWhere('phone', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('agent', function ($sub) use ($search) {
                            $sub->where('name', 'LIKE', "%{$search}%")
                                ->orWhere('email', 'LIKE', "%{$search}%");
                        });
                });
            }

            if ($request->filled('from')) {
                $from = Carbon::parse($request->from)->startOfDay();
                $query->where('created_at', '>=', $from);
            }

            if ($request->filled('to')) {
                $to = Carbon::parse($request->to)->endOfDay();
                $query->where('created_at', '<=', $to);
            }

            if ($request->filled('updated_after')) {
                $updatedAfter = Carbon::parse($request->updated_after);
                $query->where('updated_at', '>=', $updatedAfter);
            }

            $query->orderBy('created_at', 'desc');

            $paginator = $query->paginate($limit);
            $items = LeadResource::collection($paginator->items());

            return $this->paginated($paginator, $items, 'Leads retrieved successfully.');
        } catch (\Throwable $e) {
            return $this->handleException('LeadController@index', $e);
        }
    }

    /**
     * GET /v1/leads/{id}
     * Retrieve a single lead by CRM ID, Reference Number, or Marketing OS Lead ID.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $enquiry = $this->resolveLead($id);

            if (! $enquiry) {
                return $this->error('Lead not found for identifier: '.$id, 404);
            }

            $enquiry->loadMissing(['agent', 'contact', 'listing']);

            return $this->success(new LeadResource($enquiry), 'Lead retrieved successfully.');
        } catch (\Throwable $e) {
            return $this->handleException('LeadController@show', $e);
        }
    }

    /**
     * POST /v1/leads
     * Ingest qualified leads from Marketing OS into the CRM.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:50',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'nationality' => 'nullable|string|max:100',
            'marketing_os_lead_id' => 'nullable|string|max:100',
            'utm_source' => 'nullable|string|max:100',
            'utm_medium' => 'nullable|string|max:100',
            'utm_campaign' => 'nullable|string|max:150',
            'utm_term' => 'nullable|string|max:150',
            'utm_content' => 'nullable|string|max:150',
            'gclid' => 'nullable|string|max:255',
            'referrer' => 'nullable|string|max:1000',
            'referral' => 'nullable|string|max:1000',
            'campaign_identifier' => 'nullable|string|max:150',
            'web_url' => 'nullable|string|max:1000',
            'property_reference' => 'nullable|string|max:100',
            'listing_id' => 'nullable|integer',
            'enquiry_type' => 'nullable|string|max:50',
            'listing_type' => 'nullable|string|in:sale,rent,offPlan',
            'lead_rating' => 'nullable|string|in:hot,warm,cold',
            'lead_qualification' => 'nullable|string|max:100',
            'investment_motivation' => 'nullable|string|max:100',
            'budget' => 'nullable|numeric|min:0',
            'price_min' => 'nullable|numeric|min:0',
            'price_max' => 'nullable|numeric|min:0',
            'bedrooms_min' => 'nullable|integer|min:0',
            'bedrooms_max' => 'nullable|integer|min:0',
            'bathrooms_min' => 'nullable|integer|min:0',
            'bathrooms_max' => 'nullable|integer|min:0',
            'city' => 'nullable|string|max:100',
            'community' => 'nullable|string|max:150',
            'project_name' => 'nullable|string|max:150',
            'comments' => 'nullable|string',
            'agent_id' => 'nullable|integer',
            'agent_code' => 'nullable|string|max:50',
            'agent_email' => 'nullable|email',
            'lead_source' => 'nullable|string|max:100',
            'utm' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error.', 422, $validator->errors()->toArray());
        }

        try {
            // 1. Idempotency Check: if marketing_os_lead_id already exists, return existing lead (Safe LIKE search for VARCHAR(255))
            if ($request->filled('marketing_os_lead_id')) {
                $mosId = $request->marketing_os_lead_id;
                $existing = Enquiry::where(function ($q) use ($mosId) {
                    $q->where('extras', 'LIKE', '%"mid":"'.$mosId.'"%')
                        ->orWhere('extras', 'LIKE', '%"marketing_os_lead_id":"'.$mosId.'"%');
                })->first();
                if ($existing) {
                    $existing->loadMissing(['agent', 'contact', 'listing']);

                    return $this->success(new LeadResource($existing), 'Lead already exists (idempotent response).', 200);
                }
            }

            // 2. Resolve or Create Contact (E.164 phone normalization + deduplication)
            $rawPhone = $request->input('phone');
            $normalizedPhone = function_exists('normalizePhoneE164')
                ? (normalizePhoneE164($rawPhone) ?: $rawPhone)
                : $rawPhone;

            $contact = Contact::resolveMasterByPhone($normalizedPhone);
            if (! $contact && $request->filled('email')) {
                $contact = Contact::where('email', $request->email)->first()?->masterOrSelf();
            }

            $enquiryTypeInput = $request->input('enquiry_type', 'buyer');
            $contactType = in_array(strtolower((string) $enquiryTypeInput), ['tenant', 'rent'])
                ? Constants::CONTACT_TYPE_TENANT
                : Constants::CONTACT_TYPE_BUYER;

            $actorId = auth()->id() ?: 1;

            if ($contact) {
                $contact->addContactTypes($contactType);
                if ($request->filled('nationality') && empty($contact->nationality)) {
                    $contact->update(['nationality' => $request->nationality]);
                }
            } else {
                $contact = Contact::create([
                    'name' => $request->input('name') ?: 'Lead - '.$normalizedPhone,
                    'phone' => $normalizedPhone,
                    'email' => $request->input('email'),
                    'nationality' => $request->input('nationality'),
                    'contactType' => [$contactType],
                    'source' => $request->input('lead_source') ?: 'Marketing OS',
                    'createdBy' => $actorId,
                ]);
            }

            // 3. Resolve Linked Property (if reference or listing_id passed)
            $property = null;
            if ($request->filled('property_reference')) {
                $cleanRef = trim(preg_replace(['/^\s*(?:listing|property)\s*[:\-]?\s+/i', '/\s+/'], ['', ' '], $request->property_reference));
                $property = Property::where('referenceNumber', $cleanRef)->first();
            } elseif ($request->filled('listing_id')) {
                $property = Property::find($request->listing_id);
            }

            // 4. Resolve Assigned Agent
            $assignedAgentId = $this->resolveAgentId($request, $property, $actorId);

            $gclid = $request->input('gclid') ?? $request->input('utm.gclid');
            $referrer = $request->input('referrer') ?? $request->input('referral') ?? $request->input('utm.referrer') ?? $request->input('utm.referral');
            $campaignIdentifier = $request->input('campaign_identifier') ?? $request->input('utm.campaign_identifier');
            $utmSource = $request->input('utm_source') ?? $request->input('utm.source');
            $utmMedium = $request->input('utm_medium') ?? $request->input('utm.medium');
            $utmCampaign = $request->input('utm_campaign') ?? $request->input('utm.campaign');
            $utmTerm = $request->input('utm_term') ?? $request->input('utm.term');
            $utmContent = $request->input('utm_content') ?? $request->input('utm.content');

            // 5. Build Extras JSON payload for Marketing OS & UTM tracking (Zero DB Schema Change)
            $extras = [
                'mid' => $request->input('marketing_os_lead_id'),
                'src' => $utmSource,
                'med' => $utmMedium,
                'cmp' => $utmCampaign,
                'trm' => $utmTerm,
                'cnt' => $utmContent,
                'gclid' => $gclid,
                'referrer' => $referrer,
                'campaign_identifier' => $campaignIdentifier,
                'qual' => $request->input('lead_qualification') ?: 'qualified',
            ];
            $extrasJson = $this->encodeExtrasSafe($extras);

            // 6. Generate Reference Number
            $referenceNo = function_exists('generateEnquiryReferenceNo')
                ? generateEnquiryReferenceNo()
                : 'ENQ-'.strtoupper(bin2hex(random_bytes(4)));

            $budget = $request->input('budget') ?: ($property?->price);
            $actualPrice = $property?->price ?: $budget;

            $enquiry = Enquiry::create([
                'referenceNo' => $referenceNo,
                'contactId' => $contact->id,
                'agentId' => $assignedAgentId,
                'listingId' => $property?->id,
                'phone' => $normalizedPhone,
                'enquiryType' => $request->input('enquiry_type') ?: ($property && $property->listingType === 'rent' ? 'tenant' : 'buyer'),
                'listingType' => $request->input('listing_type') ?: ($property?->listingType ?: 'sale'),
                'status' => Constants::ENQUIRY_NEW_LEAD,
                'referredBy' => $referrer,
                'leadSource' => $request->input('lead_source') ?: 'Marketing OS',
                'technicalSource' => 'Marketing OS API',
                'leadRating' => $request->input('lead_rating') ?: 'hot',
                'investmentMotivation' => $request->input('investment_motivation'),
                'budget' => $budget,
                'actualPrice' => $actualPrice,
                'priceMin' => $request->input('price_min') ?: ($actualPrice ? round($actualPrice * 0.95) : null),
                'priceMax' => $request->input('price_max') ?: ($actualPrice ? round($actualPrice * 1.05) : null),
                'bedroomsMin' => $request->input('bedrooms_min') ?: ($property?->bedrooms),
                'bedroomsMax' => $request->input('bedrooms_max') ?: ($property?->bedrooms ? $property->bedrooms + 1 : null),
                'bathroomsMin' => $request->input('bathrooms_min') ?: ($property?->bathrooms),
                'bathroomsMax' => $request->input('bathrooms_max') ?: ($property?->bathrooms ? $property->bathrooms + 1 : null),
                'city' => $request->input('city') ?: 'Dubai',
                'community' => $request->input('community') ?: ($property?->pfCommunity),
                'projectName' => $request->input('project_name') ?: ($property?->title),
                'comments' => $request->input('comments') ?: 'Qualified lead created via Marketing OS API',
                'webUrl' => $request->input('web_url'),
                'extras' => $extrasJson,
                'createdBy' => $actorId,
            ]);

            // 7. Activity logging & history
            if (function_exists('logUserActivity')) {
                logUserActivity([
                    'userId' => $actorId,
                    'action' => 'created',
                    'module' => Constants::MODULE_ENQUIRY,
                    'moduleId' => $enquiry->id,
                    'description' => "Lead created via Marketing OS API: #{$enquiry->referenceNo}",
                ]);
            }

            if (function_exists('logEnquiryHistory')) {
                logEnquiryHistory([
                    'enquiryId' => $enquiry->id,
                    'field' => 'created',
                    'originalValue' => null,
                    'newValue' => json_encode([
                        'message' => "Enquiry created via Marketing OS: #{$enquiry->referenceNo}",
                        'marketing_os_lead_id' => $request->input('marketing_os_lead_id'),
                    ]),
                ]);
            }

            $enquiry->load(['agent', 'contact', 'listing']);

            return $this->success(new LeadResource($enquiry), 'Lead created successfully.', 201);
        } catch (\Throwable $e) {
            return $this->handleException('LeadController@store', $e);
        }
    }

    /**
     * PUT /v1/leads/{id}
     * Update an existing lead by CRM ID, Reference Number, or Marketing OS Lead ID.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'nationality' => 'nullable|string|max:100',
            'status' => 'nullable|string',
            'lead_source' => 'nullable|string|max:100',
            'enquiry_type' => 'nullable|string|max:50',
            'listing_type' => 'nullable|string|in:sale,rent,offPlan',
            'lead_rating' => 'nullable|string|in:hot,warm,cold',
            'lead_qualification' => 'nullable|string|max:100',
            'investment_motivation' => 'nullable|string|max:100',
            'budget' => 'nullable|numeric|min:0',
            'actual_price' => 'nullable|numeric|min:0',
            'price_min' => 'nullable|numeric|min:0',
            'price_max' => 'nullable|numeric|min:0',
            'bedrooms_min' => 'nullable|integer|min:0',
            'bedrooms_max' => 'nullable|integer|min:0',
            'bathrooms_min' => 'nullable|integer|min:0',
            'bathrooms_max' => 'nullable|integer|min:0',
            'city' => 'nullable|string|max:100',
            'community' => 'nullable|string|max:150',
            'project_name' => 'nullable|string|max:150',
            'web_url' => 'nullable|string|max:1000',
            'comments' => 'nullable|string',
            'agent_id' => 'nullable|integer',
            'agent_code' => 'nullable|string|max:50',
            'agent_email' => 'nullable|email',
            'property_reference' => 'nullable|string|max:100',
            'listing_id' => 'nullable|integer',
            'utm_source' => 'nullable|string|max:100',
            'utm_medium' => 'nullable|string|max:100',
            'utm_campaign' => 'nullable|string|max:150',
            'utm_term' => 'nullable|string|max:150',
            'utm_content' => 'nullable|string|max:150',
            'marketing_os_lead_id' => 'nullable|string|max:100',
            'gclid' => 'nullable|string|max:255',
            'referrer' => 'nullable|string|max:1000',
            'referral' => 'nullable|string|max:1000',
            'campaign_identifier' => 'nullable|string|max:150',
            'utm' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error.', 422, $validator->errors()->toArray());
        }

        try {
            $enquiry = $this->resolveLead($id);

            if (! $enquiry) {
                return $this->error('Lead not found for identifier: '.$id, 404);
            }

            $updateData = [];

            // 1. Update contact fields if present
            if ($enquiry->contact) {
                $contactUpdates = [];
                if ($request->filled('name')) {
                    $contactUpdates['name'] = $request->name;
                }
                if ($request->filled('email')) {
                    $contactUpdates['email'] = $request->email;
                }
                if ($request->filled('nationality')) {
                    $contactUpdates['nationality'] = $request->nationality;
                }
                if ($request->filled('phone')) {
                    $normalizedPhone = function_exists('normalizePhoneE164')
                        ? (normalizePhoneE164($request->phone) ?: $request->phone)
                        : $request->phone;
                    $contactUpdates['phone'] = $normalizedPhone;
                    $updateData['phone'] = $normalizedPhone;
                }
                if (! empty($contactUpdates)) {
                    $enquiry->contact->update($contactUpdates);
                }
            } elseif ($request->filled('phone')) {
                $normalizedPhone = function_exists('normalizePhoneE164')
                    ? (normalizePhoneE164($request->phone) ?: $request->phone)
                    : $request->phone;
                $updateData['phone'] = $normalizedPhone;
            }

            // 2. Update lead fields
            if ($request->filled('status')) {
                $updateData['status'] = $request->status;
            }
            if ($request->filled('lead_rating')) {
                $updateData['leadRating'] = $request->lead_rating;
            }
            if ($request->filled('lead_source')) {
                $updateData['leadSource'] = $request->lead_source;
            }
            if ($request->filled('enquiry_type')) {
                $updateData['enquiryType'] = $request->enquiry_type;
            }
            if ($request->filled('listing_type')) {
                $updateData['listingType'] = $request->listing_type;
            }
            if ($request->filled('investment_motivation')) {
                $updateData['investmentMotivation'] = $request->investment_motivation;
            }
            if ($request->filled('budget')) {
                $updateData['budget'] = $request->budget;
            }
            if ($request->filled('actual_price')) {
                $updateData['actualPrice'] = $request->actual_price;
            }
            if ($request->filled('price_min')) {
                $updateData['priceMin'] = $request->price_min;
            }
            if ($request->filled('price_max')) {
                $updateData['priceMax'] = $request->price_max;
            }
            if ($request->filled('bedrooms_min')) {
                $updateData['bedroomsMin'] = $request->bedrooms_min;
            }
            if ($request->filled('bedrooms_max')) {
                $updateData['bedroomsMax'] = $request->bedrooms_max;
            }
            if ($request->filled('bathrooms_min')) {
                $updateData['bathroomsMin'] = $request->bathrooms_min;
            }
            if ($request->filled('bathrooms_max')) {
                $updateData['bathroomsMax'] = $request->bathrooms_max;
            }
            if ($request->filled('city')) {
                $updateData['city'] = $request->city;
            }
            if ($request->filled('community')) {
                $updateData['community'] = $request->community;
            }
            if ($request->filled('project_name')) {
                $updateData['projectName'] = $request->project_name;
            }
            if ($request->filled('web_url')) {
                $updateData['webUrl'] = $request->web_url;
            }
            if ($request->filled('comments')) {
                $existingComments = $enquiry->comments ? $enquiry->comments."\n" : '';
                $updateData['comments'] = $existingComments.'['.now()->toDateTimeString().'] '.$request->comments;
            }
            if ($request->filled('referrer') || $request->filled('referral')) {
                $updateData['referredBy'] = $request->input('referrer') ?? $request->input('referral');
            }

            // Resolve Linked Property if property_reference or listing_id provided
            $property = null;
            if ($request->filled('property_reference')) {
                $cleanRef = trim(preg_replace(['/^\s*(?:listing|property)\s*[:\-]?\s+/i', '/\s+/'], ['', ' '], $request->property_reference));
                $property = Property::where('referenceNumber', $cleanRef)->first();
                if ($property) {
                    $updateData['listingId'] = $property->id;
                }
            } elseif ($request->filled('listing_id')) {
                $property = Property::find($request->listing_id);
                if ($property) {
                    $updateData['listingId'] = $property->id;
                }
            }

            // Reassign agent if specified
            if ($request->filled('agent_id') || $request->filled('agent_code') || $request->filled('agent_email')) {
                $agentId = $this->resolveAgentId($request, $property, $enquiry->agentId);
                if ($agentId) {
                    $updateData['agentId'] = $agentId;
                }
            }

            // 3. Update extras JSON tracking parameters (safely without unsetting matching keys)
            $extras = is_string($enquiry->extras) ? json_decode($enquiry->extras, true) : (is_array($enquiry->extras) ? $enquiry->extras : []);
            $keyMap = [
                'marketing_os_lead_id' => 'mid',
                'utm_source' => 'src',
                'utm_medium' => 'med',
                'utm_campaign' => 'cmp',
                'utm_term' => 'trm',
                'utm_content' => 'cnt',
                'lead_qualification' => 'qual',
                'gclid' => 'gclid',
                'referrer' => 'referrer',
                'referral' => 'referrer',
                'campaign_identifier' => 'campaign_identifier',
            ];
            $extrasChanged = false;

            foreach ($keyMap as $inputKey => $shortKey) {
                $val = null;
                if ($request->has($inputKey)) {
                    $val = $request->input($inputKey);
                } elseif (str_starts_with($inputKey, 'utm_') && $request->has('utm.'.substr($inputKey, 4))) {
                    $val = $request->input('utm.'.substr($inputKey, 4));
                } elseif ($request->has('utm.'.$inputKey)) {
                    $val = $request->input('utm.'.$inputKey);
                }

                if ($val !== null) {
                    $extras[$shortKey] = $val;
                    if ($inputKey !== $shortKey) {
                        unset($extras[$inputKey]);
                    }
                    $extrasChanged = true;
                }
            }

            if ($extrasChanged) {
                $updateData['extras'] = $this->encodeExtrasSafe($extras);
            }

            if (! empty($updateData)) {
                $enquiry->update($updateData);

                if (function_exists('logUserActivity')) {
                    logUserActivity([
                        'userId' => auth()->id() ?: 1,
                        'action' => 'updated',
                        'module' => Constants::MODULE_ENQUIRY,
                        'moduleId' => $enquiry->id,
                        'description' => "Lead updated via Marketing OS API: #{$enquiry->referenceNo}",
                    ]);
                }
            }

            $enquiry->refresh();
            $enquiry->load(['agent', 'contact', 'listing']);

            return $this->success(new LeadResource($enquiry), 'Lead updated successfully.');
        } catch (\Throwable $e) {
            return $this->handleException('LeadController@update', $e);
        }
    }

    /**
     * Resolve a lead by CRM ID, Reference Number, or Marketing OS Lead ID.
     */
    protected function resolveLead(string $id): ?Enquiry
    {
        if (is_numeric($id)) {
            $enquiry = Enquiry::find((int) $id);
            if ($enquiry) {
                return $enquiry;
            }
        }

        return Enquiry::where('referenceNo', $id)
            ->orWhere('extras', 'LIKE', '%"mid":"'.$id.'"%')
            ->orWhere('extras', 'LIKE', '%"marketing_os_lead_id":"'.$id.'"%')
            ->first();
    }

    /**
     * Resolve the assigned agent ID based on request parameters, property, or default fallback.
     */
    protected function resolveAgentId(Request $request, ?Property $property, int $defaultFallback): int
    {
        if ($request->filled('agent_id')) {
            $agent = User::find($request->agent_id);
            if ($agent) {
                return $agent->id;
            }
        }

        if ($request->filled('agent_code')) {
            $agent = User::where('agentCode', $request->agent_code)->first();
            if ($agent) {
                return $agent->id;
            }
        }

        if ($request->filled('agent_email')) {
            $agent = User::where('email', $request->agent_email)->first();
            if ($agent) {
                return $agent->id;
            }
        }

        if ($property && ! empty($property->agentId)) {
            return (int) $property->agentId;
        }

        $activeAgent = User::where('status', Constants::USER_STATUS_ACTIVE)->first();

        return $activeAgent ? (int) $activeAgent->id : $defaultFallback;
    }

    /**
     * Safely encode extras into JSON guaranteeing it never exceeds VARCHAR(255) length.
     * Prevents database-level string truncation and JSON corruption.
     */
    protected function encodeExtrasSafe(array $extras): ?string
    {
        $clean = array_filter($extras, fn ($v) => ! is_null($v) && $v !== '');
        if (empty($clean)) {
            return null;
        }

        $json = json_encode($clean);
        if (strlen($json) <= 255) {
            return $json;
        }

        // If JSON exceeds 255 chars, progressively shorten non-essential fields
        $truncatable = ['referrer', 'campaign_identifier', 'gclid', 'cnt', 'trm', 'cmp', 'src', 'med'];
        foreach ($truncatable as $field) {
            if (isset($clean[$field]) && is_string($clean[$field])) {
                $clean[$field] = substr($clean[$field], 0, 30);
                $json = json_encode($clean);
                if (strlen($json) <= 255) {
                    return $json;
                }
            }
        }

        // If still > 255 chars, drop lower-priority fields to guarantee complete, valid JSON <= 255
        $droppable = ['campaign_identifier', 'referrer', 'gclid', 'cnt', 'trm', 'qual'];
        foreach ($droppable as $drop) {
            unset($clean[$drop]);
            $json = json_encode($clean);
            if (strlen($json) <= 255) {
                return $json;
            }
        }

        return $json;
    }
}
