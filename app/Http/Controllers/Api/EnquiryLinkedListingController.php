<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EnquiryLinkedListingResource\EnquiryLinkedListingResource;
use App\Http\Resources\EnquiryResource\GetAllEnquiryResource;
use App\Jobs\SendWhatsAppTemplateJob;
use App\Models\Enquiry;
use App\Models\Property;
use App\Services\EnquiryLinkedListingService;
use App\Services\WhatsAppService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EnquiryLinkedListingController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected EnquiryLinkedListingService $enquiryLinkedListingService
    ) {}

    public function linkPropertyToEnquiry(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'enquiryId' => 'required|integer|exists:enquiries,id',
            'propertyId' => 'required|array',
            'propertyId.*' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $validated = $validator->validated();

            $propertyIds = $validated['propertyId'];
            $existingPropertyIds = Property::whereIn('id', $propertyIds)->pluck('id')->toArray();
            $nonExistentIds = array_diff($propertyIds, $existingPropertyIds);

            if (! empty($nonExistentIds)) {
                $nonExistentList = implode(', ', $nonExistentIds);
                $message = count($nonExistentIds) === 1
                    ? "Property ID {$nonExistentList} does not exist"
                    : "Property IDs {$nonExistentList} do not exist";

                return $this->error($message, 422);
            }

            $validated['createdBy'] = user()->id;

            $linkedProperties = $this->enquiryLinkedListingService->linkPropertiesToEnquiry($validated);

            $count = count($linkedProperties);
            $message = $count > 0
                ? ($count === 1 ? 'Property linked successfully' : "{$count} properties linked successfully")
                : 'No new properties linked (already linked)';

            if ($count > 0) {
                $params = [
                    'action' => 'created',
                    'module' => 'enquiry_linked_listing',
                    'moduleId' => $validated['enquiryId'],
                    'description' => "Linked {$count} property(ies) to enquiry #{$validated['enquiryId']}",
                ];
                logUserActivity($params);

                logEnquiryHistory([
                    'enquiryId' => $validated['enquiryId'],
                    'field' => 'property_linked',
                    'originalValue' => null,
                    'newValue' => json_encode(['message' => "Linked {$count} property(ies) to enquiry"]),
                ]);

                logPropertyHistory([
                    'propertyId' => $validated['propertyId'][0],
                    'field' => 'enquiry_linked',
                    'newValue' => json_encode(['message' => "Linked to enquiry #{$validated['enquiryId']}"]),
                    'comments' => json_encode([
                        'action' => 'enquiry_linked',
                        'createdBy' => user()->name ?? 'System',
                        'items' => $validated['propertyId'],
                    ]),
                ]);

                // Interested Listing Notification: tell the client about each newly linked property
                $this->sendInterestedListingNotifications($validated['enquiryId'], $linkedProperties);
            }

            return $this->success(
                EnquiryLinkedListingResource::collection($linkedProperties),
                $message
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryLinkedListingController : linkPropertyToEnquiry', $e);
        }
    }

    /**
     * "Interested Listing Notification" — when listings are linked to a client's enquiry,
     * send the client a WhatsApp (new_listing_client_version template) for each new listing.
     * Fail-safe: never breaks the linking request.
     */
    protected function sendInterestedListingNotifications(int $enquiryId, $linkedProperties): void
    {
        try {
            $template = config('services.whatsapp_360.templates.interested_listing');
            if (! $template) {
                return;
            }

            $enquiry = Enquiry::with(['contact', 'agent'])->find($enquiryId);
            $clientPhone = $enquiry?->contact?->phone;
            if (! $enquiry || ! $clientPhone) {
                return;
            }

            $fallbackImage = config('services.whatsapp_360.fallback_image');
            $siteBase = rtrim(env('FRONTEND_URL', config('app.url')), '/');

            foreach ($linkedProperties as $linked) {
                $propertyId = $linked->propertyId ?? $linked->id ?? null;
                if (! $propertyId) {
                    continue;
                }

                $property = Property::with(['community', 'primaryMediaThumbnail'])->find($propertyId);
                if (! $property) {
                    continue;
                }

                $image = getImageUrl($property->primaryMediaThumbnail?->filePath) ?: $fallbackImage;

                $components = [
                    WhatsAppService::imageHeader($image),
                    WhatsAppService::textParams([
                        $enquiry->bedroomsMin ?? 'N/A',                                      // {{1}} bedrooms enquired
                        $enquiry->enquiryPropertyType ?? $enquiry->enquiryType ?? 'property', // {{2}} property type
                        $enquiry->community ?? 'Dubai',                                      // {{3}} enquiry community
                        $enquiry->agent?->name ?? 'our agent',                              // {{4}} agent name
                        $enquiry->leadSource ?? 'our portal',                               // {{5}} source
                        $property->community?->name ?? $property->pfCommunity ?? 'N/A',      // {{6}} listing community
                        $property->price ?? 'N/A',                                          // {{7}} price
                        $property->bedrooms ?? 'N/A',                                        // {{8}} listing bedrooms
                        $property->referenceNumber ?? $property->id,                         // {{9}} listing reference
                        $siteBase.'/properties/'.$property->id,                              // {{10}} link
                    ]),
                ];

                SendWhatsAppTemplateJob::dispatch($clientPhone, $template, $components);
            }
        } catch (\Throwable $e) {
            Log::error('Interested Listing Notification failed: '.$e->getMessage());
        }
    }

    public function unlinkPropertyFromEnquiry(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'enquiryId' => 'required|integer|exists:enquiries,id',
            'propertyId' => 'required|array',
            'propertyId.*' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $validated = $validator->validated();

            $propertyIds = $validated['propertyId'];
            $existingPropertyIds = Property::whereIn('id', $propertyIds)->pluck('id')->toArray();
            $nonExistentIds = array_diff($propertyIds, $existingPropertyIds);

            if (! empty($nonExistentIds)) {
                $nonExistentList = implode(', ', $nonExistentIds);
                $message = count($nonExistentIds) === 1
                    ? "Property ID {$nonExistentList} does not exist"
                    : "Property IDs {$nonExistentList} do not exist";

                return $this->error($message, 422);
            }

            $result = $this->enquiryLinkedListingService->unlinkPropertiesFromEnquiry(
                $validated['enquiryId'],
                $validated['propertyId']
            );

            $count = $result['unlinked_count'];
            $message = $count > 0
                ? ($count === 1 ? 'Property unlinked successfully' : "{$count} properties unlinked successfully")
                : 'No properties were unlinked (not linked)';

            if ($count > 0) {
                $params = [
                    'action' => 'deleted',
                    'module' => 'enquiry_linked_listing',
                    'moduleId' => $validated['enquiryId'],
                    'description' => "Unlinked {$count} property(ies) from enquiry #{$validated['enquiryId']}",
                ];
                logUserActivity($params);

                logEnquiryHistory([
                    'enquiryId' => $validated['enquiryId'],
                    'field' => 'property_unlinked',
                    'originalValue' => null,
                    'newValue' => json_encode(['message' => "Unlinked {$count} property(ies) from enquiry"]),
                ]);

                logPropertyHistory([
                    'propertyId' => $validated['propertyId'][0],
                    'field' => 'enquiry_unlinked',
                    'newValue' => json_encode(['message' => "Unlinked from enquiry #{$validated['enquiryId']}"]),
                    'comments' => json_encode([
                        'action' => 'enquiry_unlinked',
                        'createdBy' => user()->name ?? 'System',
                        'items' => $validated['propertyId'],
                    ]),
                ]);
            }

            return $this->success(
                [
                    'unlinked_count' => $result['unlinked_count'],
                    'not_linked' => $result['not_linked'],
                ],
                $message
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryLinkedListingController : unlinkPropertyFromEnquiry', $e);
        }
    }

    public function getLinkedPropertiesByEnquiry(Request $request, int $enquiryId)
    {
        try {
            $perPage = $request->input('per_page', 10);

            if ($perPage > 100) {
                $perPage = 100;
            }

            $linkedListings = $this->enquiryLinkedListingService->getLinkedPropertiesByEnquiry($enquiryId, $request);

            if ($linkedListings->isEmpty()) {
                return $this->success(
                    [
                        'data' => [],
                        'meta' => [
                            'current_page' => 1,
                            'from' => null,
                            'last_page' => 1,
                            'per_page' => (int) $perPage,
                            'to' => null,
                            'total' => 0,
                        ],
                    ],
                    'No linked properties found'
                );
            }

            return $this->success(
                [
                    'data' => EnquiryLinkedListingResource::collection($linkedListings->items()),
                    'meta' => [
                        'current_page' => $linkedListings->currentPage(),
                        'from' => $linkedListings->firstItem(),
                        'last_page' => $linkedListings->lastPage(),
                        'per_page' => $linkedListings->perPage(),
                        'to' => $linkedListings->lastItem(),
                        'total' => $linkedListings->total(),
                    ],
                ],
                'Linked properties retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryLinkedListingController : getLinkedPropertiesByEnquiry', $e);
        }
    }

    public function linkEnquiryToProperty(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'propertyId' => 'required|integer|exists:properties,id',
            'enquiryId' => 'required|array',
            'enquiryId.*' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $validated = $validator->validated();
            $enquiryIds = $validated['enquiryId'];
            $existingEnquiryIds = Enquiry::whereIn('id', $enquiryIds)->pluck('id')->toArray();
            $nonExistentIds = array_diff($enquiryIds, $existingEnquiryIds);

            if (! empty($nonExistentIds)) {
                $nonExistentList = implode(', ', $nonExistentIds);
                $message = count($nonExistentIds) === 1
                    ? "Enquiry ID {$nonExistentList} does not exist"
                    : "Enquiry IDs {$nonExistentList} do not exist";

                return $this->error($message, 422);
            }

            $validated['createdBy'] = user()->id;

            $linkedEnquiries = $this->enquiryLinkedListingService->linkEnquiriesToProperty($validated);

            $count = count($linkedEnquiries);
            $message = $count > 0
                ? ($count === 1 ? 'Enquiry linked successfully' : "{$count} enquiries linked successfully")
                : 'No new enquiries linked (already linked)';

            if ($count > 0) {
                $params = [
                    'action' => 'created',
                    'module' => 'enquiry_linked_listing',
                    'moduleId' => $validated['propertyId'],
                    'description' => "Linked {$count} enquiry(ies) to property #{$validated['propertyId']}",
                ];
                logUserActivity($params);

                logPropertyHistory([
                    'propertyId' => $validated['propertyId'],
                    'field' => 'enquiry_linked',
                    'newValue' => json_encode(['message' => "Linked {$count} enquiry(ies) to property"]),
                ]);

                logEnquiryHistory([
                    'enquiryId' => $validated['enquiryId'][0],
                    'field' => 'property_linked',
                    'originalValue' => null,
                    'newValue' => json_encode(['message' => "Linked to property #{$validated['propertyId']}"]),
                    'comments' => json_encode(['enquiryIds' => $validated['enquiryId']]),
                ]);
            }

            return $this->success(
                EnquiryLinkedListingResource::collection($linkedEnquiries),
                $message
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryLinkedListingController : linkEnquiryToProperty', $e);
        }
    }

    public function unlinkEnquiryFromProperty(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'propertyId' => 'required|integer|exists:properties,id',
            'enquiryId' => 'required|array',
            'enquiryId.*' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $validated = $validator->validated();
            $enquiryIds = $validated['enquiryId'];
            $existingEnquiryIds = Enquiry::whereIn('id', $enquiryIds)->pluck('id')->toArray();
            $nonExistentIds = array_diff($enquiryIds, $existingEnquiryIds);

            if (! empty($nonExistentIds)) {
                $nonExistentList = implode(', ', $nonExistentIds);
                $message = count($nonExistentIds) === 1
                    ? "Enquiry ID {$nonExistentList} does not exist"
                    : "Enquiry IDs {$nonExistentList} do not exist";

                return $this->error($message, 422);
            }

            $result = $this->enquiryLinkedListingService->unlinkEnquiriesFromProperty(
                $validated['propertyId'],
                $validated['enquiryId']
            );

            $count = $result['unlinked_count'];
            $message = $count > 0
                ? ($count === 1 ? 'Enquiry unlinked successfully' : "{$count} enquiries unlinked successfully")
                : 'No enquiries were unlinked (not linked)';

            if ($count > 0) {
                $params = [
                    'action' => 'deleted',
                    'module' => 'enquiry_linked_listing',
                    'moduleId' => $validated['propertyId'],
                    'description' => "Unlinked {$count} enquiry(ies) from property #{$validated['propertyId']}",
                ];
                logUserActivity($params);

                logPropertyHistory([
                    'propertyId' => $validated['propertyId'],
                    'field' => 'enquiry_unlinked',
                    'newValue' => json_encode(['message' => "Unlinked {$count} enquiry(ies) from property"]),
                ]);

                logEnquiryHistory([
                    'enquiryId' => $validated['enquiryId'][0],
                    'field' => 'property_unlinked',
                    'originalValue' => null,
                    'newValue' => json_encode(['message' => "Unlinked from property #{$validated['propertyId']}"]),
                    'comments' => json_encode(['enquiryIds' => $validated['enquiryId']]),
                ]);
            }

            return $this->success(
                [
                    'unlinked_count' => $result['unlinked_count'],
                    'not_linked' => $result['not_linked'],
                ],
                $message
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryLinkedListingController : unlinkEnquiryFromProperty', $e);
        }
    }

    public function getLinkedEnquiriesByProperty(Request $request, int $propertyId)
    {
        try {
            $linkedListings = $this->enquiryLinkedListingService->getLinkedEnquiriesByProperty($propertyId, $request);

            return $this->success(
                EnquiryLinkedListingResource::collection($linkedListings),
                'Linked enquiries retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryLinkedListingController : getLinkedEnquiriesByProperty', $e);
        }
    }

    public function getAllLinkedPropertiesByEnquiry(Request $request, int $enquiryId)
    {
        try {
            $properties = $this->enquiryLinkedListingService->getMatchingPropertiesForEnquiry($enquiryId, $request);

            return $this->success($properties, 'Matching properties retrieved successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryLinkedListingController : getAllLinkedPropertiesByEnquiry', $e);
        }
    }

    public function getAllLinkedEnquiriesByProperty(Request $request, int $propertyId)
    {
        try {
            $enquiries = $this->enquiryLinkedListingService->getAllLinkedEnquiriesByProperty($propertyId, $request);

            return $this->success(
                GetAllEnquiryResource::collection($enquiries),
                'Matching enquiries retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('EnquiryLinkedListingController : getAllLinkedEnquiriesByProperty', $e);
        }
    }
}
