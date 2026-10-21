<?php

namespace App\Jobs;

use App\Constants\Constants;
use App\Models\Closing;
use App\Models\Enquiry;
use App\Models\Role;
use App\Models\User;
use App\Services\MailService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyEnquiryLostByColleagueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(public int $closingId) {}

    public function handle(NotificationService $notificationService, MailService $mailService): void
    {
        $closing = Closing::with(['buyer:id,name', 'assignedAgent:id,name', 'property:id,referenceNumber,title'])
            ->find($this->closingId);

        if (! $closing || $closing->status !== Constants::CLOSE_STATUS_CLOSED || ! $closing->buyerId) {
            return;
        }

        // Same client's OPEN enquiries owned by OTHER agents (the colleague missed the deal).
        $siblings = Enquiry::with(['agent:id,name,email', 'contact:id,name', 'tasks:id,moduleId,module,updated_at'])
            ->where('contactId', $closing->buyerId)
            ->whereIn('status', Constants::ENQUIRY_ACTIVE_STATUSES)
            ->when($closing->listingType, fn ($q) => $q->where('listingType', $closing->listingType))
            ->whereNotNull('agentId')
            ->where('agentId', '!=', $closing->assignedAgentId)
            ->get();

        if ($siblings->isEmpty()) {
            return;
        }

        $closerName = $closing->assignedAgent?->name ?? 'a colleague';
        $propertyRef = $closing->property?->referenceNumber ?? $closing->referenceNo ?? (string) $closing->id;
        $closingDate = $closing->closingDate ? Carbon::parse($closing->closingDate) : Carbon::now();
        $closingDateLabel = $closingDate->format('F d, Y');
        $typeLabel = $closing->listingType ? ucfirst($closing->listingType) : 'N/A';

        // Managers are notified alongside each original agent.
        $managerRoleIds = Role::where('name', Constants::MANAGER)->pluck('id');
        $managers = User::whereIn('roleId', $managerRoleIds)
            ->where('status', Constants::USER_STATUS_ACTIVE)
            ->get(['id', 'name', 'email']);

        $frontendUrl = rtrim(env('FRONTEND_URL', config('app.url')), '/');

        foreach ($siblings as $enquiry) {
            // Capture last activity BEFORE moving to Lost (the update would reset it).
            $lastActivity = $enquiry->lastActivityAt ? Carbon::parse($enquiry->lastActivityAt) : null;
            $lastActivityLabel = $lastActivity ? $lastActivity->format('F d, Y H:i') : 'N/A';
            $timeGap = $lastActivity ? $lastActivity->diffForHumans($closingDate, true) : 'N/A';

            $oldStatus = $enquiry->status;
            $clientName = $enquiry->contact?->masterOrSelf()->name ?? "Contact #{$closing->buyerId}";
            $enquiryRef = $enquiry->referenceNo ?? (string) $enquiry->id;

            // Move the enquiry to Lost with a reason, logging the change.
            logEnquiryHistory([
                'enquiryId' => $enquiry->id,
                'field' => 'status',
                'originalValue' => json_encode(['message' => $oldStatus]),
                'newValue' => json_encode(['message' => Constants::ENQUIRY_LOST]),
                'comments' => json_encode(['message' => "Auto-lost: closed by {$closerName} ({$propertyRef})"]),
                'userId' => $closing->assignedAgentId,
            ]);
            $enquiry->update([
                'status' => Constants::ENQUIRY_LOST,
                'lostReason' => "Closed by colleague {$closerName} ({$propertyRef})",
            ]);

            $enquiryLink = $frontendUrl.'/dashboard/enquiries/'.$enquiry->id.'/view';
            $originalAgentName = $enquiry->agent?->name ?? 'Agent';

            $extra = [
                'enquiryId' => $enquiry->id,
                'enquiryReference' => $enquiryRef,
                'clientName' => $clientName,
                'enquiryType' => $typeLabel,
                'closedByAgent' => $closerName,
                'propertyReference' => $propertyRef,
                'closingDate' => $closing->closingDate,
                'originalAgent' => $originalAgentName,
                'lastActivityDate' => $lastActivity?->toISOString(),
                'timeGap' => $timeGap,
                'enquiryLink' => $enquiryLink,
            ];

            // Recipients: the original agent + all active managers.
            $recipients = collect();
            if ($enquiry->agent) {
                $recipients->push($enquiry->agent);
            }
            foreach ($managers as $m) {
                $recipients->push($m);
            }
            $recipients = $recipients->unique('id');

            foreach ($recipients as $recipient) {
                $isOriginal = $recipient->id === $enquiry->agentId;

                $notificationService->notifyUser(
                    userId: $recipient->id,
                    actorId: $closing->assignedAgentId ?? $recipient->id,
                    type: Constants::NOTIFICATION_TYPES['enquiry_lost_colleague'],
                    title: "Missed opportunity: {$enquiryRef}",
                    message: $isOriginal
                        ? "Missed Opportunity: Your enquiry {$enquiryRef} has been closed by {$closerName}. Please review activity and follow-up timeline."
                        : "{$originalAgentName}'s enquiry {$enquiryRef} ({$clientName}) was lost — {$closerName} closed the deal ({$propertyRef}).",
                    module: Constants::MODULE_ENQUIRY,
                    moduleId: $enquiry->id,
                    extraData: $extra,
                );

                if (empty($recipient->email)) {
                    continue;
                }

                try {
                    $mailService->sendEmail(
                        Constants::EMAIL_SLUGS['enquiry']['lost_colleague'],
                        $recipient->email,
                        [
                            'recipient_name' => ucwords($recipient->name),
                            'enquiry_reference' => $enquiryRef,
                            'client_name' => $clientName,
                            'enquiry_type' => $typeLabel,
                            'closed_by_agent' => ucwords($closerName),
                            'property_reference' => $propertyRef,
                            'closing_date' => $closingDateLabel,
                            'original_agent' => ucwords($originalAgentName),
                            'last_activity_date' => $lastActivityLabel,
                            'time_gap' => $timeGap,
                            'enquiry_link' => $enquiryLink,
                            'company_name' => getCompanyName(),
                        ]
                    );
                } catch (\Exception $e) {
                    Log::error('NotifyEnquiryLostByColleagueJob : sendEmail : '.$e->getMessage());
                }
            }
        }
    }
}
