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

class NotifyListingMediaUpdatedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    /**
     * @param  string  $mediaAction  e.g. 'added', 'removed', 'updated'
     */
    public function __construct(
        public int $propertyId,
        public string $mediaAction,
        public ?int $count,
        public int $actorId
    ) {}

    public function handle(): void
    {
        $property = Property::find($this->propertyId);
        $actor = User::find($this->actorId);

        if (! $property || ! $actor) {
            return;
        }

        PropertyService::notifyListingMediaUpdated($property, $this->mediaAction, $this->count, $actor);
    }
}
