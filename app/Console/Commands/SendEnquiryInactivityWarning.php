<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Models\Enquiry;
use App\Services\MailService;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendEnquiryInactivityWarning extends Command
{
    protected $signature = 'app:send-enquiry-inactivity-warning';

    protected $description = 'Send warning email and notification to agents with no tasks on their enquiries for 12+ hours';

    private const INACTIVITY_HOURS = 12;

    private const HISTORY_FIELD = 'inactivityWarningSent';

    public function handle(): int
    {
        $notificationService = app(NotificationService::class);
        $mailService = app(MailService::class);

        $cutoff = now()->subHours(self::INACTIVITY_HOURS);

        // New Lead / Contacting: no tasks, created 12h+ ago, not already warned
        $enquiries = Enquiry::with(['agent:id,name,email', 'contact:id,name'])
            ->whereNotNull('agentId')
            ->where('isOpenGame', 0)
            ->whereIn('status', [Constants::ENQUIRY_NEW_LEAD, Constants::ENQUIRY_CONTACTING])
            ->where('created_at', '<=', $cutoff)
            ->whereDoesntHave('tasks')
            ->whereDoesntHave('history', function ($query) {
                $query->where('field', self::HISTORY_FIELD);
            })
            ->whereDoesntHave('agent', function ($agentQuery) {
                $agentQuery->where('isAvailable', 0);
            })
            ->get();

        $sentCount = 0;

        foreach ($enquiries as $enquiry) {
            $agent = $enquiry->agent;

            if (! $agent || empty($agent->email)) {
                continue;
            }

            $contactName = $enquiry->contact?->masterOrSelf()->name ?? "Enquiry #{$enquiry->referenceNo}";

            $notificationService->notifyUser(
                userId: $agent->id,
                actorId: $agent->id,
                type: Constants::NOTIFICATION_TYPES['enquiry_inactivity_warning'],
                title: "Inactivity Warning: {$contactName}",
                message: "No task created since lead assignment. Please follow up on enquiry '{$contactName}'.",
                module: Constants::MODULE_ENQUIRY,
                moduleId: $enquiry->id,
                extraData: [
                    'referenceNo' => $enquiry->referenceNo,
                    'inactivityHours' => self::INACTIVITY_HOURS,
                ]
            );

            try {
                $mailService->sendEmail(
                    Constants::EMAIL_SLUGS['enquiry']['inactivity_warning'],
                    $agent->email,
                    [
                        'name' => ucwords($agent->name),
                        'email' => $agent->email,
                        'contact_name' => $contactName,
                        'reference_no' => $enquiry->referenceNo,
                        'enquiry_status' => Constants::ENQUIRY_STATUS[$enquiry->status] ?? $enquiry->status,
                        'inactivity_hours' => self::INACTIVITY_HOURS,
                        'reason' => 'No task created since lead assignment',
                        'company_name' => getCompanyName(),
                    ]
                );
            } catch (\Exception $e) {
                Log::error('SendEnquiryInactivityWarning : sendEmail : '.$e->getMessage());
            }

            // Flag via enquiry history so warning is never sent again. No userId:
            // the scheduler sent the warning, the agent received it — crediting the
            // agent made the timeline read as though they had warned themselves.
            logEnquiryHistory([
                'enquiryId' => $enquiry->id,
                'field' => self::HISTORY_FIELD,
                'originalValue' => json_encode(['message' => '0']),
                'newValue' => json_encode(['message' => 'Warning sent after '.self::INACTIVITY_HOURS.'h inactivity']),
            ]);

            $sentCount++;
        }

        $this->info("Sent {$sentCount} enquiry inactivity warnings.");

        return self::SUCCESS;
    }
}
