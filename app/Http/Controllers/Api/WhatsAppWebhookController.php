<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsAppTemplateJob;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\Enquiry;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * Button texts (from the approved feedback templates) that count as customer feedback.
     */
    private const FEEDBACK_BUTTONS = [
        'excellent service',
        'average experience',
        'needs improvement',
        'very satisfied',
        'satisfied but can improve',
        'not satisfied',
    ];

    /**
     * Keywords in a free-text message that signal an issue/complaint to escalate.
     */
    private const ESCALATION_KEYWORDS = [
        'complaint', 'complain', 'issue', 'problem', 'not happy', 'unhappy',
        'disappointed', 'poor service', 'bad service', 'worst', 'refund', 'escalate',
    ];

    /**
     * Keywords in a free-text message that signal an owner-services interest
     * (Property Management, Vacation Homes, or related) => create an owner enquiry.
     */
    private const OWNER_INTEREST_KEYWORDS = [
        'property management', 'manage my property', 'manage my home', 'managed property',
        'vacation home', 'vacation homes', 'holiday home', 'holiday homes',
        'short term rental', 'short-term rental', 'list my property', 'list my villa',
        'list my apartment', 'rent out my property', 'lease my property',
    ];

    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * Inbound webhook for customer WhatsApp replies (360degreeapps).
     * Always returns HTTP 200 so the gateway does not retry endlessly.
     */
    public function handle(Request $request): JsonResponse
    {
        // Log the raw payload so the exact 360degreeapps inbound shape can be confirmed.
        Log::info('WhatsApp inbound webhook', ['body' => $request->all(), 'query' => $request->query()]);

        // Optional shared-secret verification.
        $secret = config('services.whatsapp_360.webhook_secret');
        if ($secret) {
            $provided = $request->header('X-Webhook-Secret') ?? $request->query('token');
            if (! is_string($provided) || ! hash_equals($secret, $provided)) {
                return response()->json(['status' => false, 'message' => 'Unauthorized'], 401);
            }
        }

        $phone = $this->extractPhone($request);
        $buttonText = $this->extractButtonText($request);
        $text = $this->extractText($request);

        if (! $phone) {
            Log::warning('WhatsApp webhook: no sender phone found');

            return response()->json(['status' => true]);
        }

        $enquiry = $this->findLatestEnquiryByPhone($phone);
        if (! $enquiry) {
            Log::warning('WhatsApp webhook: no enquiry for phone', ['phone' => $phone]);

            return response()->json(['status' => true]);
        }

        $this->routeAction($enquiry, $buttonText, $text);

        return response()->json(['status' => true]);
    }

    /**
     * Map an inbound customer response to a system action.
     * New button actions are added as cases here.
     */
    protected function routeAction(Enquiry $enquiry, ?string $buttonText, ?string $text): void
    {
        $button = $buttonText ? strtolower(trim($buttonText)) : null;

        // Customer requests assistance ("I need help" / "I need some help") => Hot Lead alert.
        if ($button && (str_contains($button, 'need help') || str_contains($button, 'need some help'))) {
            $this->hotLeadNotification($enquiry, $buttonText);

            return;
        }

        // Customer says no one contacted them yet => notify the assigned agent.
        if ($button && str_contains($button, 'no one contacted')) {
            $this->notContactedNotification($enquiry, $buttonText);

            return;
        }

        // Customer is still searching => reactivate the lead and notify the agent.
        if ($button && str_contains($button, 'still searching')) {
            $this->stillSearchingNotification($enquiry, $buttonText);

            return;
        }

        // Customer found a property (elsewhere) => mark the lead as Lost.
        if ($button && str_contains($button, 'found a property')) {
            $this->foundPropertyLost($enquiry, $buttonText);

            return;
        }

        // Customer is not ready right now => keep the lead in the nurture cycle.
        if ($button && str_contains($button, 'not right now')) {
            $this->notRightNowNurture($enquiry, $buttonText);

            return;
        }

        // Customer submits feedback (reply to a feedback template).
        if ($button && in_array($button, self::FEEDBACK_BUTTONS, true)) {
            $this->feedbackNotification($enquiry, $buttonText);

            return;
        }

        // Customer reports an issue via a button ("Report an issue" / "Reports an issue").
        if ($button && str_contains($button, 'issue')) {
            $this->customerCareEscalation($enquiry, $buttonText);

            return;
        }

        // Free-text message handling.
        if (! $button && is_string($text) && trim($text) !== '') {
            // Property Management / Vacation Homes interest => create an owner enquiry.
            if ($this->looksLikeOwnerInterest($text)) {
                $this->ownerEnquiryCreation($enquiry, $text);

                return;
            }

            // Issue/complaint keywords => escalate to Customer Care.
            if ($this->looksLikeComplaint($text)) {
                $this->customerCareEscalation($enquiry, $text);

                return;
            }

            // Otherwise => Random WhatsApp Notification.
            $this->randomWhatsAppNotification($enquiry, $text);

            return;
        }

        Log::info('WhatsApp webhook: unhandled inbound', [
            'enquiryId' => $enquiry->id,
            'button' => $buttonText,
            'text' => $text,
        ]);
    }

    /**
     * Hot Lead Notification — customer requested assistance.
     * Alerts the assigned agent (in-app + WhatsApp template notification_for_hot_lead_toagennt).
     */
    protected function hotLeadNotification(Enquiry $enquiry, string $buttonText): void
    {
        if (! $enquiry->agentId) {
            Log::warning('WhatsApp webhook: enquiry has no agent', ['enquiryId' => $enquiry->id]);

            return;
        }

        $clientName = $enquiry->contact?->masterOrSelf()->name ?? 'A client';
        $reference = $enquiry->referenceNo ?? $enquiry->id;
        $actorId = $enquiry->createdBy ?: $enquiry->agentId;
        $summary = "{$clientName} ({$reference}) requested assistance: \"{$buttonText}\". Please respond immediately.";

        // In-app notification to the agent.
        try {
            $this->notificationService->notifyUser(
                userId: $enquiry->agentId,
                actorId: $actorId,
                type: Constants::NOTIFICATION_TYPES['hot_enquiry_no_activity'],
                title: "Hot lead — {$clientName} needs help",
                message: $summary,
                module: Constants::MODULE_ENQUIRY,
                moduleId: $enquiry->id,
                extraData: ['moduleTitle' => $clientName, 'moduleId' => $enquiry->id, 'response' => $buttonText],
            );
        } catch (\Throwable $e) {
            Log::error('Hot Lead Notification (in-app) failed: '.$e->getMessage());
        }

        // WhatsApp template to the agent (queued).
        try {
            $template = config('services.whatsapp_360.templates.hot_lead');
            $agent = $enquiry->agent;
            if ($template && $agent?->phone) {
                SendWhatsAppTemplateJob::dispatch(
                    $agent->phone,
                    $template,
                    [WhatsAppService::textParams([$summary])], // {{1}} hot lead summary
                    'en_GB', // this template is approved in en_GB, not en
                );
            }
        } catch (\Throwable $e) {
            Log::error('Hot Lead Notification (WhatsApp) failed: '.$e->getMessage());
        }
    }

    /**
     * "No one contacted me" — customer replied that they have not been contacted yet.
     * Notifies the assigned agent (in-app + WhatsApp) to reach out immediately.
     */
    protected function notContactedNotification(Enquiry $enquiry, string $buttonText): void
    {
        if (! $enquiry->agentId) {
            Log::warning('WhatsApp webhook: enquiry has no agent', ['enquiryId' => $enquiry->id]);

            return;
        }

        $clientName = $enquiry->contact?->masterOrSelf()->name ?? 'A client';
        $reference = $enquiry->referenceNo ?? $enquiry->id;
        $actorId = $enquiry->createdBy ?: $enquiry->agentId;
        $summary = "{$clientName} ({$reference}) says no one has contacted them yet. Please reach out immediately.";

        // In-app notification to the agent.
        try {
            $this->notificationService->notifyUser(
                userId: $enquiry->agentId,
                actorId: $actorId,
                type: Constants::NOTIFICATION_TYPES['enquiry_update'],
                title: "Client not contacted yet — {$clientName}",
                message: $summary,
                module: Constants::MODULE_ENQUIRY,
                moduleId: $enquiry->id,
                extraData: ['moduleTitle' => $clientName, 'moduleId' => $enquiry->id, 'response' => $buttonText],
            );
        } catch (\Throwable $e) {
            Log::error('Not-contacted Notification (in-app) failed: '.$e->getMessage());
        }

        // WhatsApp template to the agent (reuses the generic agent-notification template, queued).
        try {
            $template = config('services.whatsapp_360.templates.random_whatsapp');
            $agent = $enquiry->agent;
            if ($template && $agent?->phone) {
                SendWhatsAppTemplateJob::dispatch(
                    $agent->phone,
                    $template,
                    [WhatsAppService::textParams([$summary])], // {{1}} message summary
                );
            }
        } catch (\Throwable $e) {
            Log::error('Not-contacted Notification (WhatsApp) failed: '.$e->getMessage());
        }
    }

    /**
     * "Still searching" — customer is still looking for a property.
     * Marks the lead as active and notifies the assigned agent.
     */
    protected function stillSearchingNotification(Enquiry $enquiry, string $buttonText): void
    {
        // Mark the lead as active.
        try {
            $enquiry->isActive = 1;
            $enquiry->save();
        } catch (\Throwable $e) {
            Log::error('Still-searching (activate) failed: '.$e->getMessage());
        }

        if (! $enquiry->agentId) {
            Log::warning('WhatsApp webhook: enquiry has no agent', ['enquiryId' => $enquiry->id]);

            return;
        }

        $clientName = $enquiry->contact?->masterOrSelf()->name ?? 'A client';
        $reference = $enquiry->referenceNo ?? $enquiry->id;
        $actorId = $enquiry->createdBy ?: $enquiry->agentId;
        $summary = "{$clientName} ({$reference}) is still searching. The lead has been reactivated — please continue the property search.";

        // In-app notification to the agent.
        try {
            $this->notificationService->notifyUser(
                userId: $enquiry->agentId,
                actorId: $actorId,
                type: Constants::NOTIFICATION_TYPES['enquiry_update'],
                title: "Lead still active — {$clientName}",
                message: $summary,
                module: Constants::MODULE_ENQUIRY,
                moduleId: $enquiry->id,
                extraData: ['moduleTitle' => $clientName, 'moduleId' => $enquiry->id, 'response' => $buttonText],
            );
        } catch (\Throwable $e) {
            Log::error('Still-searching Notification (in-app) failed: '.$e->getMessage());
        }

        // WhatsApp template to the agent (reuses the generic agent-notification template, queued).
        try {
            $template = config('services.whatsapp_360.templates.random_whatsapp');
            $agent = $enquiry->agent;
            if ($template && $agent?->phone) {
                SendWhatsAppTemplateJob::dispatch(
                    $agent->phone,
                    $template,
                    [WhatsAppService::textParams([$summary])], // {{1}} message summary
                );
            }
        } catch (\Throwable $e) {
            Log::error('Still-searching Notification (WhatsApp) failed: '.$e->getMessage());
        }
    }

    /**
     * "Found a property" — customer found a property (elsewhere).
     * Marks the enquiry as Lost and notifies the assigned agent.
     */
    protected function foundPropertyLost(Enquiry $enquiry, string $buttonText): void
    {
        // Update the lead status to Lost.
        try {
            $enquiry->status = Constants::ENQUIRY_LOST;
            $enquiry->lostReason = 'Client found a property (reported via WhatsApp)';
            $enquiry->isActive = 0;
            $enquiry->save();
        } catch (\Throwable $e) {
            Log::error('Found-property (mark lost) failed: '.$e->getMessage());

            return;
        }

        if (! $enquiry->agentId) {
            return;
        }

        $clientName = $enquiry->contact?->masterOrSelf()->name ?? 'A client';
        $reference = $enquiry->referenceNo ?? $enquiry->id;
        $actorId = $enquiry->createdBy ?: $enquiry->agentId;
        $summary = "{$clientName} ({$reference}) found a property elsewhere. The lead has been marked as Lost.";

        // In-app notification to the agent.
        try {
            $this->notificationService->notifyUser(
                userId: $enquiry->agentId,
                actorId: $actorId,
                type: Constants::NOTIFICATION_TYPES['enquiry_status_change'],
                title: "Lead marked Lost — {$clientName}",
                message: $summary,
                module: Constants::MODULE_ENQUIRY,
                moduleId: $enquiry->id,
                extraData: ['moduleTitle' => $clientName, 'moduleId' => $enquiry->id, 'response' => $buttonText],
            );
        } catch (\Throwable $e) {
            Log::error('Found-property Notification (in-app) failed: '.$e->getMessage());
        }

        // WhatsApp template to the agent (reuses the generic agent-notification template, queued).
        try {
            $template = config('services.whatsapp_360.templates.random_whatsapp');
            $agent = $enquiry->agent;
            if ($template && $agent?->phone) {
                SendWhatsAppTemplateJob::dispatch(
                    $agent->phone,
                    $template,
                    [WhatsAppService::textParams([$summary])], // {{1}} message summary
                );
            }
        } catch (\Throwable $e) {
            Log::error('Found-property Notification (WhatsApp) failed: '.$e->getMessage());
        }
    }

    /**
     * "Not right now" — customer is not ready yet.
     * Keeps the lead in the nurture cycle (active, holding status) and notifies the agent.
     */
    protected function notRightNowNurture(Enquiry $enquiry, string $buttonText): void
    {
        // Keep the lead in the nurture cycle: active + holding status.
        try {
            $enquiry->status = Constants::ENQUIRY_THINKING_ABOUT_IT;
            $enquiry->isActive = 1;
            $enquiry->save();
        } catch (\Throwable $e) {
            Log::error('Not-right-now (nurture) failed: '.$e->getMessage());

            return;
        }

        if (! $enquiry->agentId) {
            return;
        }

        $clientName = $enquiry->contact?->masterOrSelf()->name ?? 'A client';
        $reference = $enquiry->referenceNo ?? $enquiry->id;
        $actorId = $enquiry->createdBy ?: $enquiry->agentId;
        $summary = "{$clientName} ({$reference}) is not ready right now. The lead has been kept in the nurture cycle for future follow-up.";

        // In-app notification to the agent.
        try {
            $this->notificationService->notifyUser(
                userId: $enquiry->agentId,
                actorId: $actorId,
                type: Constants::NOTIFICATION_TYPES['enquiry_status_change'],
                title: "Lead in nurture — {$clientName}",
                message: $summary,
                module: Constants::MODULE_ENQUIRY,
                moduleId: $enquiry->id,
                extraData: ['moduleTitle' => $clientName, 'moduleId' => $enquiry->id, 'response' => $buttonText],
            );
        } catch (\Throwable $e) {
            Log::error('Not-right-now Notification (in-app) failed: '.$e->getMessage());
        }

        // WhatsApp template to the agent (reuses the generic agent-notification template, queued).
        try {
            $template = config('services.whatsapp_360.templates.random_whatsapp');
            $agent = $enquiry->agent;
            if ($template && $agent?->phone) {
                SendWhatsAppTemplateJob::dispatch(
                    $agent->phone,
                    $template,
                    [WhatsAppService::textParams([$summary])], // {{1}} message summary
                );
            }
        } catch (\Throwable $e) {
            Log::error('Not-right-now Notification (WhatsApp) failed: '.$e->getMessage());
        }
    }

    /**
     * Feedback Notification — customer submitted feedback.
     * Saves the feedback against the contact and notifies the assigned agent
     * (in-app + WhatsApp template feedback_received_for_agent_to_cst).
     */
    protected function feedbackNotification(Enquiry $enquiry, string $feedback): void
    {
        $clientName = $enquiry->contact?->masterOrSelf()->name ?? 'A client';
        $reference = $enquiry->referenceNo ?? $enquiry->id;
        $actorId = $enquiry->createdBy ?: $enquiry->agentId;

        // Save the feedback as a contact note.
        try {
            if ($enquiry->contactId) {
                ContactNote::create([
                    'contactId' => $enquiry->contactId,
                    'content' => $feedback,
                    'type' => 'feedback',
                    'parent' => 0,
                    'createdBy' => $actorId,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Feedback Notification (save) failed: '.$e->getMessage());
        }

        if (! $enquiry->agentId) {
            return;
        }

        $summary = "Feedback from {$clientName} ({$reference}): {$feedback}";

        // In-app notification to the agent.
        try {
            $this->notificationService->notifyUser(
                userId: $enquiry->agentId,
                actorId: $actorId,
                type: Constants::NOTIFICATION_TYPES['enquiry_update'],
                title: "Feedback received from {$clientName}",
                message: $summary,
                module: Constants::MODULE_ENQUIRY,
                moduleId: $enquiry->id,
                extraData: ['moduleTitle' => $clientName, 'moduleId' => $enquiry->id, 'feedback' => $feedback],
            );
        } catch (\Throwable $e) {
            Log::error('Feedback Notification (in-app) failed: '.$e->getMessage());
        }

        // WhatsApp template to the agent (queued).
        try {
            $template = config('services.whatsapp_360.templates.feedback_received');
            $agent = $enquiry->agent;
            if ($template && $agent?->phone) {
                SendWhatsAppTemplateJob::dispatch(
                    $agent->phone,
                    $template,
                    [WhatsAppService::textParams([$summary])], // {{1}} feedback summary
                );
            }
        } catch (\Throwable $e) {
            Log::error('Feedback Notification (WhatsApp) failed: '.$e->getMessage());
        }
    }

    /**
     * Customer Care Escalation — customer reported an issue/complaint.
     * Notifies the assigned agent and all active managers in-app.
     * (No customer-facing WhatsApp template exists for escalation.)
     */
    protected function customerCareEscalation(Enquiry $enquiry, string $message): void
    {
        $clientName = $enquiry->contact?->masterOrSelf()->name ?? 'A client';
        $reference = $enquiry->referenceNo ?? $enquiry->id;
        $actorId = $enquiry->createdBy ?: ($enquiry->agentId ?: 0);
        $summary = "Issue/complaint from {$clientName} ({$reference}): {$message}";

        // Recipients: all active managers + the assigned agent.
        $managerRoleIds = Role::where('name', Constants::MANAGER)->pluck('id');
        $recipients = User::whereIn('roleId', $managerRoleIds)
            ->where('status', Constants::USER_STATUS_ACTIVE)
            ->get(['id', 'name', 'phone']);

        if ($enquiry->agentId && $enquiry->agent) {
            $recipients->push($enquiry->agent);
        }
        $recipients = $recipients->unique('id')->values();

        if ($recipients->isEmpty()) {
            Log::warning('Customer Care Escalation: no recipients', ['enquiryId' => $enquiry->id]);

            return;
        }

        // Reuse the hot-lead template (approved in en_GB) for the escalation WhatsApp alert.
        $template = config('services.whatsapp_360.templates.hot_lead');

        foreach ($recipients as $recipient) {
            // In-app notification.
            try {
                $this->notificationService->notifyUser(
                    userId: $recipient->id,
                    actorId: $actorId ?: $recipient->id,
                    type: Constants::NOTIFICATION_TYPES['enquiry_update'],
                    title: "Customer care escalation — {$clientName}",
                    message: $summary,
                    module: Constants::MODULE_ENQUIRY,
                    moduleId: $enquiry->id,
                    extraData: ['moduleTitle' => $clientName, 'moduleId' => $enquiry->id, 'complaint' => $message],
                );
            } catch (\Throwable $e) {
                Log::error('Customer Care Escalation (in-app) failed: '.$e->getMessage());
            }

            // WhatsApp alert (reuses the hot-lead template, queued).
            try {
                if ($template && $recipient->phone) {
                    SendWhatsAppTemplateJob::dispatch(
                        $recipient->phone,
                        $template,
                        [WhatsAppService::textParams([$summary])], // {{1}} escalation summary
                        'en_GB', // hot-lead template is approved in en_GB
                    );
                }
            } catch (\Throwable $e) {
                Log::error('Customer Care Escalation (WhatsApp) failed: '.$e->getMessage());
            }
        }
    }

    /**
     * Detect whether a free-text message reads like an issue/complaint.
     */
    protected function looksLikeComplaint(string $text): bool
    {
        $normalized = strtolower($text);
        foreach (self::ESCALATION_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect whether a free-text message shows interest in owner services
     * (Property Management, Vacation Homes, or related).
     */
    protected function looksLikeOwnerInterest(string $text): bool
    {
        $normalized = strtolower($text);
        foreach (self::OWNER_INTEREST_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Owner Enquiry Creation — customer showed interest in Property Management /
     * Vacation Homes / related services. Creates a new owner (landlord) enquiry for
     * the contact and notifies the assigned agent (in-app + WhatsApp New Lead template).
     */
    protected function ownerEnquiryCreation(Enquiry $enquiry, string $message): void
    {
        $contact = $enquiry->contact;
        if (! $contact) {
            Log::warning('Owner Enquiry Creation: no contact', ['enquiryId' => $enquiry->id]);

            return;
        }

        $actorId = $enquiry->createdBy ?: ($enquiry->agentId ?: null);

        // Create the owner enquiry (property owner interested in PM / Vacation services).
        try {
            $owner = Enquiry::create([
                'contactId' => $contact->id,
                'agentId' => $enquiry->agentId,
                'phone' => $contact->phone,
                'enquiryType' => Constants::CONTACT_TYPE_LANDLORD, // closest "owner" type
                'status' => Constants::ENQUIRY_NEW_LEAD,
                'leadSource' => $enquiry->leadSource,
                'comments' => 'Owner enquiry (Property Management / Vacation Homes interest) via WhatsApp: '.$message,
                'createdBy' => $actorId,
                'isActive' => 1,
            ]);
        } catch (\Throwable $e) {
            Log::error('Owner Enquiry Creation (create) failed: '.$e->getMessage());

            return;
        }

        $clientName = $contact?->masterOrSelf()->name ?? 'A client';

        if (! $enquiry->agentId) {
            return;
        }

        // In-app notification to the agent.
        try {
            $this->notificationService->notifyUser(
                userId: $enquiry->agentId,
                actorId: $actorId ?: $enquiry->agentId,
                type: Constants::NOTIFICATION_TYPES['new_enquiry'],
                title: "New owner enquiry — {$clientName}",
                message: "{$clientName} is interested in Property Management / Vacation Homes services.",
                module: Constants::MODULE_ENQUIRY,
                moduleId: $owner->id,
                extraData: ['moduleTitle' => $clientName, 'moduleId' => $owner->id, 'message' => $message],
            );
        } catch (\Throwable $e) {
            Log::error('Owner Enquiry Creation (in-app) failed: '.$e->getMessage());
        }

        // WhatsApp to the agent (reuses the New Lead template, queued).
        try {
            $template = config('services.whatsapp_360.templates.new_lead');
            $agent = $enquiry->agent;
            if ($template && $agent?->phone) {
                $link = rtrim(env('FRONTEND_URL', config('app.url')), '/').'/dashboard/enquiries/'.$owner->id.'/view';
                SendWhatsAppTemplateJob::dispatch(
                    $agent->phone,
                    $template,
                    [WhatsAppService::textParams([
                        $owner->referenceNo ?? (string) $owner->id, // {{1}} reference
                        $clientName,                                 // {{2}} client name
                        $contact->phone ?? 'N/A',                    // {{3}} client phone
                        'Owner / PM',                                // {{4}} type
                        $link,                                       // {{5}} link
                    ])],
                );
            }
        } catch (\Throwable $e) {
            Log::error('Owner Enquiry Creation (WhatsApp) failed: '.$e->getMessage());
        }
    }

    /**
     * Random WhatsApp Notification — customer sent a free-text message.
     * Notifies the assigned agent (in-app + WhatsApp template whatsapp_random_noti_agent).
     */
    protected function randomWhatsAppNotification(Enquiry $enquiry, string $message): void
    {
        if (! $enquiry->agentId) {
            Log::warning('WhatsApp webhook: enquiry has no agent', ['enquiryId' => $enquiry->id]);

            return;
        }

        $clientName = $enquiry->contact?->masterOrSelf()->name ?? 'A client';
        $reference = $enquiry->referenceNo ?? $enquiry->id;
        $actorId = $enquiry->createdBy ?: $enquiry->agentId;
        $summary = "From {$clientName} ({$reference}): {$message}";

        // In-app notification to the agent.
        try {
            $this->notificationService->notifyUser(
                userId: $enquiry->agentId,
                actorId: $actorId,
                type: Constants::NOTIFICATION_TYPES['enquiry_update'],
                title: "New WhatsApp message from {$clientName}",
                message: $summary,
                module: Constants::MODULE_ENQUIRY,
                moduleId: $enquiry->id,
                extraData: ['moduleTitle' => $clientName, 'moduleId' => $enquiry->id, 'whatsappMessage' => $message],
            );
        } catch (\Throwable $e) {
            Log::error('Random WhatsApp Notification (in-app) failed: '.$e->getMessage());
        }

        // WhatsApp template to the agent (queued).
        try {
            $template = config('services.whatsapp_360.templates.random_whatsapp');
            $agent = $enquiry->agent;
            if ($template && $agent?->phone) {
                SendWhatsAppTemplateJob::dispatch(
                    $agent->phone,
                    $template,
                    [WhatsAppService::textParams([$summary])], // {{1}} message summary
                );
            }
        } catch (\Throwable $e) {
            Log::error('Random WhatsApp Notification (WhatsApp) failed: '.$e->getMessage());
        }
    }

    /**
     * Find the most recent enquiry for the sender, matching on the last 9 digits of
     * the phone number (ignores country-code formatting differences).
     *
     * Resolves through a merge: a sender whose number belongs to a contact that was
     * merged into another lands on the surviving contact, and the search then covers
     * the whole family — merging never moved the enquiries off the alias, so looking
     * at the master alone would miss them.
     */
    protected function findLatestEnquiryByPhone(string $phone): ?Enquiry
    {
        $contact = Contact::resolveMasterByPhone($phone);

        if (! $contact) {
            return null;
        }

        return Enquiry::whereIn('contactId', $contact->aliasIds())->latest('id')->first();
    }

    protected function extractPhone(Request $request): ?string
    {
        foreach (['from', 'sender', 'senderNumber', 'mobile', 'phone', 'waId', 'wa_id', 'number', 'customerNumber'] as $key) {
            $val = $request->input($key) ?? $request->query($key);
            if (is_scalar($val) && (string) $val !== '') {
                return (string) $val;
            }
        }
        $nested = $request->input('message.from') ?? $request->input('data.from') ?? $request->input('contact.wa_id');

        return is_scalar($nested) ? (string) $nested : null;
    }

    protected function extractText(Request $request): ?string
    {
        foreach (['text', 'body', 'message', 'messageBody', 'content'] as $key) {
            $val = $request->input($key);
            if (is_string($val) && trim($val) !== '') {
                return $val;
            }
        }
        $nested = $request->input('message.text') ?? $request->input('message.body') ?? $request->input('text.body');

        return is_string($nested) ? $nested : null;
    }

    protected function extractButtonText(Request $request): ?string
    {
        foreach (['buttonText', 'button_text', 'button', 'reply', 'payload'] as $key) {
            $val = $request->input($key);
            if (is_string($val) && trim($val) !== '') {
                return $val;
            }
        }
        $nested = $request->input('message.button.text')
            ?? $request->input('button.text')
            ?? $request->input('interactive.button_reply.title');

        return is_string($nested) ? $nested : null;
    }
}
