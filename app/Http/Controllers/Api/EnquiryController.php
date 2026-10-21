<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Http\Resources\EnquiryResource\GetAllEnquiryResource;
use App\Http\Resources\EnquiryResource\GetEnquiryByUserIdResource;
use App\Http\Resources\EnquiryResource\GetEnquiryFilterResource;
use App\Http\Resources\EnquiryResource\GetEnquiryResource;
use App\Http\Resources\EnquiryResource\GetKanbanEnquiryResource;
use App\Http\Resources\EnquiryResource\GetOthersEnquiryResource;
use App\Http\Resources\PropertyResource\GetPropertyResource;
use App\Jobs\NotifyDataExportJob;
use App\Jobs\SyncEnquiryToSalesforceJob;
use App\Mail\DynamicMail;
use App\Models\AssignTeamMember;
use App\Models\Contact;
use App\Models\Enquiry;
use App\Models\EnquiryHistory;
use App\Models\Property;
use App\Models\SaveEnquiryFilter;
use App\Models\User;
use App\Repositories\GlobalSettingRepository;
use App\Services\EnquiryService;
use App\Services\MailService;
use App\Services\MatchingService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class EnquiryController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected EnquiryService $enquiryService,
        protected GlobalSettingRepository $repository,
        protected MailService $mailService
    ) {}

    /**
     * Lightweight select list for dropdowns.
     */
    public function selectEnquiries(Request $request)
    {
        try {
            $query = Enquiry::select('id', 'referenceNo', 'enquiryType', 'status', 'listingId', 'contactId')
                ->with(['listing:id,title', 'contact:id,name'])
                ->whereIn('status', Constants::ENQUIRY_ACTIVE_STATUSES);

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('referenceNo', 'LIKE', "%{$search}%");
                });
            }

            if (isAgent()) {
                $query->where('agentId', user()->id);
            }

            $enquiries = $query->orderBy('created_at', 'desc')
                ->limit(50)
                ->get()
                ->map(function ($enquiry) {
                    return [
                        'id' => $enquiry->id,
                        'referenceNo' => $enquiry->referenceNo,
                        'identifier' => $enquiry->referenceNo
                            ? '#'.$enquiry->referenceNo.' - '.ucfirst($enquiry->enquiryType ?? '')
                            : 'Enquiry #'.$enquiry->id,
                        'property' => $enquiry->listing ? [
                            'id' => $enquiry->listing->id,
                            'title' => $enquiry->listing->title,
                        ] : null,
                        'contactId' => $enquiry->contact?->masterOrSelf()->id ?? $enquiry->contactId,
                        'contact' => $enquiry->contact ? [
                            'id' => $enquiry->contact->masterOrSelf()->id,
                            'name' => $enquiry->contact->masterOrSelf()->name,
                        ] : null,
                    ];
                });

            return $this->success($enquiries, 'Enquiries retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : selectEnquiries', $e, 500);
        }
    }

    /**
     * Get all enquiries with pagination and filters
     */
    public function getAllEnquiryListing(Request $request)
    {
        try {
            $enquiries = $this->enquiryService->getAllEnquiries($request);

            return $this->success(
                GetAllEnquiryResource::collection($enquiries),
                'Enquiries retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : getAllEnquiryListing', $e);
        }
    }

    /**
     * Get enquiries grouped by status for kanban board view.
     */
    public function kanban(Request $request)
    {
        try {
            $boardType = $request->input('type');
            if (! in_array($boardType, ['rent', 'sale', 'all'], true)) {
                $boardType = 'sale';
            }

            $rentTypes = ['landlord', 'tenant'];
            $saleTypes = ['buyer', 'seller', 'offPlanInvestor', 'agent'];

            $enquiryTypes = match ($boardType) {
                'rent' => $rentTypes,
                'sale' => $saleTypes,
                'all' => array_merge($rentTypes, $saleTypes),
            };

            $startDate = $request->filled('startDate')
                ? Carbon::parse($request->startDate)->startOfDay()
                : Carbon::now()->startOfMonth();
            $endDate = $request->filled('endDate')
                ? Carbon::parse($request->endDate)->endOfDay()
                : Carbon::now()->endOfMonth();

            // Scope the board to the requested users (comma-separated ids, e.g. "5,8,12"),
            // defaulting to the current user. Only these users' own enquiries are shown —
            // including their own open-game leads — never other users' open-game leads.
            $userIds = collect(explode(',', (string) $request->input('users', '')))
                ->map(fn ($id) => (int) trim($id))
                ->filter()
                ->values()
                ->all();

            // Optional team filter: widen the scope to every member of the given team.
            // User ids take priority — when `users` is supplied, `teamId` is ignored.
            if (empty($userIds) && $request->filled('teamId')) {
                $userIds = AssignTeamMember::where('teamId', (int) $request->input('teamId'))
                    ->pluck('userId')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }

            $userIds = array_values(array_unique($userIds));

            $restrictToUsers = true;
            if (empty($userIds)) {
                if (isAgent()) {
                    $userIds = [user()->id];
                } else {
                    $restrictToUsers = false;
                }
            }

            $enquiries = Enquiry::with([
                'agent:id,name',
                'contact:id,name,phone',
                'listing:id,title',
                'creator:id,name',
                'internalNotes',
                'tasks' => fn ($q) => $q->select('id', 'moduleId', 'module', 'updated_at')->latest('updated_at')->limit(1),
            ])
                // The "All" board shows rent + sale together, so it isn't scoped
                // by listingType; rent/sale boards keep their type restriction.
                ->when($boardType !== 'all', function ($q) use ($boardType, $enquiryTypes) {
                    $q->where(function ($inner) use ($boardType, $enquiryTypes) {
                        $inner->where('listingType', $boardType)
                            ->orWhere(function ($deep) use ($enquiryTypes) {
                                $deep->whereNull('listingType')
                                    ->whereIn('enquiryType', $enquiryTypes);
                            });
                    });
                })
                ->whereBetween('created_at', [$startDate, $endDate])
                ->when($restrictToUsers, fn ($q) => $q->whereIn('agentId', $userIds))
                ->orderBy('id', 'desc')
                ->get();

            $grouped = $enquiries->groupBy('status');

            $statusMap = $boardType === 'all'
                ? Constants::ENQUIRY_ALL_STATUS
                : Constants::enquiryStatusMap($enquiryTypes);

            $board = collect($statusMap)
                ->map(fn ($statusLabel, $statusKey) => [
                    'statusObj' => [
                        'statusKey' => $statusKey,
                        'statusLabel' => $statusLabel,
                    ],
                    'enquiries' => GetKanbanEnquiryResource::collection($grouped->get($statusKey, collect()))->toArray($request),
                ])
                ->all();

            return $this->success($board, 'Kanban enquiries retrieved successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : kanban', $e);
        }
    }

    public function getAllMatchingEnquiries(Request $request)
    {
        try {
            $enquiries = $this->enquiryService->getAllMatchingEnquiries($request);

            return $this->success(
                GetAllEnquiryResource::collection($enquiries),
                'Matching enquiries retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : getAllMatchingEnquiries', $e);
        }
    }

    /**
     * Create a new enquiry
     */
    public function addEnquiry(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'agentId' => 'nullable|exists:users,id',
            'leadSource' => 'nullable',
            'propertyId' => 'nullable|exists:properties,id',
            'phone' => 'required|string',
            'email' => 'nullable',
            'name' => 'nullable',
            'flyerSent' => 'nullable|boolean',
            'enquiryType' => 'nullable|in:'.implode(',', array_keys(Constants::ENQUIRY_TYPE)),
            'priceMin' => 'nullable',
            'priceMax' => 'nullable',
            'sizeMin' => 'nullable',
            'sizeMax' => 'nullable',
            'bedroomsMin' => 'nullable',
            'bedroomsMax' => 'nullable',
            'bathroomsMin' => 'nullable',
            'bathroomsMax' => 'nullable',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $validated = $validator->validated();
            $property = null;

            if (! empty($validated['propertyId'])) {
                $property = Property::find($validated['propertyId']);

                if ($property) {
                    $validated['listingId'] = $property->id;
                    $validated['listingType'] = $property->listingType ?? null;
                    $validated['enquiryPropertyType'] = $property->category ?? null;
                    $validated['enquiryPropertySubType'] = $property->pfPropertyType ?? $property->bayutPropertyType ?? null;
                    if (! empty($property->pfCommunity)) {
                        $validated['community'] = $property->pfCommunity;
                    }
                    if (empty($validated['enquiryType'])) {
                        $validated['enquiryType'] = match ($property->listingType) {
                            'sale' => 'buyer',
                            'rent' => 'tenant',
                            'offPlan' => 'offPlanInvestor',
                            default => 'buyer',
                        };
                    }

                    $matchingService = app(MatchingService::class);
                    $listingType = $property->listingType;

                    if ($property->price) {
                        $validated['actualPrice'] = $property->price;
                        $validated['priceMin'] = round($property->price * 0.95);
                        $validated['priceMax'] = round($property->price * 1.05);
                    }

                    if ($property->sizeArea) {
                        $validated['sizeMin'] = $property->sizeArea;
                        $validated['sizeMax'] = round($property->sizeArea * 1.15);
                    }

                    if ($property->bedrooms) {
                        $validated['bedroomsMin'] = $property->bedrooms;
                        $validated['bedroomsMax'] = $property->bedrooms + 1;
                    }
                    if ($property->bathrooms) {
                        $validated['bathroomsMin'] = $property->bathrooms;
                        $validated['bathroomsMax'] = $property->bathrooms + 1;
                    }

                }
            }

            if (! empty($validated['enquiryType'])) {
                $validated['listingType'] = in_array($validated['enquiryType'], ['landlord', 'tenant']) ? 'rent' : 'sale';
            }

            if (! empty($validated['phone']) || ! empty($validated['email'])) {
                // Phone first (matched on the last 9 digits, so any spelling of a number
                // already on file resolves to that contact rather than creating a second
                // one), then email as a fallback.
                $contact = null;
                if (! empty($validated['phone'])) {
                    $contact = Contact::resolveMasterByPhone($validated['phone']);
                }
                if (! $contact && ! empty($validated['email'])) {
                    $contact = Contact::where('email', $validated['email'])->first()?->masterOrSelf();
                }

                // Contact type is driven by the enquiry type (e.g. an "Agent" enquiry
                // makes the contact an "Agent"). Fall back to the listing type when the
                // enquiry type has no contact-type equivalent.
                $contactType = Constants::contactTypeFromEnquiryType($validated['enquiryType'] ?? null);
                if (! $contactType) {
                    $contactType = ($property && $property->listingType == 'rent')
                        ? Constants::CONTACT_TYPE_TENANT
                        : Constants::CONTACT_TYPE_BUYER;
                }

                if (! $contact) {
                    if ($validated['phone']) {
                        $contact = Contact::create([
                            'name' => $validated['name'] ?? 'Lead - '.($validated['phone'] ?? ''),
                            'phone' => $validated['phone'] ?? null,
                            'email' => $validated['email'] ?? null,
                            'contactType' => [$contactType],
                            'contactOwner' => $validated['agentId'] ?? user()->id,
                            'source' => Constants::LEAD_SOURCE_MANUAL,
                            'createdBy' => user()->id,
                        ]);
                    }
                } else {
                    // Merge — a contact can hold multiple roles over time.
                    $contact->addContactTypes($contactType);
                }
                $validated['contactId'] = $contact->id;
            }

            $validated['createdBy'] = user()->id;
            $validated['status'] = Constants::ENQUIRY_NEW_LEAD;

            // Duplicate detection (same contact + property) and the alert to the
            // original enquiry's agent are handled centrally in EnquiryService.
            // When a duplicate is found, no new enquiry is created — the existing
            // one is returned, so skip the "new enquiry" side effects below.
            // true = this is a MANUAL CRM entry, so send the new-lead WhatsApp backup.
            $enquiry = $this->enquiryService->createEnquiry($validated, true);

            if (! $enquiry->wasRecentlyCreated) {
                return $this->error(
                    'An enquiry for this contact and property already exists.',
                    409
                );
            }

            $params = [
                'action' => 'created',
                'module' => Constants::MODULE_ENQUIRY,
                'moduleId' => $enquiry->id,
                'description' => "Created enquiry: #{$enquiry->id}",
            ];
            logUserActivity($params);

            logEnquiryHistory([
                'enquiryId' => $enquiry->id,
                'field' => 'created',
                'originalValue' => null,
                'newValue' => json_encode(['message' => "Enquiry created: #{$enquiry->id}"]),
            ]);

            $agentUser = ! empty($validated['agentId'])
                ? User::find($validated['agentId'])
                : user();
            $fromEmail = $agentUser?->email;
            $fromName = $agentUser?->name;

            if (isset($contact) && ! empty($contact->email)) {
                $contactEmail = $contact->email;
                $replacements = [
                    'name' => ucwords($contact->name ?? 'Contact'),
                    'email' => $contactEmail,
                    'company_name' => getCompanyName(),
                ];

                $enquiryData = [
                    'propertyReferenceNo' => $property->referenceNumber ?? '',
                    'referenceNo' => $enquiry->referenceNo ?? '',
                    'contactPhone' => $contact->phone ?? ($validated['phone'] ?? ''),
                    'agentId' => $validated['agentId'] ?? null,
                    'leadSource' => $validated['leadSource'] ?? '', // propertyFinder, bayut, website, direct
                    'technicalSource' => 'KeyFlow CRM', // enquiries created via the CRM /addEnquiry API
                    'contactName' => $contact->name ?? ($validated['name'] ?? ''),
                    'contactNote' => $validated['comments'] ?? '',
                ];

                // Salesforce sync is non-critical and slow; push it to the queue so
                // it never blocks or fails the enquiry creation response.
                SyncEnquiryToSalesforceJob::dispatch($enquiry->id, $enquiryData);

                try {
                    $this->mailService->sendEmail(
                        Constants::EMAIL_SLUGS['enquiry']['enquiry_sent_details'],
                        $contactEmail,
                        $replacements,
                        [],
                        [],
                        $fromEmail,
                        $fromName
                    );

                    logEnquiryHistory([
                        'enquiryId' => $enquiry->id,
                        'field' => 'email_sent',
                        'originalValue' => null,
                        'newValue' => json_encode(['message' => "Email sent to {$contactEmail}"]),
                    ]);

                    $guideSlug = match (strtolower((string) $enquiry->listingType)) {
                        'sale' => Constants::EMAIL_SLUGS['contact']['buyers_guide'],
                        'rent' => Constants::EMAIL_SLUGS['contact']['tenants_guide'],
                        default => null,
                    };

                    if ($guideSlug) {
                        $this->mailService->sendEmail(
                            $guideSlug,
                            $contactEmail,
                            [
                                'contact_name' => ucwords($contact->name ?? 'Contact'),
                                'agent_name' => ucwords(user()->name ?? ''),
                                'company_name' => getCompanyName(),
                            ],
                            [],
                            [],
                            $fromEmail,
                            $fromName
                        );

                        logEnquiryHistory([
                            'enquiryId' => $enquiry->id,
                            'field' => 'automation_email_sent',
                            'originalValue' => null,
                            'newValue' => json_encode([
                                'message' => 'Automation guide sent based on listing type: '.$enquiry->listingType,
                            ]),
                        ]);
                    }
                } catch (\Throwable $e) {
                    // Email failure must not abort an already-created enquiry
                    Log::error('EnquiryController : addEnquiry : sendEmail : '.$e->getMessage());
                }
            }

            if (isset($property) && ! empty($property->physicalOwnerId)) {
                $owner = Contact::find($property->physicalOwnerId);

                if ($owner && ! empty($owner->email)) {
                    $ownerGuideSlug = match (strtolower((string) $property->listingType)) {
                        'sale' => Constants::EMAIL_SLUGS['contact']['sellers_guide'],
                        'rent' => Constants::EMAIL_SLUGS['contact']['landlord_guide'],
                        default => null,
                    };

                    if ($ownerGuideSlug) {
                        try {
                            $this->mailService->sendEmail(
                                $ownerGuideSlug,
                                $owner->email,
                                [
                                    'contact_name' => ucwords($owner->name ?? 'Contact'),
                                    'agent_name' => ucwords(user()->name ?? ''),
                                    'company_name' => getCompanyName(),
                                ],
                                [],
                                [],
                                $fromEmail,
                                $fromName
                            );

                            logEnquiryHistory([
                                'enquiryId' => $enquiry->id,
                                'field' => 'automation_email_sent',
                                'originalValue' => null,
                                'newValue' => json_encode([
                                    'message' => 'Automation guide sent to property owner based on listing type: '.$property->listingType,
                                ]),
                            ]);
                        } catch (\Exception $e) {
                            Log::error('EnquiryController : addEnquiry : sendOwnerGuideEmail : '.$e->getMessage());
                        }
                    }
                }
            }

            return $this->success(new GetEnquiryResource($enquiry), 'Enquiry created successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : addEnquiry', $e);
        }
    }

    /**
     * Update an existing enquiry
     */
    public function updateEnquiryById(Request $request, $id)
    {
        $existingEnquiry = Enquiry::find($id);
        $effectiveEnquiryType = $request->input('enquiryType') ?? $existingEnquiry?->enquiryType;
        $statusMap = Constants::enquiryStatusMap($effectiveEnquiryType);

        $validator = Validator::make($request->all(), [
            'agentId' => 'nullable|exists:users,id',
            'contactId' => 'nullable|exists:contacts,id',
            'listingId' => 'nullable|exists:properties,id',
            'phone' => 'nullable',
            'enquiryType' => 'nullable',
            'status' => 'nullable|in:'.implode(',', array_keys($statusMap)),
            'referredBy' => 'nullable',
            'actualPrice' => 'nullable',
            'priceMin' => 'nullable',
            'priceMax' => 'nullable',
            'sizeMin' => 'nullable',
            'sizeMax' => 'nullable',
            'bedroomsMin' => 'nullable',
            'bedroomsMax' => 'nullable',
            'bathroomsMin' => 'nullable',
            'bathroomsMax' => 'nullable',
            'listingType' => 'nullable',
            'lostReason' => 'nullable',
            'enquiryPropertyType' => 'nullable',
            'enquiryPropertySubType' => 'nullable',
            'leadSource' => 'nullable',
            'technicalSource' => 'nullable',
            'comments' => 'nullable',
            'isOpenGame' => 'nullable',
            'isFollowing' => 'nullable',
            'isFavorite' => 'nullable',
            'isQualified' => 'nullable',
            'isActive' => 'nullable',
            'flyerSent' => 'nullable|boolean',
            'city' => 'nullable|string',
            'community' => 'nullable|string',
            'community_2' => 'nullable|string',
            'community_3' => 'nullable|string',
            'leadQualification' => 'nullable|string',
            'projectName' => 'nullable|string',
            'contactMethod' => 'nullable|string',
            'webUrl' => 'nullable|string',
            'propertyPropertyFinder' => 'nullable|string',
            'recordType' => 'nullable|string',
            'extras' => 'nullable|string',
            // Manual lead-score override (hot/warm/cold). Empty clears it, reverting
            // the score to the automatic (status-derived) value.
            'leadRating' => 'nullable|in:'.implode(',', array_keys(Constants::ENQUIRY_LEAD_SCORE)),
            'leadOutcome' => 'nullable|string',
            'outcome' => 'nullable|string',
            'enquiryRecordUrl' => 'nullable|string',
            'filterNumber' => 'nullable|string',
            'smsOptOut' => 'nullable|boolean',
            'unitNumber' => 'nullable|string',
            'unitType' => 'nullable|string',
            'yearBuiltMin' => 'nullable|integer',
            'yearBuiltMax' => 'nullable|integer',
            'floorMin' => 'nullable|integer',
            'floorMax' => 'nullable|integer',
            'parkingSpaceMin' => 'nullable|integer',
            'parkingSpaceMax' => 'nullable|integer',
            'privateAmenities' => 'nullable|array',
            'view' => 'nullable|string',
            'furnished' => 'nullable|string',
            'elPartners' => 'nullable|string',
            'partnersEmail' => 'nullable|string',
            'partnerEmailCc' => 'nullable|string',
            'investmentMotivation' => 'nullable|string',
            'budget' => 'nullable',
            'dubaiExperience' => 'nullable|string',
            'timeline' => 'nullable|string',
            'apartmentType' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $validated = $validator->validated();
            $oldEnquiry = Enquiry::find($id);
            $oldValues = $oldEnquiry ? $oldEnquiry->only(array_keys($validated)) : [];
            $enquiry = $this->enquiryService->updateEnquiry($id, $validated);

            $params = [
                'action' => 'updated',
                'module' => Constants::MODULE_ENQUIRY,
                'moduleId' => $enquiry->id,
                'description' => "Updated enquiry: #{$enquiry->id}",
                'oldValues' => $oldValues,
            ];
            logUserActivity($params);

            return $this->success(new GetEnquiryResource($enquiry), 'Enquiry updated successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : updateEnquiryById', $e);
        }
    }

    /**
     * Get a single enquiry by ID
     */
    public function getEnquiryById($id)
    {
        try {
            $relations = ['agent', 'contact', 'listing:id,title,referenceNumber', 'creator', 'history.user', 'follows.user', 'follows.enquiry', 'cityInfo', 'communityInfo', 'community2Info', 'community3Info', 'internalNotes', 'listing.salesforceIdMap'];

            // if (isAgent()) {
            //     $currentUserId = user()->id;
            //     $relations['tasks'] = function ($query) use ($currentUserId) {
            //         $query->where('assignedTo', $currentUserId)->with('assignedUser');
            //     };
            // } else {
            //     $relations[] = 'tasks.assignedUser';
            // }
            $relations[] = 'tasks.assignedUser';

            $query = Enquiry::with($relations);

            $enquiry = $query->find($id);

            if (! $enquiry) {
                return $this->error('The requested enquiry was not found.', 422);
            }

            $isOthers = isAgent()
                && $enquiry->agentId !== user()->id
                && ! $enquiry->isOpenGame;

            $resource = $isOthers
                ? new GetOthersEnquiryResource($enquiry)
                : new GetEnquiryResource($enquiry);

            return $this->success($resource, 'Enquiry retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : getEnquiryById', $e);
        }
    }

    /**
     * Delete enquiry(s) by ID
     */
    public function deleteEnquiryById(Request $request, $id = null)
    {
        if (isAgent()) {
            return $this->error('Agents are not allowed to delete enquiries.', 403);
        }
        $idParam = $request->input('id', $id);
        $ids = array_filter(array_map('intval', explode(',', $idParam)));

        if (empty($ids)) {
            return $this->error('Invalid enquiry id(s)', 422);
        }

        try {
            $enquiries = Enquiry::whereIn('id', $ids)->get();
            $deletedCount = $this->enquiryService->deleteEnquiries($ids);

            if ($deletedCount === 0) {
                return $this->error('The requested enquiry was not found.', 422);
            }

            $message = $deletedCount === 1
                ? 'Enquiry deleted successfully'
                : "{$deletedCount} enquiries deleted successfully";

            foreach ($ids as $enquiryId) {
                $params = [
                    'action' => 'deleted',
                    'module' => Constants::MODULE_ENQUIRY,
                    'moduleId' => $enquiryId,
                    'description' => "Deleted enquiry: #{$enquiryId}",
                ];
                logUserActivity($params);
            }

            foreach ($enquiries as $enquiry) {
                logEnquiryHistory([
                    'enquiryId' => $enquiry->id,
                    'field' => 'deleted',
                    'originalValue' => json_encode(['message' => "Enquiry: #{$enquiry->id}"]),
                    'newValue' => json_encode(['message' => 'Enquiry deleted']),
                ]);
            }

            return $this->success(
                ['deleted_count' => $deletedCount],
                $message
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : deleteEnquiryById', $e);
        }
    }

    public function getEnquiryHistoryById(Request $request, $id)
    {
        try {
            $query = EnquiryHistory::with(['enquiry:id', 'user:id,name'])
                ->where('enquiryId', $id);

            // if (isAgent()) {
            //     $query->where('userId', user()->id);
            // }

            $perPage = $request->input('per_page', 10);

            if ($perPage > 100) {
                $perPage = 100;
            }

            if ($request->has('search') && ! empty($request->search)) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('field', 'like', "%{$search}%")
                        ->orWhere('originalValue', 'like', "%{$search}%")
                        ->orWhere('newValue', 'like', "%{$search}%")
                        ->orWhere('comments', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%");
                        });
                });
            }

            $history = $query->orderBy('created_at', 'desc')->paginate($perPage);

            $history->getCollection()->transform(function ($item) {
                // Returned as a plain array on purpose. Assigning a converted string
                // back onto the model would be re-cast to Carbon and re-serialised as
                // UTC, so the browser would shift it a second time.
                $data = $item->toArray();

                $data['originalValue'] = json_decode($item->originalValue);
                $data['newValue'] = json_decode($item->newValue);
                $data['comments'] = json_decode($item->comments);

                // Raw, these timestamps go out as UTC ISO and the frontend renders them
                // in the viewer's own timezone — 90 minutes ahead of Dubai for anyone
                // looking from India. Convert to the app timezone like every other
                // endpoint does (GetAllEnquiryResource, TaskResource, ...).
                $data['created_at'] = convertToTimezone($item->created_at);
                $data['updated_at'] = convertToTimezone($item->updated_at);

                return $data;
            });

            return $this->success($history, 'Enquiry history retrieved successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : getEnquiryHistoryById', $e);
        }
    }

    public function exportEnquiries(Request $request)
    {
        if (isAgent()) {
            if ($actor = user()) {
                NotifyDataExportJob::dispatch($actor->id, Constants::MODULE_ENQUIRY, 0, now()->toISOString(), 'blocked');
            }

            return $this->error('Agents are not allowed to export data.', 403);
        }

        try {
            $request->merge([
                'per_page' => 100000,
                'sortBy' => 'id',
                'sortOrder' => 'desc',
            ]);

            $paginator = $this->enquiryService->getAllEnquiries($request);
            $enquiries = $paginator->getCollection();

            $fileName = 'enquiries_'.now()->format('Y_m_d_H_i_s').'.csv';

            $columnMap = [
                'referenceNo' => ['label' => 'Reference No', 'value' => fn ($e) => $e->referenceNo],
                'agentId' => ['label' => 'Agent', 'value' => fn ($e) => $e->agent?->name],
                'contactId' => ['label' => 'Contact', 'value' => fn ($e) => $e->contact?->masterOrSelf()->name],
                'listingId' => ['label' => 'Listing', 'value' => fn ($e) => $e->listing?->title],
                'city' => ['label' => 'City', 'value' => fn ($e) => $e->cityInfo?->name ?? $e->city],
                'community' => ['label' => 'Community', 'value' => fn ($e) => $e->communityInfo?->name ?? $e->community],
                'community_2' => ['label' => 'Community 2', 'value' => fn ($e) => $e->community2Info?->name ?? $e->community_2],
                'community_3' => ['label' => 'Community 3', 'value' => fn ($e) => $e->community3Info?->name ?? $e->community_3],
                'projectName' => ['label' => 'Project Name', 'value' => fn ($e) => $e->projectName],
                'enquiryPropertyType' => ['label' => 'Property Type', 'value' => fn ($e) => $e->enquiryPropertyType ? (Constants::ENQUIRY_PROPERTY_TYPE[$e->enquiryPropertyType] ?? $e->enquiryPropertyType) : null],
                'enquiryPropertySubType' => ['label' => 'Property Sub Type', 'value' => fn ($e) => $e->enquiryPropertySubType],
                'enquiryType' => ['label' => 'Enquiry Type', 'value' => fn ($e) => $e->enquiryType ? (Constants::ENQUIRY_TYPE[$e->enquiryType] ?? $e->enquiryType) : null],
                'phone' => ['label' => 'Phone', 'value' => fn ($e) => $e->phone],
                'status' => ['label' => 'Status', 'value' => fn ($e) => $e->status ? (Constants::enquiryStatusMap($e->enquiryType)[$e->status] ?? $e->status) : null],
                'referredBy' => ['label' => 'Referred By', 'value' => fn ($e) => $e->referredBy],
                'actualPrice' => ['label' => 'Actual Price', 'value' => fn ($e) => $e->actualPrice],
                'priceMin' => ['label' => 'Price Min', 'value' => fn ($e) => $e->priceMin],
                'priceMax' => ['label' => 'Price Max', 'value' => fn ($e) => $e->priceMax],
                'sizeMin' => ['label' => 'Size Min', 'value' => fn ($e) => $e->sizeMin],
                'sizeMax' => ['label' => 'Size Max', 'value' => fn ($e) => $e->sizeMax],
                'bedroomsMin' => ['label' => 'Bedrooms Min', 'value' => fn ($e) => $e->bedroomsMin],
                'bedroomsMax' => ['label' => 'Bedrooms Max', 'value' => fn ($e) => $e->bedroomsMax],
                'bathroomsMin' => ['label' => 'Bathrooms Min', 'value' => fn ($e) => $e->bathroomsMin],
                'bathroomsMax' => ['label' => 'Bathrooms Max', 'value' => fn ($e) => $e->bathroomsMax],
                'yearBuiltMin' => ['label' => 'Year Built Min', 'value' => fn ($e) => $e->yearBuiltMin],
                'yearBuiltMax' => ['label' => 'Year Built Max', 'value' => fn ($e) => $e->yearBuiltMax],
                'floorMin' => ['label' => 'Floor Min', 'value' => fn ($e) => $e->floorMin],
                'floorMax' => ['label' => 'Floor Max', 'value' => fn ($e) => $e->floorMax],
                'parkingSpaceMin' => ['label' => 'Parking Space Min', 'value' => fn ($e) => $e->parkingSpaceMin],
                'parkingSpaceMax' => ['label' => 'Parking Space Max', 'value' => fn ($e) => $e->parkingSpaceMax],
                'unitNumber' => ['label' => 'Unit Number', 'value' => fn ($e) => $e->unitNumber],
                'unitType' => ['label' => 'Unit Type', 'value' => fn ($e) => $e->unitType],
                'apartmentType' => ['label' => 'Apartment Type', 'value' => fn ($e) => $e->apartmentType],
                'privateAmenities' => ['label' => 'Private Amenities', 'value' => fn ($e) => $e->privateAmenities],
                'view' => ['label' => 'View', 'value' => fn ($e) => $e->view],
                'furnished' => ['label' => 'Furnished', 'value' => fn ($e) => $e->furnished],
                'listingType' => ['label' => 'Listing Type', 'value' => fn ($e) => $e->listingType ? (Constants::ENQUIRY_LISTING_TYPE[$e->listingType] ?? $e->listingType) : null],
                'lostReason' => ['label' => 'Lost Reason', 'value' => fn ($e) => $e->lostReason],
                'leadSource' => ['label' => 'Lead Source', 'value' => fn ($e) => Constants::leadSourceVariantLabel($e->leadSource)],
                'technicalSource' => ['label' => 'Technical Source', 'value' => fn ($e) => $e->technicalSource ? (Constants::TECHNICAL_SOURCE[$e->technicalSource] ?? $e->technicalSource) : null],
                'comments' => ['label' => 'Comments', 'value' => fn ($e) => $e->comments],
                'isOpenGame' => ['label' => 'Is Open Game', 'value' => fn ($e) => $e->isOpenGame ? 'Yes' : 'No'],
                'isQualified' => ['label' => 'Is Qualified', 'value' => fn ($e) => $e->isQualified ? 'Yes' : 'No'],
                'isActive' => ['label' => 'Is Active', 'value' => fn ($e) => $e->isActive ? 'Yes' : 'No'],
                'flyerSent' => ['label' => 'Flyer Sent', 'value' => fn ($e) => $e->flyerSent ? 'Yes' : 'No'],
                'smsOptOut' => ['label' => 'SMS Opt Out', 'value' => fn ($e) => $e->smsOptOut ? 'Yes' : 'No'],
                // Mirrors GetAllEnquiryResource: leadRating is the materialised score,
                // falling back to the status-derived band for un-backfilled rows.
                'leadScore' => ['label' => 'Lead Score', 'value' => fn ($e) => $e->leadRating ?: leadScoreForStatus($e->status)],
                'leadScoreSource' => ['label' => 'Lead Score Source', 'value' => fn ($e) => $e->leadScoreLocked ? 'manual' : 'auto'],
                'leadQualification' => ['label' => 'Lead Qualification', 'value' => fn ($e) => $e->leadQualification],
                'leadOutcome' => ['label' => 'Lead Outcome', 'value' => fn ($e) => $e->leadOutcome],
                'outcome' => ['label' => 'Outcome', 'value' => fn ($e) => $e->outcome],
                'contactMethod' => ['label' => 'Contact Method', 'value' => fn ($e) => $e->contactMethod],
                'investmentMotivation' => ['label' => 'Investment Motivation', 'value' => fn ($e) => $e->investmentMotivation],
                'budget' => ['label' => 'Budget', 'value' => fn ($e) => $e->budget],
                'dubaiExperience' => ['label' => 'Dubai Experience', 'value' => fn ($e) => $e->dubaiExperience],
                'timeline' => ['label' => 'Timeline', 'value' => fn ($e) => $e->timeline],
                'elPartners' => ['label' => 'EL Partners', 'value' => fn ($e) => $e->elPartners],
                'partnersEmail' => ['label' => 'Partners Email', 'value' => fn ($e) => $e->partnersEmail],
                'partnerEmailCc' => ['label' => 'Partner Email CC', 'value' => fn ($e) => $e->partnerEmailCc],
                'webUrl' => ['label' => 'Web URL', 'value' => fn ($e) => $e->webUrl],
                'propertyPropertyFinder' => ['label' => 'Property (Property Finder)', 'value' => fn ($e) => $e->propertyPropertyFinder],
                'enquiryRecordUrl' => ['label' => 'Enquiry Record URL', 'value' => fn ($e) => $e->enquiryRecordUrl],
                'recordType' => ['label' => 'Record Type', 'value' => fn ($e) => $e->recordType],
                'filterNumber' => ['label' => 'Filter Number', 'value' => fn ($e) => $e->filterNumber],
                'extras' => ['label' => 'Extras', 'value' => fn ($e) => $e->extras],
                'createdBy' => ['label' => 'Created By', 'value' => fn ($e) => $e->creator?->name],
                'created_at' => ['label' => 'Created Date', 'value' => fn ($e) => convertToTimezone($e->created_at)],
                'updated_at' => ['label' => 'Updated Date', 'value' => fn ($e) => convertToTimezone($e->updated_at)],
                'lastActivityDate' => ['label' => 'Last Activity Date', 'value' => fn ($e) => convertToTimezone($e->lastActivityAt)],
            ];

            logUserActivity([
                'action' => 'exported',
                'module' => Constants::MODULE_ENQUIRY,
                'moduleId' => null,
                'description' => "Exported {$enquiries->count()} enquiry(ies) to CSV: {$fileName}",
            ]);

            $enquiryIds = $enquiries->pluck('id')->toArray();
            if (! empty($enquiryIds)) {
                logEnquiryHistory([
                    'enquiryId' => $enquiryIds[0],
                    'field' => 'exported_csv',
                    'originalValue' => null,
                    'newValue' => json_encode(['message' => "Exported to CSV: {$fileName}"]),
                    'comments' => json_encode(['enquiryIds' => $enquiryIds]),
                ]);
            }

            if ($actor = user()) {
                NotifyDataExportJob::dispatch($actor->id, Constants::MODULE_ENQUIRY, $enquiries->count(), now()->toISOString());
            }

            return exportCsvStream($request, $enquiries, $columnMap, $fileName);
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : exportEnquiries', $e);
        }
    }

    public function generateEnquiryPdf($id)
    {
        try {
            $query = Enquiry::query();

            if (isAgent()) {
                $query->where('agentId', user()->id);
            }

            $enquiry = $query->find($id);

            if (! $enquiry) {
                return $this->error('The requested enquiry was not found.', 422);
            }

            $params = [
                'action' => 'generated_pdf',
                'module' => Constants::MODULE_ENQUIRY,
                'moduleId' => $enquiry->id,
                'description' => "Generated PDF for enquiry: #{$enquiry->id}",
            ];
            logUserActivity($params);

            logEnquiryHistory([
                'enquiryId' => $enquiry->id,
                'field' => 'generated_pdf',
                'originalValue' => null,
                'newValue' => json_encode(['message' => "Generated PDF for enquiry: #{$enquiry->id}"]),
            ]);

            return $this->success(
                new GetEnquiryResource($enquiry),
                'Enquiry data retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : generateEnquiryPdf', $e);
        }
    }

    public function enquiryFilters(Request $request)
    {
        $payload = $request->all();

        $validator = Validator::make($payload, [
            'name' => 'required',
            'module' => 'required',
            'filters' => 'nullable|array',
            'fields' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $filter = SaveEnquiryFilter::create([
                'name' => $payload['name'],
                'module' => $payload['module'],
                'filters' => $payload['filters'] ?? [],
                'fields' => $payload['fields'] ?? [],
                'createdBy' => user()->id,
            ]);

            $params = [
                'action' => 'created',
                'module' => 'enquiry_filter',
                'moduleId' => $filter->id,
                'description' => "Saved enquiry filter: #{$filter->id} ({$filter->name})",
            ];
            logUserActivity($params);

            return $this->success(new GetEnquiryFilterResource($filter), 'Filter saved successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : enquiryFilters', $e);
        }
    }

    public function getEnquiryFilters(Request $request)
    {
        try {
            $query = SaveEnquiryFilter::where('createdBy', user()->id)->latest();

            if ($request->has('module') && in_array($request->module, [Constants::MODULE_PROPERTY, Constants::MODULE_ENQUIRY])) {
                $query->where('module', $request->module);
            }

            $filters = $query->get();

            if ($filters->isEmpty()) {
                return $this->success([], 'No filters found');
            }

            return $this->success(GetEnquiryFilterResource::collection($filters), 'Filters retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : getEnquiryFilters', $e);
        }
    }

    public function updateEnquiryFilterById(Request $request, $id)
    {
        $payload = $request->all();

        $validator = Validator::make($payload, [
            'name' => 'required',
            'module' => 'required',
            'filters' => 'nullable|array',
            'fields' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $filter = SaveEnquiryFilter::findOrFail($id);

            $filter->update([
                'name' => $payload['name'],
                'module' => $payload['module'],
                'filters' => $payload['filters'] ?? [],
                'fields' => $payload['fields'] ?? [],
            ]);

            $params = [
                'action' => 'updated',
                'module' => $payload['module'],
                'moduleId' => $filter->id,
                'description' => "Updated filter view: #{$filter->id} ({$filter->name})",
            ];
            logUserActivity($params);

            return $this->success(new GetEnquiryFilterResource($filter), 'Filter updated successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : updateEnquiryFilterById', $e);
        }
    }

    public function deleteEnquiryFilterById($id)
    {
        try {
            $filter = SaveEnquiryFilter::findOrFail($id);
            $filterId = $filter->id;
            $filterName = $filter->name;
            $filter->delete();

            $params = [
                'action' => 'deleted',
                'module' => 'enquiry_filter',
                'moduleId' => $filterId,
                'description' => "Deleted enquiry filter: #{$filterId} ({$filterName})",
            ];
            logUserActivity($params);

            return $this->success('Enquiry filter deleted successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : deleteEnquiryFilterById', $e);
        }
    }

    public function sendPropertyQuotationToEnquiryContact(Request $request)
    {
        $request->validate([
            'enquiryId' => 'required|exists:enquiries,id',
            'propertyIds' => 'required|array|min:1',
            'propertyIds.*' => 'exists:properties,id',
        ]);

        try {
            $enquiry = Enquiry::with(['contact', 'agent'])->find($request->enquiryId);

            if (! $enquiry) {
                return $this->error('The requested enquiry was not found.', 422);
            }

            if (empty($enquiry->contact?->email)) {
                return $this->error('Enquiry contact email not available', 422);
            }

            $properties = Property::with(['creator'])
                ->whereIn('id', $request->propertyIds)
                ->get();

            if ($properties->isEmpty()) {
                return $this->error('No properties found', 422);
            }

            $propertyListItems = $properties->map(function ($property) {
                return sprintf(
                    '<li><strong>%s</strong><br>Budget: %s<br>Bedrooms: %s<br>Bathrooms: %s<br>Listing Type: %s</li>',
                    $property->title ?? 'N/A',
                    $property->price ?? '-',
                    $property->bedrooms ?? '-',
                    $property->bathrooms ?? '-',
                    $property->listingType ?? 'N/A'
                );
            })->implode('<br>');

            $propertyListHtml = "<ul>{$propertyListItems}</ul>";

            $replacements = [
                'name' => ucwords($enquiry->contact?->masterOrSelf()->name ?? 'Client'),
                'email' => $enquiry->contact->email,
                'matching_table_rows' => $propertyListHtml,
                'agent_name' => ucwords($enquiry->agent?->name ?? user()->name),
                'company_name' => getCompanyName(),
            ];

            $agentUser = $enquiry->agent ?: user();

            $this->mailService->sendEmail(
                Constants::EMAIL_SLUGS['enquiry']['daily_matching'],
                $enquiry->contact->email,
                $replacements,
                [],
                [],
                $agentUser?->email,
                $agentUser?->name
            );

            logEnquiryHistory([
                'enquiryId' => $enquiry->id,
                'field' => 'property_quotation_sent',
                'originalValue' => null,
                'newValue' => json_encode(['message' => 'Quotation email sent with selected properties']),
            ]);

            $params = [
                'action' => 'sent_email',
                'module' => Constants::MODULE_ENQUIRY,
                'moduleId' => $enquiry->id,
                'description' => "Sent property quotation email for enquiry: #{$enquiry->id}",
            ];
            logUserActivity($params);

            return $this->success(null, 'Quotation email sent successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : sendPropertyQuotationToEnquiryContact', $e);
        }
    }

    public function createEnquiryPdfById(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'enquiryId' => 'required|exists:enquiries,id',
            'propertyIds' => 'required|array|min:1',
            'propertyIds.*' => 'exists:properties,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'The provided data is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $enquiryQuery = Enquiry::with(['contact', 'agent']);

            $enquiry = $enquiryQuery->find($request->enquiryId);

            if (! $enquiry) {
                return response()->json([
                    'status' => false,
                    'message' => 'The requested enquiry was not found.',
                ], 422);
            }

            $propertiesQuery = Property::with([
                'tasks',
                'propertyListingHistory',
                'propertyCategories',
            ])->whereIn('id', $request->propertyIds);

            if (isAgent()) {
                $propertiesQuery->where('agentId', user()->id);
            }

            $properties = $propertiesQuery->get();

            $params = [
                'action' => 'generated_pdf',
                'module' => Constants::MODULE_ENQUIRY,
                'moduleId' => $enquiry->id,
                'description' => "Generated PDF for enquiry: #{$enquiry->id} with properties",
            ];
            logUserActivity($params);

            logEnquiryHistory([
                'enquiryId' => $enquiry->id,
                'field' => 'generated_pdf',
                'originalValue' => null,
                'newValue' => json_encode(['message' => 'Generated PDF with '.$properties->count().' property(ies)']),
            ]);

            return $this->success([
                'enquiry' => new GetEnquiryResource($enquiry),
                'properties' => GetPropertyResource::collection($properties),
            ], 'Enquiry data retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : createEnquiryPdfById', $e);
        }
    }

    public function getEnquiryByUserId(Request $request, $id)
    {
        try {
            $enquiries = $this->enquiryService
                ->getEnquiriesByAgent($id, $request);

            return $this->success(GetEnquiryByUserIdResource::collection($enquiries), 'Enquiries retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : getEnquiryByUserId', $e);
        }
    }

    public function takeOwnership(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'enquiryId' => 'required|exists:enquiries,id',
            'agentId' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $enquiry = $this->enquiryService->takeOwnership($request->enquiryId, $request->agentId);

            logUserActivity([
                'action' => 'take_ownership',
                'module' => Constants::MODULE_ENQUIRY,
                'moduleId' => $enquiry->id,
                'description' => "Took ownership of enquiry: #{$enquiry->id}",
            ]);

            logEnquiryHistory([
                'enquiryId' => $enquiry->id,
                'field' => 'take_ownership',
                'originalValue' => null,
                'newValue' => json_encode(['message' => ($enquiry->agent?->name ?? 'Agent').' took ownership of this enquiry']),
            ]);

            return $this->success(new GetEnquiryResource($enquiry), 'Enquiry ownership transferred successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : takeOwnership', $e);
        }
    }

    public function associatedEnquiries(Request $request, $propertyId)
    {
        try {
            $query = Enquiry::with(['agent', 'contact', 'listing:id,title,referenceNumber'])
                ->where('listingId', $propertyId);

            $enquiries = $query->orderBy('created_at', 'desc')->get();

            return $this->success(
                GetAllEnquiryResource::collection($enquiries),
                'Associated enquiries retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : associatedEnquiries', $e);
        }
    }

    public function composeEmail(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'to' => 'required|email',
            'cc' => 'nullable|array',
            'cc.*' => 'email',
            'subject' => 'required|string',
            'body' => 'required|string',
            'attachOption' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        $enquiry = Enquiry::with([
            'contact', 'agent', 'listing', 'listing.city', 'listing.community', 'listing.subCommunity', 'listing.buildingTower',
            'linkedListings.property.city', 'linkedListings.property.community', 'linkedListings.property.subCommunity', 'linkedListings.property.buildingTower',
        ])->find($id);
        if (! $enquiry) {
            return $this->error('The requested enquiry was not found.', 422);
        }

        try {
            $emailBody = $request->body;
            $fileAttachments = [];

            // Get linked listing properties from the Related tab
            $linkedProperties = $enquiry->linkedListings->pluck('property')->filter();

            // Attach: collect permit_test.pdf only if it already exists for each linked property
            if ($request->attachOption === 'attach' || $request->attachOption === 'both') {
                foreach ($linkedProperties as $linkedProperty) {
                    $permitPdfPath = storage_path("app/public/propertymediapdf/{$linkedProperty->id}/permit_test/permit_test.pdf");
                    if (file_exists($permitPdfPath)) {
                        $fileAttachments[] = [
                            'path' => $permitPdfPath,
                            'name' => ($linkedProperty->referenceNumber ?? $linkedProperty->title ?? 'property_'.$linkedProperty->id).'_permit.pdf',
                        ];
                    }
                }
            }

            // Inline: add linked listings as an HTML table
            if ($request->attachOption === 'inline' || $request->attachOption === 'both') {
                if ($linkedProperties->isNotEmpty()) {
                    $emailBody .= '<hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;">';
                    $emailBody .= '<h3 style="margin-bottom: 10px;">Linked Listings</h3>';
                    $emailBody .= '<table style="width: 100%; border-collapse: collapse; font-size: 14px; border: 1px solid #e0e0e0;">';
                    $emailBody .= '<thead><tr style="background-color: #f5f5f5;">';
                    $emailBody .= '<th style="padding: 8px 10px; text-align: left; border: 1px solid #e0e0e0;">Ref No.</th>';
                    $emailBody .= '<th style="padding: 8px 10px; text-align: left; border: 1px solid #e0e0e0;">Title</th>';
                    $emailBody .= '<th style="padding: 8px 10px; text-align: left; border: 1px solid #e0e0e0;">Type</th>';
                    $emailBody .= '<th style="padding: 8px 10px; text-align: left; border: 1px solid #e0e0e0;">Bedrooms</th>';
                    $emailBody .= '<th style="padding: 8px 10px; text-align: left; border: 1px solid #e0e0e0;">Size (sqft)</th>';
                    $emailBody .= '<th style="padding: 8px 10px; text-align: left; border: 1px solid #e0e0e0;">Price</th>';
                    $emailBody .= '<th style="padding: 8px 10px; text-align: left; border: 1px solid #e0e0e0;">Location</th>';
                    $emailBody .= '</tr></thead><tbody>';

                    foreach ($linkedProperties as $linkedProperty) {
                        $location = collect([
                            $linkedProperty->city?->location ?? null,
                            $linkedProperty->community?->community ?? null,
                            $linkedProperty->subCommunity?->subCommunity ?? null,
                            $linkedProperty->buildingTower?->tower ?? null,
                        ])->filter()->implode(', ');

                        $emailBody .= '<tr>';
                        $emailBody .= '<td style="padding: 6px 10px; border: 1px solid #e0e0e0;">'.htmlspecialchars($linkedProperty->referenceNumber ?? '-').'</td>';
                        $emailBody .= '<td style="padding: 6px 10px; border: 1px solid #e0e0e0;">'.htmlspecialchars($linkedProperty->title ?? '-').'</td>';
                        $emailBody .= '<td style="padding: 6px 10px; border: 1px solid #e0e0e0;">'.htmlspecialchars($linkedProperty->listingType ?? '-').'</td>';
                        $emailBody .= '<td style="padding: 6px 10px; border: 1px solid #e0e0e0;">'.htmlspecialchars($linkedProperty->bedrooms ?? '-').'</td>';
                        $emailBody .= '<td style="padding: 6px 10px; border: 1px solid #e0e0e0;">'.htmlspecialchars($linkedProperty->sizeArea ?? '-').'</td>';
                        $emailBody .= '<td style="padding: 6px 10px; border: 1px solid #e0e0e0;">'.htmlspecialchars($linkedProperty->price ?? '-').'</td>';
                        $emailBody .= '<td style="padding: 6px 10px; border: 1px solid #e0e0e0;">'.htmlspecialchars($location ?: '-').'</td>';
                        $emailBody .= '</tr>';
                    }

                    $emailBody .= '</tbody></table>';
                }
            }

            $html = view('EmailTemplate.templateLayout', [
                'title' => $request->subject,
                'body' => $emailBody,
            ])->render();

            $mail = Mail::to($request->to);

            if (! empty($request->cc)) {
                $mail->cc($request->cc);
            }

            $agentUser = $enquiry->agent ?: user();

            $mail->send(
                new DynamicMail(
                    $request->subject,
                    $html,
                    $fileAttachments,
                    $agentUser?->email,
                    $agentUser?->name
                )
            );

            $this->mailService->logContactEmail($request->to, $request->body);

            if (! empty($request->cc)) {
                foreach ($request->cc as $ccEmail) {
                    $this->mailService->logContactEmail($ccEmail, $request->body);
                }
            }

            $params = [
                'action' => 'compose_email',
                'module' => Constants::MODULE_ENQUIRY,
                'moduleId' => $id,
                'description' => "Composed email for enquiry ID: {$id} to {$request->to}".(! empty($request->cc) ? ' cc: '.implode(', ', $request->cc) : ''),
                'extraData' => [
                    'to' => $request->to,
                    'cc' => ! empty($request->cc) ? implode(', ', $request->cc) : null,
                    'subject' => $request->subject,
                    'emailContent' => $request->body,
                ],
            ];
            logUserActivity($params);

            logEnquiryHistory([
                'enquiryId' => $id,
                'field' => 'compose_email',
                'originalValue' => null,
                'newValue' => json_encode(['message' => "Email composed and sent to {$request->to}".(! empty($request->cc) ? ' cc: '.implode(', ', $request->cc) : '')]),
                'extraData' => [
                    'to' => $request->to,
                    'cc' => ! empty($request->cc) ? implode(', ', $request->cc) : null,
                    'subject' => $request->subject,
                    'emailContent' => $request->body,
                ],
            ]);

            return $this->success(null, 'Email sent successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryController : composeEmail', $e);
        }
    }
}
