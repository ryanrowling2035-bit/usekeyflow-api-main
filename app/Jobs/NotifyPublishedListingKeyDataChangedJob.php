<?php

namespace App\Jobs;

use App\Models\Property;
use App\Models\User;
use App\Services\PropertyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyPublishedListingKeyDataChangedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    /**
     * @param  array  $before  Snapshot of the listing key data taken before the update.
     */
    public function __construct(public int $propertyId, public array $before, public int $actorId) {}

    public function handle(PropertyService $propertyService): void
    {
        $property = Property::with('meta')->find($this->propertyId);
        $actor = User::find($this->actorId);

        if (! $property || ! $actor) {
            return;
        }

        $propertyService->notifyPublishedListingKeyDataChanges($this->before, $property, $actor);
    }
}
