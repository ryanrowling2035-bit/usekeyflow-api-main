<?php

namespace App\Jobs;

use App\Constants\Constants;
use App\Models\Closing;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyAgentClosingCompletedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public function __construct(public int $closingId, public int $actorId) {}

    public function handle(NotificationService $notificationService): void
    {
        $closing = Closing::with(['assignedAgent:id,name', 'property:id,title'])->find($this->closingId);

        // Only congratulate when the closing exists, has an agent and is actually completed.
        if (! $closing
            || ! $closing->assignedAgentId
            || $closing->status !== Constants::CLOSE_STATUS_CLOSED) {
            return;
        }

        $agent = $closing->assignedAgent;
        if (! $agent) {
            return;
        }

        $closingRef = $closing->referenceNo ?? (string) $closing->id;
        $propertyTitle = $closing->property?->title;
        $propertyDisplay = $propertyTitle ? "{$propertyTitle} / {$closingRef}" : $closingRef;

        $notificationService->notifyUser(
            userId: $agent->id,
            actorId: $this->actorId,
            type: Constants::NOTIFICATION_TYPES['closing_completed'],
            title: 'Congratulations on your closing!',
            message: "Congratulations {$agent->name}! Your closing for {$propertyDisplay} has been completed. Great work from the company.",
            module: Constants::MODULE_CLOSING,
            moduleId: $closing->id,
            extraData: [
                'closingId' => $closing->id,
                'closingRef' => $closingRef,
                'propertyTitle' => $propertyTitle,
            ]
        );
    }
}
