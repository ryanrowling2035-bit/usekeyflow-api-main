<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Http\Resources\BoostResources\BoostResource;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use App\Services\BoostService;
use App\Services\MailService;
use App\Services\NotificationService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BoostController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected BoostService $boostService,
        protected NotificationService $notificationService,
        protected MailService $mailService,
    ) {}

    public function addBoost(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'isPropertyFinder' => 'required|boolean|accepted_if:isBayut,0',
            'isBayut' => 'required|boolean|accepted_if:isPropertyFinder,0',
            'propertyId' => 'required|integer|exists:properties,id',
            'duration' => 'required|in:'.implode(',', array_keys(Constants::BOOST_DURATION)),
            'boostType' => 'required|in:'.implode(',', array_keys(Constants::BOOST_TYPE)),
            'credits' => 'sometimes|required|numeric|min:0',
            'status' => 'required|in:'.implode(',', array_keys(Constants::BOOST_STATUS)),
            'notes' => 'nullable',
            'rejectionReason' => 'nullable|required_if:status,rejected|string',
            'startDate' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $data = $validator->validate();
            $data['createdBy'] = user()->id;

            // "Boosted by" — whoever approves or pushes the boost live.
            if (in_array($data['status'] ?? null, ['approved', 'goneLive'], true)) {
                $data['approvedBy'] = user()->id;
            }

            // Auto-calculate dates when status is goneLive
            if ($data['status'] === 'goneLive' && ! empty($data['duration'])) {
                $data['startDate'] = $data['startDate'] ?? Carbon::today()->toDateString();
                $data['endDate'] = Carbon::parse($data['startDate'])->addDays((int) $data['duration'])->toDateString();
            }

            $boost = $this->boostService->create($data);

            // Notify after response to avoid blocking
            dispatch(function () use ($boost) {
                if (isAgent()) {
                    $this->notifyAdminsManagersOnBoostRequest($boost);
                } else {
                    $this->notifyAgentOnBoostStatus($boost);
                }
            })->afterResponse();

            $params = [
                'action' => 'created',
                'module' => Constants::MODULE_BOOST,
                'moduleId' => $boost->id,
                'description' => "Created boost: #{$boost->id}",
            ];
            logUserActivity($params);

            logPropertyHistory([
                'propertyId' => $boost->propertyId,
                'field' => 'boost_created',
                'newValue' => json_encode(['message' => "Boost created: #{$boost->id}"]),
            ]);

            return $this->success(new BoostResource($boost), 'Boost created successfully.');
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Integrity constraint') || str_contains($e->getMessage(), 'SQLSTATE')) {
                return $this->error($e->getMessage(), 422);
            }

            return $this->exceptionResponse('BoostController : addBoost', $e);
        }
    }

    public function getBoosts(Request $request)
    {
        try {
            $boosts = $this->boostService->getAll($request);

            return $this->success(BoostResource::collection($boosts), 'Boost list retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('BoostController : getBoosts', $e);
        }
    }

    public function getBoostById($id)
    {
        try {
            $boost = $this->boostService->getById($id);
            if (! $boost) {
                return $this->error('The requested boost was not found.');
            }

            return $this->success(new BoostResource($boost), 'Boost details retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('BoostController : getBoostById', $e);
        }
    }

    public function updateBoost(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'isPropertyFinder' => 'sometimes|required|boolean|accepted_if:isBayut,0',
            'isBayut' => 'sometimes|required|boolean|accepted_if:isPropertyFinder,0',
            'propertyId' => 'sometimes|required|integer|exists:properties,id',
            'duration' => 'sometimes|required|in:'.implode(',', array_keys(Constants::BOOST_DURATION)),
            'boostType' => 'sometimes|required|in:'.implode(',', array_keys(Constants::BOOST_TYPE)),
            'credits' => 'sometimes|required|numeric|min:0',
            'status' => 'sometimes|required|in:'.implode(',', array_keys(Constants::BOOST_STATUS)),
            'notes' => 'nullable',
            'rejectionReason' => 'nullable|required_if:status,rejected|string',
            'startDate' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $data = $validator->validate();

            $oldBoost = $this->boostService->getById($id);
            if (! $oldBoost) {
                return $this->error('The requested boost was not found.');
            }

            $oldStatus = $oldBoost->status;

            // Auto-calculate dates when status changes to goneLive
            if (isset($data['status']) && $data['status'] === 'goneLive') {
                $duration = $data['duration'] ?? $oldBoost->duration;
                $data['startDate'] = $data['startDate'] ?? Carbon::today()->toDateString();
                $data['endDate'] = Carbon::parse($data['startDate'])->addDays((int) $duration)->toDateString();
            }

            // "Boosted by" — record who approved or pushed the boost live.
            if (isset($data['status']) && in_array($data['status'], ['approved', 'goneLive'], true)) {
                $data['approvedBy'] = user()->id;
            }

            $boost = $this->boostService->update($id, $data);
            if (! $boost) {
                return $this->error('The requested boost was not found.');
            }

            // Notify agent when status changes (after response)
            if (isset($data['status']) && $data['status'] !== $oldStatus) {
                dispatch(function () use ($boost) {
                    $this->notifyAgentOnBoostStatus($boost);
                })->afterResponse();
            }

            $params = [
                'action' => 'updated',
                'module' => Constants::MODULE_BOOST,
                'moduleId' => $boost->id,
                'description' => "Updated boost: #{$boost->id}",
            ];
            logUserActivity($params);

            logPropertyHistory([
                'propertyId' => $boost->propertyId,
                'field' => 'boost_updated',
                'newValue' => json_encode(['message' => "Boost updated: #{$boost->id}"]),
            ]);

            return $this->success(new BoostResource($boost), 'Boost updated successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('BoostController : updateBoost', $e);
        }
    }

    public function deleteBoost($id)
    {
        try {
            $boost = $this->boostService->delete($id);
            if (! $boost) {
                return $this->error('The requested boost was not found.');
            }

            $params = [
                'action' => 'deleted',
                'module' => Constants::MODULE_BOOST,
                'moduleId' => $id,
                'description' => "Deleted boost: #{$id}",
            ];
            logUserActivity($params);

            if ($boost->propertyId) {
                logPropertyHistory([
                    'propertyId' => $boost->propertyId,
                    'field' => 'boost_deleted',
                    'originalValue' => json_encode(['message' => "Boost: #{$id}"]),
                    'newValue' => json_encode(['message' => 'Boost deleted']),
                ]);
            }

            return $this->success([], 'Boost deleted successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('BoostController : deleteBoost', $e);
        }
    }

    public function getBoostByPropertyId($id)
    {
        try {
            $boosts = $this->boostService->getBoostByPropertyId($id);

            if ($boosts->isEmpty()) {
                return $this->error('No boost found for this property.', 200);
            }

            return $this->success(
                BoostResource::collection($boosts),
                'Boost list retrieved successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('BoostController : getBoostByPropertyId', $e);
        }
    }

    private function notifyAdminsManagersOnBoostRequest($boost): void
    {
        $property = Property::find($boost->propertyId);
        if (! $property) {
            return;
        }

        $actor = user();
        $agentRoleId = Role::where('name', Constants::AGENT)->value('id');

        $recipients = User::where('status', 'active')
            ->where('roleId', '!=', $agentRoleId)
            ->where('id', '!=', $actor->id)
            ->get(['id', 'name', 'email']);

        if ($recipients->isEmpty()) {
            return;
        }

        $propertyTitle = $property->title ?? "Property #{$boost->propertyId}";
        $portalNames = collect([
            $boost->isPropertyFinder ? 'Property Finder' : null,
            $boost->isBayut ? 'Bayut' : null,
        ])->filter()->implode(' & ');

        $boostTypeLabel = Constants::BOOST_TYPE[$boost->boostType] ?? $boost->boostType;
        $propertyLink = env('FRONTEND_URL', config('app.url')).'/dashboard/property/'.$property->id.'/view?tab=boost';

        $title = "New boost request for {$propertyTitle}";
        $message = "{$actor->name} has requested a boost for \"{$propertyTitle}\" on {$portalNames}.";

        foreach ($recipients as $recipient) {
            $this->notificationService->notifyUser(
                userId: $recipient->id,
                actorId: $actor->id,
                type: Constants::NOTIFICATION_TYPES['boost_requested'],
                title: $title,
                message: $message,
                module: Constants::MODULE_BOOST,
                moduleId: $boost->id,
                extraData: [
                    'propertyId' => $boost->propertyId,
                    'propertyTitle' => $propertyTitle,
                    'portal' => $portalNames,
                    'duration' => $boost->duration,
                    'boostType' => $boostTypeLabel,
                ]
            );

            if ($recipient->email) {
                try {
                    $this->mailService->sendEmail(
                        Constants::EMAIL_SLUGS['boost']['boost_request_admin'],
                        $recipient->email,
                        [
                            'recipient_name' => ucwords($recipient->name),
                            'property_title' => $propertyTitle,
                            'property_id' => $property->referenceNumber ?? $property->id,
                            'portal' => $portalNames,
                            'boost_type' => $boostTypeLabel,
                            'duration' => $boost->duration,
                            'credits' => $boost->credits,
                            'requested_by' => ucwords($actor->name)." ($actor->email)",
                            'requested_date' => Carbon::parse(convertToTimezone(now()))->format('F d, Y H:i'),
                            'property_link' => $propertyLink,
                            'company_name' => getCompanyName(),
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::error("Failed to send boost request email to {$recipient->email}: ".$e->getMessage());
                }
            }
        }
    }

    private function notifyAgentOnBoostStatus($boost): void
    {
        $property = Property::find($boost->propertyId);
        $agentId = $property?->agentId;
        $agent = $agentId ? User::find($agentId) : null;

        if (! $agent || $agentId === user()->id) {
            return;
        }

        $propertyTitle = $property->title ?? "Property #{$boost->propertyId}";
        $portalNames = collect([
            $boost->isPropertyFinder ? 'Property Finder' : null,
            $boost->isBayut ? 'Bayut' : null,
        ])->filter()->implode(' & ');

        $statusLabelMap = [
            'approved' => 'Approved',
            'goneLive' => 'Gone Live',
            'expired' => 'Expired',
            'rejected' => 'Rejected',
        ];

        $notificationMap = [
            'approved' => [
                'type' => Constants::NOTIFICATION_TYPES['boost_approved'],
                'title' => "Boost approved for {$propertyTitle}",
                'message' => "Your boost request for \"{$propertyTitle}\" on {$portalNames} has been approved.",
            ],
            'goneLive' => [
                'type' => Constants::NOTIFICATION_TYPES['boost_live'],
                'title' => "Boost is now live for {$propertyTitle}",
                'message' => "Boost for \"{$propertyTitle}\" on {$portalNames} is now live for {$boost->duration} days.",
            ],
            'expired' => [
                'type' => Constants::NOTIFICATION_TYPES['boost_expired'],
                'title' => "Boost expired for {$propertyTitle}",
                'message' => "Boost for \"{$propertyTitle}\" on {$portalNames} has expired.",
            ],
            'rejected' => [
                'type' => Constants::NOTIFICATION_TYPES['boost_rejected'],
                'title' => "Boost rejected for {$propertyTitle}",
                'message' => "Your boost request for \"{$propertyTitle}\" on {$portalNames} was rejected. Reason: {$boost->rejectionReason}",
            ],
        ];

        $notification = $notificationMap[$boost->status] ?? null;

        if (! $notification) {
            return;
        }

        $this->notificationService->notifyUser(
            userId: $agentId,
            actorId: user()->id,
            type: $notification['type'],
            title: $notification['title'],
            message: $notification['message'],
            module: Constants::MODULE_BOOST,
            moduleId: $boost->id,
            extraData: [
                'propertyId' => $boost->propertyId,
                'propertyTitle' => $propertyTitle,
                'portal' => $portalNames,
                'duration' => $boost->duration,
            ]
        );

        if ($agent->email) {
            $propertyLink = env('FRONTEND_URL', config('app.url')).'/dashboard/property/'.$property->id.'/view';

            try {
                $this->mailService->sendEmail(
                    Constants::EMAIL_SLUGS['boost']['boost_status_agent'],
                    $agent->email,
                    [
                        'agent_name' => ucwords($agent->name),
                        'property_title' => $propertyTitle,
                        'property_id' => $property->referenceNumber ?? $property->id,
                        'portal' => $portalNames,
                        'duration' => $boost->duration,
                        'status' => $statusLabelMap[$boost->status] ?? $boost->status,
                        'status_message' => $notification['message'],
                        'updated_by' => ucwords(user()->name),
                        'updated_date' => now()->setTimezone(appTimezone())->format('F d, Y H:i'),
                        'property_link' => $propertyLink,
                        'company_name' => getCompanyName(),
                    ]
                );
            } catch (\Throwable $e) {
                Log::error("Failed to send boost status email to {$agent->email}: ".$e->getMessage());
            }
        }
    }
}
