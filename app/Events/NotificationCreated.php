<?php

namespace App\Events;

use App\Http\Resources\NotificationResources\NotificationResource;
use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Notification $notification;

    public function __construct(Notification $notification)
    {
        $this->notification = $notification;
    }

    /**
     * Get the channels the event should broadcast on.
     * Each user has their own private channel.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('notifications.'.$this->notification->userId),
        ];
    }

    /**
     * The event name to broadcast as.
     */
    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'notification' => (new NotificationResource($this->notification))->resolve(),
        ];
    }
}
