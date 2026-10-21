<?php

namespace App\Jobs;

use App\Constants\Constants;
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

class NotifyDataExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    /**
     * @param  string  $outcome  'exported' (successful) or 'blocked' (attempt denied)
     */
    public function __construct(
        public int $actorId,
        public string $module,
        public int $recordCount,
        public string $exportedAt,
        public string $outcome = 'exported'
    ) {}

    public function handle(NotificationService $notificationService, MailService $mailService): void
    {
        $actor = User::find($this->actorId);
        if (! $actor) {
            return;
        }

        // Alert Super Admins and Managers (the exporting user is excluded).
        $roleIds = Role::whereIn('name', [Constants::SUPER_ADMIN, Constants::MANAGER])->pluck('id');
        $recipients = User::whereIn('roleId', $roleIds)
            ->where('status', Constants::USER_STATUS_ACTIVE)
            ->where('id', '!=', $this->actorId)
            ->get(['id', 'name', 'email']);

        if ($recipients->isEmpty()) {
            return;
        }

        $moduleLabel = ucfirst($this->module);
        $exportedAtLabel = Carbon::parse($this->exportedAt)->format('F d, Y H:i');
        $blocked = $this->outcome === 'blocked';

        $title = $blocked
            ? "Blocked export attempt: {$moduleLabel}"
            : "Data export: {$moduleLabel}";
        $message = $blocked
            ? "{$actor->name} attempted to export {$moduleLabel} data on {$exportedAtLabel} but was blocked (not permitted)."
            : "{$actor->name} exported {$this->recordCount} {$moduleLabel} record(s) on {$exportedAtLabel}.";

        foreach ($recipients as $recipient) {
            // CRM notification
            $notificationService->notifyUser(
                userId: $recipient->id,
                actorId: $this->actorId,
                type: Constants::NOTIFICATION_TYPES['data_export'],
                title: $title,
                message: $message,
                module: $this->module,
                moduleId: null,
                extraData: [
                    'user' => $actor->name,
                    'userId' => $this->actorId,
                    'module' => $this->module,
                    'recordCount' => $this->recordCount,
                    'exportedAt' => $this->exportedAt,
                    'outcome' => $this->outcome,
                ]
            );

            // Email alert (successful exports only; blocked attempts are CRM-only).
            if ($blocked || empty($recipient->email)) {
                continue;
            }

            try {
                $mailService->sendEmail(
                    Constants::EMAIL_SLUGS['user']['data_export'],
                    $recipient->email,
                    [
                        'recipient_name' => ucwords($recipient->name),
                        'user' => ucwords($actor->name),
                        'module' => $moduleLabel,
                        'record_count' => $this->recordCount,
                        'exported_at' => $exportedAtLabel,
                        'company_name' => getCompanyName(),
                    ]
                );
            } catch (\Exception $e) {
                Log::error('NotifyDataExportJob : sendEmail : '.$e->getMessage());
            }
        }
    }
}
