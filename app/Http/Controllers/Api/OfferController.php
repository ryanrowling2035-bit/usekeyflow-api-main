<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Http\Resources\OfferResources\OfferResource;
use App\Jobs\NotifyDataExportJob;
use App\Models\Contact;
use App\Models\Enquiry;
use App\Models\Offer;
use App\Models\Property;
use App\Services\ContactService;
use App\Services\FileStorageService;
use App\Services\InternalNoteService;
use App\Services\OfferPdfService;
use App\Services\OfferService;
use App\Services\PropertyService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OfferController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OfferService $offerService,
        private ContactService $contactService,
        private FileStorageService $fileStorage,
    ) {}

    public function index(Request $request)
    {
        try {
            $offers = $this->offerService->list($request);

            return $this->success(
                OfferResource::collection($offers),
                'Offers retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('OfferController : index', $e);
        }
    }

    public function getAllOffers(Request $request, $enquiryId)
    {
        try {
            $offers = $this->offerService->listByEnquiryId($request, $enquiryId);

            return $this->success(
                OfferResource::collection($offers),
                'Offers retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('OfferController : getAllOffers', $e);
        }
    }

    public function addOffer(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'listingId' => 'required_without:enquiryId|nullable|integer',
            'enquiryId' => 'required_without:listingId|nullable|integer',
            'buyerId' => 'required_without:enquiryId|nullable|integer',
            'offeredAmount' => 'sometimes|nullable|numeric',
            'targetDate' => 'sometimes|nullable|date',
            'status' => 'sometimes|nullable|in:'.implode(',', array_keys(Constants::OFFER_STATUS)),
            'offerDate' => 'sometimes|nullable|date',
            'offerExpirationDate' => 'sometimes|nullable|date',
            'sellerLandlord' => 'sometimes|nullable|integer',
            'listingType' => 'sometimes|nullable|string',
            'listingPrice' => 'sometimes|nullable|numeric',
            'leaseTerms' => 'sometimes|nullable|string',
            'nationality' => 'sometimes|nullable|string|max:255',
            'residencyStatus' => 'sometimes|nullable|string|max:50',
            'potentialCommission' => 'sometimes|nullable|numeric',
            'deposits' => 'sometimes|nullable|numeric',
            'terms' => 'sometimes|nullable|string',
            'assignedAgentId' => 'sometimes|nullable|integer',
            'internalNotes' => 'sometimes|nullable|string',
            'name' => 'sometimes|nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }
        $data = $validator->validated();
        $internalNotes = $data['internalNotes'] ?? null;
        unset($data['internalNotes']);
        $data['createdBy'] = auth()->id();
        if (empty($data['status'])) {
            $data['status'] = Constants::OFFER_STATUS_NEW;
        }

        try {
            $enquiry = null;
            if (! empty($data['enquiryId'])) {
                $enquiry = Enquiry::with('contact')->findOrFail($data['enquiryId']);
                $data['buyerId'] = $enquiry->contact?->id;
                // if (empty($data['listingId'])) {
                //     $data['listingId'] = $enquiry->listingId;
                // }
            }

            $this->contactService->syncBuyerFields($data['buyerId'], $data);
            unset($data['nationality'], $data['residencyStatus']);

            $property = ! empty($data['listingId'])
                ? Property::select('listingType', 'agentId', 'physicalOwnerId', 'referenceNumber')->find($data['listingId'])
                : null;
            $listingType = $enquiry?->listingType ?? $property?->listingType;
            $data['listingType'] = $listingType === 'rent' ? 'rent' : 'sale';
            $data['sellerLandlord'] = $property?->physicalOwnerId;

            if (isAgent()) {
                $data['assignedAgentId'] = auth()->id();
            } else {
                $data['assignedAgentId'] = $enquiry?->agentId ?? $property?->agentId;
            }

            if (empty($data['name'])) {
                $buyerName = Contact::where('id', $data['buyerId'])->value('name');
                $nameSuffix = $property?->referenceNumber ?? $enquiry?->referenceNo ?? '';
                $data['name'] = trim(($buyerName ?? '').' & '.$nameSuffix, ' &');
            }

            $offer = $this->offerService->store($data);

            $actor = auth()->user();
            $hasInternalNotes = $request->has('internalNotes');
            $offerStatus = $data['status'] ?? null;

            dispatch(function () use ($offer, $hasInternalNotes, $internalNotes, $offerStatus, $actor) {
                if ($hasInternalNotes) {
                    app(InternalNoteService::class)->syncModuleNotes(
                        Constants::MODULE_OFFER,
                        $offer->id,
                        ['internalNotes' => $internalNotes]
                    );
                }

                logUserActivity([
                    'action' => 'created',
                    'module' => Constants::MODULE_OFFER,
                    'moduleId' => $offer->id,
                    'description' => "Created offer: {$offer->name}",
                    'userId' => $actor?->id,
                ]);

                if ($offer->enquiryId) {
                    logEnquiryHistory([
                        'enquiryId' => $offer->enquiryId,
                        'field' => 'offer_created',
                        'originalValue' => null,
                        'newValue' => json_encode(['message' => "Offer created: {$offer->name}"]),
                    ]);
                }

                if ($offer->listingId) {
                    logPropertyHistory([
                        'propertyId' => $offer->listingId,
                        'field' => 'offer_created',
                        'newValue' => json_encode(['message' => "Offer created: {$offer->name}"]),
                    ]);
                }

                $listingProperty = Property::find($offer->listingId);
                if ($listingProperty && $listingProperty->status !== Constants::PROPERTY_STATUS_UNDER_OFFER) {
                    $listingProperty->update(['status' => Constants::PROPERTY_STATUS_UNDER_OFFER]);
                    PropertyService::notifyAdminsManagersOnStatusChange($listingProperty, 'Under Offer', $actor);
                }

                if ($offer->enquiryId) {
                    $linkedEnquiry = Enquiry::find($offer->enquiryId);
                    if ($linkedEnquiry && $linkedEnquiry->status !== Constants::ENQUIRY_UNDER_OFFER) {
                        $previousEnquiryStatus = $linkedEnquiry->status;
                        $linkedEnquiry->update(['status' => Constants::ENQUIRY_UNDER_OFFER]);
                        logEnquiryHistory([
                            'enquiryId' => $linkedEnquiry->id,
                            'field' => 'status',
                            'originalValue' => $previousEnquiryStatus,
                            'newValue' => Constants::ENQUIRY_UNDER_OFFER,
                        ]);
                    }
                }

                app(OfferService::class)->createClosingIfAccepted($offer, $offerStatus);
            })->afterResponse();

            return new OfferResource($offer);
        } catch (\Exception $e) {
            return $this->exceptionResponse('OfferController : addOffer', $e);
        }
    }

    public function show(string $id)
    {
        try {
            $offer = $this->offerService->getById($id);

            if (! $offer) {
                return $this->error('The requested offer was not found.', 422);
            }

            return $this->success(
                new OfferResource($offer),
                'Offer retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('OfferController : show', $e);
        }
    }

    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required',
            'listingId' => 'sometimes|nullable|integer',
            'buyerId' => 'sometimes|required',
            'offeredAmount' => 'sometimes|nullable|numeric',
            'targetDate' => 'sometimes|nullable|date',
            'status' => 'sometimes|nullable|in:'.implode(',', array_keys(Constants::OFFER_STATUS)),
            'offerDate' => 'sometimes|nullable|date',
            'offerExpirationDate' => 'sometimes|nullable|date',
            'sellerLandlord' => 'sometimes|nullable|integer',
            'listingType' => 'sometimes|nullable|string',
            'listingPrice' => 'sometimes|nullable|numeric',
            'leaseTerms' => 'sometimes|nullable|string',
            'nationality' => 'sometimes|nullable|string|max:255',
            'residencyStatus' => 'sometimes|nullable|string|max:50',
            'potentialCommission' => 'sometimes|nullable|numeric',
            'deposits' => 'sometimes|nullable|numeric',
            'enquiryId' => 'sometimes|nullable|integer',
            'terms' => 'sometimes|nullable|string',
            'assignedAgentId' => 'sometimes|nullable|integer',
            'internalNotes' => 'sometimes|nullable|string',
        ]);
        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }
        $data = $validator->validated();
        $internalNotes = $data['internalNotes'] ?? null;
        unset($data['internalNotes']);

        try {

            $buyerSyncData = array_intersect_key($data, array_flip(['nationality', 'residencyStatus']));
            unset($data['nationality'], $data['residencyStatus']);

            $offer = $this->offerService->update($id, $data);
            $this->contactService->syncBuyerFields($offer->buyerId, $buyerSyncData);

            $offerStatus = $data['status'] ?? null;
            $hasInternalNotes = $request->has('internalNotes');

            $this->offerService->createClosingIfAccepted($offer, $offerStatus);

            if ($hasInternalNotes) {
                app(InternalNoteService::class)->syncModuleNotes(
                    Constants::MODULE_OFFER,
                    $offer->id,
                    ['internalNotes' => $internalNotes]
                );
            }

            logUserActivity([
                'action' => 'updated',
                'module' => Constants::MODULE_OFFER,
                'moduleId' => $offer->id,
                'description' => "Updated offer: {$offer->name}",
            ]);

            if ($offer->enquiryId) {
                logEnquiryHistory([
                    'enquiryId' => $offer->enquiryId,
                    'field' => 'offer_updated',
                    'originalValue' => null,
                    'newValue' => json_encode(['message' => "Offer updated: {$offer->name}"]),
                ]);
            }

            if ($offer->listingId) {
                logPropertyHistory([
                    'propertyId' => $offer->listingId,
                    'field' => 'offer_updated',
                    'newValue' => json_encode(['message' => "Offer updated: {$offer->name}"]),
                ]);
            }

            return new OfferResource($offer);
        } catch (\Exception $e) {
            return $this->exceptionResponse('OfferController : update', $e);
        }
    }

    public function destroy(string $id)
    {
        try {
            $offer = $this->offerService->getById($id);

            if (! $offer) {
                return $this->error('The requested offer was not found.', 422);
            }

            $offerName = $offer->name;
            $offerEnquiryId = $offer->enquiryId;
            $offerListingId = $offer->listingId;
            $this->offerService->delete($id);

            $params = [
                'action' => 'deleted',
                'module' => Constants::MODULE_OFFER,
                'moduleId' => $id,
                'description' => "Deleted offer: {$offerName}",
            ];
            logUserActivity($params);

            if ($offerEnquiryId) {
                logEnquiryHistory([
                    'enquiryId' => $offerEnquiryId,
                    'field' => 'offer_deleted',
                    'originalValue' => json_encode(['message' => "Offer: {$offerName}"]),
                    'newValue' => json_encode(['message' => 'Offer deleted']),
                ]);
            }

            if ($offerListingId) {
                logPropertyHistory([
                    'propertyId' => $offerListingId,
                    'field' => 'offer_deleted',
                    'originalValue' => json_encode(['message' => "Offer: {$offerName}"]),
                    'newValue' => json_encode(['message' => 'Offer deleted']),
                ]);
            }

            return $this->success([], 'Offer deleted successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('OfferController : destroy', $e);
        }
    }

    public function downloadOfferPdf(string $id, string $type)
    {
        if (! in_array($type, array_keys(Constants::OFFER_LETTER_TYPES))) {
            return $this->error('Invalid type. Must be buyer, seller, landlord, or tenant.', 422);
        }

        $offer = $this->offerService->getById($id);
        if (! $offer) {
            return $this->error('The requested offer was not found.', 422);
        }

        try {
            $offerPdfService = app(OfferPdfService::class);
            $pdfContent = $offerPdfService->generatePdf($offer, $type);

            $fileName = 'offer_letter_'.$type.'_'.$offer->id.'_'.now()->format('Y_m_d').'.pdf';
            $storagePath = $this->fileStorage->recordPrefix('offer-pdfs', $offer->referenceNo, $offer->id).'/'.$fileName;

            // Offer letters are confidential — store privately and serve via a signed URL.
            $key = $this->fileStorage->putPrivate($storagePath, $pdfContent);
            $url = $this->fileStorage->temporaryUrl($key);

            logUserActivity([
                'action' => 'downloaded_pdf',
                'module' => Constants::MODULE_OFFER,
                'moduleId' => $offer->id,
                'description' => "Downloaded offer letter PDF ({$type}): {$offer->name}",
            ]);

            return $this->success(['url' => $url, 'fileName' => $fileName], 'Offer PDF generated successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('OfferController : downloadOfferPdf', $e);
        }
    }

    public function exportOfferCsv(Request $request)
    {
        if (isAgent()) {
            if ($actor = user()) {
                NotifyDataExportJob::dispatch($actor->id, Constants::MODULE_OFFER, 0, now()->toISOString(), 'blocked');
            }

            return $this->error('Agents are not allowed to export data.', 403);
        }

        try {
            $request->merge([
                'per_page' => 100000,
                'sortBy' => 'id',
                'sortOrder' => 'desc',
            ]);

            $paginator = $this->offerService->list($request);
            $offers = $paginator->getCollection();

            $fileName = 'offers_'.now()->format('Y_m_d_H_i_s').'.csv';

            $columnMap = [
                'name' => ['label' => 'Name', 'value' => fn ($o) => $o->name],
                'listing' => ['label' => 'Listing', 'value' => fn ($o) => $o->property?->title],
                'buyer' => ['label' => 'Buyer', 'value' => fn ($o) => $o->buyer?->masterOrSelf()->name],
                'offeredAmount' => ['label' => 'Offered Amount', 'value' => fn ($o) => $o->offeredAmount],
                'targetDate' => ['label' => 'Target Date', 'value' => fn ($o) => $o->targetDate],
                'status' => ['label' => 'Status', 'value' => fn ($o) => $o->status ? (Constants::OFFER_STATUS[$o->status] ?? $o->status) : null],
                'offerDate' => ['label' => 'Offer Date', 'value' => fn ($o) => $o->offerDate],
                'offerExpirationDate' => ['label' => 'Offer Expiration Date', 'value' => fn ($o) => $o->offerExpirationDate],
                'sellerLandlord' => ['label' => 'Seller/Landlord', 'value' => fn ($o) => $o->sellerLandlord],
                'potentialBuyer' => ['label' => 'Potential Buyer', 'value' => fn ($o) => $o->buyer?->masterOrSelf()->name],
                'listingType' => ['label' => 'Listing Type', 'value' => fn ($o) => $o->listingType ? (Constants::PROPERTY_PURPOSE_TYPE[$o->listingType] ?? $o->listingType) : null],
                'listingPrice' => ['label' => 'Listing Price', 'value' => fn ($o) => $o->listingPrice],
                'leaseTerms' => ['label' => 'Lease Terms', 'value' => fn ($o) => $o->leaseTerms],
                'nationality' => ['label' => 'Buyer/Tenant Nationality', 'value' => fn ($o) => $o->buyer?->masterOrSelf()->nationality],
                'potentialCommission' => ['label' => 'Potential Commission', 'value' => fn ($o) => $o->potentialCommission],
                'deposits' => ['label' => 'Deposits', 'value' => fn ($o) => $o->deposits],
                'terms' => ['label' => 'Terms', 'value' => fn ($o) => $o->terms],
                'creator' => ['label' => 'Created By', 'value' => fn ($o) => $o->creator?->name],
                'created_at' => ['label' => 'Created At', 'value' => fn ($o) => convertToTimezone($o->created_at)],
            ];

            logUserActivity([
                'action' => 'exported_csv',
                'module' => Constants::MODULE_OFFER,
                'moduleId' => null,
                'description' => "Exported {$offers->count()} offer(s) to CSV: {$fileName}",
            ]);

            if ($actor = user()) {
                NotifyDataExportJob::dispatch($actor->id, Constants::MODULE_OFFER, $offers->count(), now()->toISOString());
            }

            return exportCsvStream($request, $offers, $columnMap, $fileName);
        } catch (\Exception $e) {
            return $this->exceptionResponse('OfferController : exportOfferCsv', $e);
        }
    }

    public function selectOffers(Request $request)
    {
        try {
            $assignedTo = $request->query('assignedTo');

            $offers = Offer::with('property:id,title')
                ->select('id', 'name', 'referenceNo', 'listingId', 'assignedAgentId')
                ->when($assignedTo, fn ($q) => $q->where('assignedAgentId', $assignedTo))
                ->orderBy('id', 'desc')
                ->limit(50)
                ->get()
                ->map(function ($offer) {
                    return [
                        'id' => $offer->id,
                        'name' => $offer->name,
                        'referenceNo' => $offer->referenceNo,
                        'property' => $offer->property ? [
                            'id' => $offer->property->id,
                            'title' => $offer->property->title,
                        ] : null,
                    ];
                });

            return $this->success($offers, 'Offers retrieved successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('OfferController : selectOffers', $e);
        }
    }
}
