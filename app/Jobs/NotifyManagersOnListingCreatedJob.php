<?php

namespace App\Jobs;

use App\Constants\Constants;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyManagersOnListingCreatedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public function __construct(public int $propertyId, public int $actorId) {}

    public function handle(NotificationService $notificationService): void
    {
        $property = Property::with('agent:id,name')->find($this->propertyId);
        $actor = User::find($this->actorId);

        if (! $property || ! $actor) {
            return;
        }

        $managerRoleIds = Role::where('name', Constants::MANAGER)->pluck('id');

        $managers = User::whereIn('roleId', $managerRoleIds)
            ->where('status', Constants::USER_STATUS_ACTIVE)
            ->where('id', '!=', $actor->id)
            ->get(['id', 'name']);

        if ($managers->isEmpty()) {
            return;
        }

        $agentName = $property->agent?->name ?? $actor->name;
        $statusLabel = Constants::PROPERTY_STATUS[$property->status] ?? $property->status;
        $propertyRef = $property->referenceNumber ?? $property->id;
        $propertyLink = rtrim(env('FRONTEND_URL', config('app.url')), '/').'/dashboard/property/'.$property->id.'/view';

        foreach ($managers as $manager) {
            $notificationService->notifyUser(
                userId: $manager->id,
                actorId: $actor->id,
                type: Constants::NOTIFICATION_TYPES['listing_created'],
                title: "New listing created: {$property->title}",
                message: "{$agentName} created a new listing \"{$property->title}\" ({$propertyRef}) with status \"{$statusLabel}\".",
                module: Constants::MODULE_PROPERTY,
                moduleId: $property->id,
                extraData: [
                    'propertyId' => $property->id,
                    'propertyRef' => $propertyRef,
                    'propertyTitle' => $property->title,
                    'agentName' => $agentName,
                    'status' => $statusLabel,
                    'propertyLink' => $propertyLink,
                ]
            );
        }
    }
}
