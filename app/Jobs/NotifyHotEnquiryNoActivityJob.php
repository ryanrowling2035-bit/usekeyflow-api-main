<?php

namespace App\Jobs;

use App\Constants\Constants;
use App\Models\Enquiry;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyHotEnquiryNoActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public const HISTORY_FIELD = 'hotNoActivityNotified';

    public function __construct(public int $enquiryId) {}

    public function handle(NotificationService $notificationService): void
    {
        $enquiry = Enquiry::with(['agent:id,name', 'contact:id,name', 'tasks:id,moduleId,module,updated_at'])
            ->find($this->enquiryId);

        if (! $enquiry || ! $enquiry->agentId) {
            return;
        }

        // Defensive re-checks: still hot (by effective lead score) and not already flagged.
        if ($enquiry->leadRating !== Constants::LEAD_SCORE_HOT) {
            return;
        }

        if ($enquiry->history()->where('field', self::HISTORY_FIELD)->exists()) {
            return;
        }

        $hours = Constants::HOT_ENQUIRY_NO_ACTIVITY_HOURS;
        $contactName = $enquiry->contact?->masterOrSelf()->name ?? "Enquiry #{$enquiry->referenceNo}";
        $enquiryLink = rtrim(env('FRONTEND_URL', config('app.url')), '/').'/dashboard/enquiries/'.$enquiry->id.'/view';
        $lastActivity = $enquiry->lastActivityAt;
        $lastActivityLabel = $lastActivity ? Carbon::parse($lastActivity)->format('F d, Y H:i') : '-';

        // Recipients: the assigned agent + all active managers.
        $recipientIds = [$enquiry->agentId];

        $managerRoleIds = Role::where('name', Constants::MANAGER)->pluck('id');
        $managerIds = User::whereIn('roleId', $managerRoleIds)
            ->where('status', Constants::USER_STATUS_ACTIVE)
            ->pluck('id')
            ->all();

        $recipientIds = array_values(array_unique(array_filter(array_merge($recipientIds, $managerIds))));

        foreach ($recipientIds as $userId) {
            $notificationService->notifyUser(
                userId: $userId,
                actorId: $enquiry->agentId,
                type: Constants::NOTIFICATION_TYPES['hot_enquiry_no_activity'],
                title: "Enquiry needs follow-up (No activity): {$contactName}",
                message: "Enquiry \"{$contactName}\" has had no activity for {$hours}+ hours (last activity: {$lastActivityLabel}). Please follow up.",
                module: Constants::MODULE_ENQUIRY,
                moduleId: $enquiry->id,
                extraData: [
                    'enquiryId' => $enquiry->id,
                    'referenceNo' => $enquiry->referenceNo,
                    'contactName' => $contactName,
                    'inactiveHours' => $hours,
                    'lastActivityAt' => $lastActivity,
                    'enquiryLink' => $enquiryLink,
                ]
            );
        }

        // Flag via enquiry history so the same alert is not sent again.
        logEnquiryHistory([
            'enquiryId' => $enquiry->id,
            'field' => self::HISTORY_FIELD,
            'originalValue' => json_encode(['message' => '0']),
            'newValue' => json_encode(['message' => "Hot enquiry no-activity alert sent after {$hours}h"]),
            'userId' => $enquiry->agentId,
        ]);
    }
}
