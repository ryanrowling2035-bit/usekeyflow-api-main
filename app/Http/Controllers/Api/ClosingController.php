<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClosingResources\ClosingResource;
use App\Jobs\NotifyDataExportJob;
use App\Mail\DynamicMail;
use App\Models\Closing;
use App\Models\Contact;
use App\Services\ClosingService;
use App\Services\ContactNoteService;
use App\Services\ContactService;
use App\Services\InternalNoteService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class ClosingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ClosingService $closingService,
        private ContactService $contactService,
        private InternalNoteService $internalNoteService,
        private ContactNoteService $contactNoteService,
    ) {}

    public function index(Request $request)
    {
        try {
            $closings = $this->closingService->list($request);

            return $this->success(ClosingResource::collection($closings), 'Closings retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ClosingController : index', $e);
        }
    }

    public function listByListingId(Request $request, $listingId = null)
    {
        try {
            $closings = $this->closingService->listByListingId($request, $listingId);

            return $this->success(ClosingResource::collection($closings), 'Closings retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ClosingController : listByListingId', $e);
        }
    }

    public function addClose(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string',
            'listingId' => 'required',
            'enquiryId' => 'nullable|integer',
            'offerId' => 'nullable',
            'buyerId' => 'required',
            'status' => 'nullable|in:'.implode(',', array_keys(Constants::CLOSE_STATUS)),
            'listingType' => 'nullable|in:'.implode(',', array_keys(Constants::CLOSE_LISTING_TYPE)),
            'targetCloseDate' => 'nullable|date',
            'closingDate' => 'nullable|date',
            'assignedAgentId' => 'nullable',
            'clientNationality' => 'nullable|string',
            'nationality' => 'nullable|string|max:255',
            'residencyStatus' => 'nullable|string|max:50',
        ]);
        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }
        $data = $validator->validated();
        $data['createdBy'] = auth()->id();

        try {
            $this->contactService->syncBuyerFields($data['buyerId'] ?? null, $data);
            unset($data['nationality'], $data['residencyStatus']);

            $closing = $this->closingService->create($data);

            $params = [
                'action' => 'created',
                'module' => Constants::MODULE_CLOSING,
                'moduleId' => $closing->id,
                'description' => "Created closing: #{$closing->id}",
            ];
            logUserActivity($params);

            if ($closing->enquiryId) {
                logEnquiryHistory([
                    'enquiryId' => $closing->enquiryId,
                    'field' => 'closing_created',
                    'originalValue' => null,
                    'newValue' => json_encode(['message' => "Closing created: #{$closing->id}"]),
                ]);
            }

            logPropertyHistory([
                'propertyId' => $closing->listingId,
                'field' => 'closing_created',
                'newValue' => json_encode(['message' => "Closing created: #{$closing->id}"]),
            ]);

            return $this->success(new ClosingResource($closing), 'Closing created successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ClosingController : addClose', $e);
        }
    }

    public function show(string $id)
    {
        try {
            $closing = $this->closingService->getById($id);

            if (! $closing) {
                return $this->error('Closing not found.', 422);
            }

            return $this->success(new ClosingResource($closing), 'Closing retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ClosingController : show', $e);
        }
    }

    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|nullable|string',
            'listingId' => 'sometimes|required',
            'offerId' => 'sometimes|nullable',
            'buyerId' => 'sometimes|required',
            // DOB is only mandatory when the closing is being moved to CLOSED.
            // While it stays OPEN (e.g. just fixing the commission) DOB is optional,
            // since the paperwork may not be in hand yet.
            'dob' => 'required_if:status,'.Constants::CLOSE_STATUS_CLOSED.'|nullable|date',
            'status' => 'sometimes|nullable|in:'.implode(',', array_keys(Constants::CLOSE_STATUS)),
            'listingType' => 'sometimes|nullable|in:'.implode(',', array_keys(Constants::CLOSE_LISTING_TYPE)),
            'targetCloseDate' => 'sometimes|nullable|date',
            'closingDate' => 'sometimes|nullable|date',
            'assignedAgentId' => 'sometimes|nullable',
            'clientLeadSource' => 'sometimes|nullable|string',
            'clientNationality' => 'sometimes|nullable|string',
            'nationality' => 'sometimes|nullable|string|max:255',
            'residencyStatus' => 'sometimes|nullable|string|max:50',
            'sellerLandlord' => 'sometimes|nullable|integer',
            'saleRentalPrice' => 'sometimes|nullable|numeric',
            'buyerCommission' => 'sometimes|nullable|numeric',
            'sellerCommission' => 'sometimes|nullable|numeric',
            'totalCommission' => 'sometimes|nullable|numeric',
            'representing' => 'sometimes|nullable|string',
            'closingProbability' => 'sometimes|nullable|numeric',
            'agentCommission' => 'sometimes|nullable|numeric',
            'companyCommission' => 'sometimes|nullable|numeric',
            'sellerLandlordSideSplitAmount' => 'sometimes|nullable|numeric',
            'buyerTenantSideSplitAmount' => 'sometimes|nullable|numeric',
            'sellerAgentSplitPercentage' => 'sometimes|nullable|numeric',
            'companySellerSplitPercentage' => 'sometimes|nullable|numeric',
            'sellerAgentSplitAmount' => 'sometimes|nullable|numeric',
            'companySellerSplitAmount' => 'sometimes|nullable|numeric',
            'buyerAgentSplitPercentage' => 'sometimes|nullable|numeric',
            'companyBuyerSplitPercentage' => 'sometimes|nullable|numeric',
            'buyerAgentSplitAmount' => 'sometimes|nullable|numeric',
            'companyBuyerSplitAmount' => 'sometimes|nullable|numeric',
            'escrowTitleContact' => 'sometimes|nullable|string',
            'buyerTenantAgencyContact' => 'sometimes|nullable|string',
            'mortgageBrokerContact' => 'sometimes|nullable|string',
            'sellerLandlordSource' => 'sometimes|nullable|string',
            'closingUrlRecord' => 'sometimes|nullable|string',
            'owner' => 'sometimes|nullable|string',
            'buyerTenant' => 'sometimes|nullable|integer',
            'listingAgencyContact' => 'sometimes|nullable|string',
            'closingChecklist' => 'sometimes|nullable|array',
            'enquiryId' => 'sometimes|nullable|integer',
        ]);
        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }
        $data = $validator->validated();

        try {
            $buyerSyncData = array_intersect_key($data, array_flip(['dob', 'nationality', 'residencyStatus']));
            unset($data['dob'], $data['nationality'], $data['residencyStatus']);

            $closing = $this->closingService->update($id, $data);

            $this->contactService->syncBuyerFields($closing->buyerId ?? ($data['buyerId'] ?? null), $buyerSyncData);

            app()->terminating(function () use ($closing) {
                logUserActivity([
                    'action' => 'updated',
                    'module' => Constants::MODULE_CLOSING,
                    'moduleId' => $closing->id,
                    'description' => "Updated closing: #{$closing->id}",
                ]);

                if ($closing->enquiryId) {
                    logEnquiryHistory([
                        'enquiryId' => $closing->enquiryId,
                        'field' => 'closing_updated',
                        'originalValue' => null,
                        'newValue' => json_encode(['message' => "Closing updated: #{$closing->id}"]),
                    ]);
                }

                logPropertyHistory([
                    'propertyId' => $closing->listingId,
                    'field' => 'closing_updated',
                    'newValue' => json_encode(['message' => "Closing updated: #{$closing->id}"]),
                ]);
            });

            return $this->success(new ClosingResource($closing), 'Closing updated successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ClosingController : update', $e);
        }
    }

    public function destroy(string $id)
    {
        try {
            $closing = $this->closingService->getById($id);

            if (! $closing) {
                return $this->error('Closing not found.', 422);
            }

            $listingId = $closing->listingId ?? null;
            $closingEnquiryId = $closing->enquiryId ?? null;

            $this->closingService->delete($id);

            $params = [
                'action' => 'deleted',
                'module' => Constants::MODULE_CLOSING,
                'moduleId' => $id,
                'description' => "Deleted closing: #{$id}",
            ];
            logUserActivity($params);

            if ($closingEnquiryId) {
                logEnquiryHistory([
                    'enquiryId' => $closingEnquiryId,
                    'field' => 'closing_deleted',
                    'originalValue' => json_encode(['message' => "Closing: #{$id}"]),
                    'newValue' => json_encode(['message' => 'Closing deleted']),
                ]);
            }

            if ($listingId) {
                logPropertyHistory([
                    'propertyId' => $listingId,
                    'field' => 'closing_deleted',
                    'originalValue' => json_encode(['message' => "Closing: #{$id}"]),
                    'newValue' => json_encode(['message' => 'Closing deleted']),
                ]);
            }

            return $this->success(null, 'Closing deleted successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ClosingController : destroy', $e);
        }
    }

    public function exportClosingCsv(Request $request)
    {
        if (isAgent()) {
            if ($actor = user()) {
                NotifyDataExportJob::dispatch($actor->id, Constants::MODULE_CLOSING, 0, now()->toISOString(), 'blocked');
            }

            return $this->error('Agents are not allowed to export data.', 403);
        }

        try {
            $request->merge([
                'per_page' => 100000,
                'sortBy' => 'id',
                'sortOrder' => 'desc',
            ]);

            $paginator = $this->closingService->list($request);
            $closings = $paginator->getCollection()->load('sellerLandlordContact', 'property.creator');

            $fileName = 'closings_'.now()->format('Y_m_d_H_i_s').'.csv';

            $columnMap = [
                'name' => ['label' => 'Closing Name', 'value' => fn ($c) => $c->name],
                'listing' => ['label' => 'Listing', 'value' => fn ($c) => $c->property?->title],
                'listingReference' => ['label' => 'Listing Reference', 'value' => fn ($c) => $c->property?->referenceNumber],
                'offer' => ['label' => 'Offer', 'value' => fn ($c) => $c->offer?->name],
                'buyer' => ['label' => 'Buyer/Tenant', 'value' => fn ($c) => $c->buyer?->masterOrSelf()->name],
                'listingType' => ['label' => 'Listing Type', 'value' => fn ($c) => $c->listingType ? (Constants::CLOSE_LISTING_TYPE[$c->listingType] ?? $c->listingType) : null],
                'assignedAgent' => ['label' => 'Agent Name', 'value' => fn ($c) => $c->assignedAgent?->name],
                'clientLeadSource' => ['label' => 'Client Lead Source', 'value' => fn ($c) => $c->clientLeadSource],
                'clientNationality' => ['label' => 'Client Nationality', 'value' => fn ($c) => $c->clientNationality],
                'nationality' => ['label' => 'Nationality', 'value' => fn ($c) => $c->buyer?->masterOrSelf()->nationality],
                'sellerLandlord' => ['label' => 'Seller/Landlord', 'value' => fn ($c) => $c->sellerLandlordContact?->name],
                'sellerLandlordData' => ['label' => 'Seller/Landlord Name', 'value' => fn ($c) => $c->sellerLandlordContact?->name],
                'sellerLandlordType' => ['label' => 'Seller/Landlord Type', 'value' => fn ($c) => $c->sellerLandlordContact
                    ? collect(Contact::normalizeContactTypes($c->sellerLandlordContact->contactType))
                        ->map(fn ($t) => Constants::CONTACT_TYPE[$t] ?? $t)
                        ->implode(', ')
                    : null],
                'ownerFirstName' => ['label' => 'Owner First Name', 'value' => fn ($c) => $c->property?->creator?->name],
                'saleRentalPrice' => ['label' => 'Sale/Rental Price', 'value' => fn ($c) => $c->saleRentalPrice],
                'buyerCommission' => ['label' => 'Buyer Commission', 'value' => fn ($c) => $c->buyerCommission],
                'sellerCommission' => ['label' => 'Seller Commission', 'value' => fn ($c) => $c->sellerCommission],
                'totalCommission' => ['label' => 'Total Commission', 'value' => fn ($c) => $c->totalCommission],
                'targetCloseDate' => ['label' => 'Target Close Date', 'value' => fn ($c) => $c->targetCloseDate],
                'closingDate' => ['label' => 'Closing Date', 'value' => fn ($c) => $c->closingDate],
                'status' => ['label' => 'Status', 'value' => fn ($c) => $c->status ? (Constants::CLOSE_STATUS[$c->status] ?? $c->status) : null],
                // The checklist is stored as {item => bool}, so the ticked items are
                // the KEYS whose value is true — mapping over the array directly
                // walked the booleans instead and emitted ", , , , ," for every row.
                'closingChecklist' => ['label' => 'Closing Checklist', 'value' => function ($c) {
                    if (! is_array($c->closingChecklist)) {
                        return $c->closingChecklist;
                    }

                    return implode(', ', array_map(
                        fn ($key) => Constants::CLOSING_CHECKLIST[$key] ?? $key,
                        array_keys(array_filter($c->closingChecklist))
                    ));
                }],
                'creator' => ['label' => 'Created By', 'value' => fn ($c) => $c->creator?->name],
                'created_at' => ['label' => 'Created At', 'value' => fn ($c) => convertToTimezone($c->created_at)],
            ];

            logUserActivity([
                'action' => 'exported_csv',
                'module' => Constants::MODULE_CLOSING,
                'moduleId' => null,
                'description' => "Exported {$closings->count()} closing(s) to CSV: {$fileName}",
            ]);

            if ($actor = user()) {
                NotifyDataExportJob::dispatch($actor->id, Constants::MODULE_CLOSING, $closings->count(), now()->toISOString());
            }

            return exportCsvStream($request, $closings, $columnMap, $fileName);

        } catch (\Exception $e) {
            return $this->exceptionResponse('ClosingController : exportClosingCsv', $e);
        }
    }

    public function pipelineSummary(Request $request)
    {
        try {
            $summary = $this->closingService->pipelineSummary($request);

            return $this->success($summary, 'Sales pipeline summary retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ClosingController : pipelineSummary', $e);
        }
    }

    public function selectClosings(Request $request)
    {
        try {
            $assignedTo = $request->query('assignedTo');

            $closings = Closing::with(['property:id,title', 'offer:id,name'])
                ->select('id', 'name', 'referenceNo', 'listingId', 'offerId', 'assignedAgentId')
                ->when($assignedTo, fn ($q) => $q->where('assignedAgentId', $assignedTo))
                ->orderBy('id', 'desc')
                ->limit(50)
                ->get()
                ->map(function ($closing) {
                    return [
                        'id' => $closing->id,
                        'name' => $closing->name,
                        'referenceNo' => $closing->referenceNo,
                        'property' => $closing->property ? [
                            'id' => $closing->property->id,
                            'title' => $closing->property->title,
                        ] : null,
                        'offer' => $closing->offer ? [
                            'id' => $closing->offer->id,
                            'name' => $closing->offer->name,
                        ] : null,
                    ];
                });

            return $this->success($closings, 'Closings retrieved successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ClosingController : selectClosings', $e);
        }
    }

    /**
     * Compose and send an email from a closing (mirrors the property/contact
     * flow). The "To" defaults to the closing's buyer on the frontend. The email
     * is logged both as a closing activity (internal note, type=email) so it shows
     * on the Activities card, and in the user activity log.
     */
    public function composeEmail(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'to' => 'required|email',
            'cc' => 'nullable|array',
            'cc.*' => 'email',
            'subject' => 'required|string',
            'body' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        $closing = Closing::find($id);
        if (! $closing) {
            return $this->error('The requested closing was not found.', 422);
        }

        try {
            $html = view('EmailTemplate.templateLayout', [
                'title' => $request->subject,
                'body' => $request->body,
            ])->render();

            $agentUser = user();

            $mail = Mail::to($request->to);
            if (! empty($request->cc)) {
                $mail->cc($request->cc);
            }

            $mail->send(new DynamicMail(
                $request->subject,
                $html,
                [],
                $agentUser?->email,
                $agentUser?->name
            ));

            $logContent = json_encode([
                'to' => $request->to,
                'cc' => $request->cc ?? [],
                'subject' => $request->subject,
                'body' => $request->body,
            ]);

            // Log as a closing activity so it appears on the Activities card.
            $this->internalNoteService->create([
                'module' => Constants::MODULE_CLOSING,
                'moduleId' => $closing->id,
                'type' => 'email',
                'parent' => 0,
                'createdBy' => user()->id,
                'content' => $logContent,
            ]);

            // Also log it against the closing's buyer so the email shows in that
            // contact's Email history (Manage Contacts › Emails tab).
            if ($closing->buyerId) {
                $this->contactNoteService->create([
                    'contactId' => $closing->buyerId,
                    'type' => 'email',
                    'parent' => 0,
                    'createdBy' => user()->id,
                    'content' => $logContent,
                ]);
            }

            logUserActivity([
                'action' => 'compose_email',
                'module' => Constants::MODULE_CLOSING,
                'moduleId' => $closing->id,
                'description' => "Composed email for closing #{$closing->id} to {$request->to}".(! empty($request->cc) ? ' cc: '.implode(', ', $request->cc) : ''),
                'extraData' => [
                    'to' => $request->to,
                    'cc' => ! empty($request->cc) ? implode(', ', $request->cc) : null,
                    'subject' => $request->subject,
                    'emailContent' => $request->body,
                ],
            ]);

            return $this->success(null, 'Email sent successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ClosingController : composeEmail', $e);
        }
    }
}
