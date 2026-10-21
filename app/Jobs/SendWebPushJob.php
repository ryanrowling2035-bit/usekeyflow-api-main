<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fans a persisted notification out to the user's browser push subscriptions.
 * Queued because delivery is one outbound HTTP call per subscribed browser and
 * must never sit in the request that triggered the notification.
 */
class SendWebPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(public int $notificationId) {}

    public function handle(WebPushService $webPushService): void
    {
        if (! $webPushService->isConfigured()) {
            return;
        }

        $notification = Notification::find($this->notificationId);

        // Deleted or already read before the worker got to it — nothing to push.
        if (! $notification || $notification->isRead) {
            return;
        }

        try {
            $webPushService->sendToUser($notification->userId, [
                'id' => $notification->id,
                'title' => $notification->title,
                'body' => $notification->message,
                'type' => $notification->type,
                'module' => $notification->module,
                'moduleId' => $notification->moduleId,
            ]);
        } catch (\Throwable $e) {
            Log::error('SendWebPushJob : notification #'.$this->notificationId.' : '.$e->getMessage());
        }
    }
}
