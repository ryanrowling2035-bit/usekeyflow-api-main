<?php

namespace App\Services;

use App\Constants\Constants;
use App\Events\NotificationCreated;
use App\Jobs\SendWebPushJob;
use App\Models\Enquiry;
use App\Models\Follow;
use App\Models\Notification;
use App\Models\Property;
use App\Repositories\NotificationRepository;

class NotificationService
{
    public function __construct(
        protected NotificationRepository $notificationRepository
    ) {}

    public function getUserNotifications(int $userId, int $perPage = 20, array $filters = [])
    {
        return $this->notificationRepository->getUserNotifications($userId, $perPage, $filters);
    }

    public function getUnreadNotifications(int $userId, int $limit = 10)
    {
        return $this->notificationRepository->getUnreadNotifications($userId, $limit);
    }

    public function getUnreadCount(int $userId): int
    {
        return $this->notificationRepository->getUnreadCount($userId);
    }

    public function markAsRead(int $notificationId, int $userId): bool
    {
        $notification = $this->notificationRepository->findByUser($notificationId, $userId);

        if (! $notification) {
            return false;
        }

        return $this->notificationRepository->markAsRead($notification);
    }

    public function markAllAsRead(int $userId): int
    {
        return $this->notificationRepository->markAllAsRead($userId);
    }

    public function delete(int $notificationId, int $userId): bool
    {
        $notification = $this->notificationRepository->findByUser($notificationId, $userId);

        if (! $notification) {
            return false;
        }

        return $this->notificationRepository->delete($notification);
    }

    public function deleteBulk(array $ids, int $userId): int
    {
        return $this->notificationRepository->deleteBulkForUser($ids, $userId);
    }

    public function deleteAll(int $userId): int
    {
        return $this->notificationRepository->deleteAllForUser($userId);
    }

    /**
     * Create a notification and broadcast it.
     */
    public function create(array $data): Notification
    {
        // Producers re-run on every save/upload/removal, which used to stack byte-identical
        // alerts in the bell. Guarding the one path they all funnel through covers every
        // producer at once. The suppressed alert's predecessor is returned so callers still
        // get a real record back, and neither broadcast nor web push fires for a repeat.
        if ($duplicate = $this->findSuppressedDuplicate($data)) {
            return $duplicate;
        }

        $notification = $this->notificationRepository->create($data);

        // Load actor relationship for broadcasting
        $notification->load('actor:id,name,profilePhoto');

        // Broadcast the notification for real-time updates. Broadcasting is
        // best-effort: a broadcaster outage (e.g. Reverb unreachable under a
        // sync queue) must never roll back the persisted notification or break
        // the business operation that triggered it.
        try {
            broadcast(new NotificationCreated($notification))->toOthers();
        } catch (\Throwable $e) {
            \Log::error('Failed to broadcast NotificationCreated #'.$notification->id.': '.$e->getMessage());
        }

        // Browser push reaches the user when no CRM tab is open, which is where
        // the Reverb broadcast above stops. Best-effort for the same reason.
        try {
            SendWebPushJob::dispatch($notification->id);
        } catch (\Throwable $e) {
            \Log::error('Failed to queue SendWebPushJob for notification #'.$notification->id.': '.$e->getMessage());
        }

        return $notification;
    }

    /**
     * The earlier notification this one would merely repeat, while still suppressed.
     *
     * Compared against the latest alert of the same type on the same record for this
     * recipient — not the latest matching one — so content that changes and later
     * changes back still re-alerts. Applies to every notification type; see
     * Constants::NOTIFICATION_DEDUPE_MINUTES.
     */
    protected function findSuppressedDuplicate(array $data): ?Notification
    {
        $type = $data['type'] ?? null;
        $userId = $data['userId'] ?? null;

        if (! $type || ! $userId) {
            return null;
        }

        $query = Notification::where('userId', $userId)->where('type', $type);

        // Account-level alerts (e.g. target_alert) carry neither module nor moduleId,
        // and `= null` never matches in SQL — those have to be matched as IS NULL.
        foreach (['module' => $data['module'] ?? null, 'moduleId' => $data['moduleId'] ?? null] as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        $last = $query->orderByDesc('id')->first(['id', 'title', 'message', 'created_at']);

        if (! $last
            || $last->title !== ($data['title'] ?? null)
            || $last->message !== ($data['message'] ?? null)) {
            return null;
        }

        return $last->created_at->gt(now()->subMinutes(Constants::NOTIFICATION_DEDUPE_MINUTES))
            ? $last
            : null;
    }

    /**
     * Notify the owner and all followers of a Property or Enquiry.
     */
    public function notifyOwnerAndFollowers(
        string $module,
        int $moduleId,
        int $actorId,
        string $type,
        string $title,
        ?string $message = null,
        ?array $extraData = null
    ): array {
        $createdNotifications = [];

        // Get the owner (createdBy) of the module
        $ownerId = $this->getModuleOwnerId($module, $moduleId);

        // Get all follower user IDs
        $followerIds = Follow::where('module', $module)
            ->where('moduleId', $moduleId)
            ->pluck('userId')
            ->toArray();

        // Combine owner and followers, removing duplicates and the actor
        $recipientIds = array_unique(array_merge(
            $ownerId ? [$ownerId] : [],
            $followerIds
        ));

        // Don't notify the actor (person who performed the action)
        $recipientIds = array_filter($recipientIds, fn ($id) => $id !== $actorId);

        foreach ($recipientIds as $userId) {
            $notification = $this->create([
                'userId' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'module' => $module,
                'moduleId' => $moduleId,
                'actorId' => $actorId,
                'data' => $extraData,
            ]);

            $createdNotifications[] = $notification;
        }

        return $createdNotifications;
    }

    /**
     * Notify only the owner of a module.
     */
    public function notifyOwner(
        string $module,
        int $moduleId,
        int $actorId,
        string $type,
        string $title,
        ?string $message = null,
        ?array $extraData = null
    ): ?Notification {
        $ownerId = $this->getModuleOwnerId($module, $moduleId);

        if (! $ownerId || $ownerId === $actorId) {
            return null;
        }

        return $this->create([
            'userId' => $ownerId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'module' => $module,
            'moduleId' => $moduleId,
            'actorId' => $actorId,
            'data' => $extraData,
        ]);
    }

    /**
     * Notify only the followers of a module (not the owner).
     */
    public function notifyFollowers(
        string $module,
        int $moduleId,
        int $actorId,
        string $type,
        string $title,
        ?string $message = null,
        ?array $extraData = null
    ): array {
        $createdNotifications = [];

        $followerIds = Follow::where('module', $module)
            ->where('moduleId', $moduleId)
            ->where('userId', '!=', $actorId)
            ->pluck('userId')
            ->toArray();

        foreach ($followerIds as $userId) {
            $notification = $this->create([
                'userId' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'module' => $module,
                'moduleId' => $moduleId,
                'actorId' => $actorId,
                'data' => $extraData,
            ]);

            $createdNotifications[] = $notification;
        }

        return $createdNotifications;
    }

    /**
     * Notify a specific user.
     */
    public function notifyUser(
        int $userId,
        int $actorId,
        string $type,
        string $title,
        ?string $message = null,
        ?string $module = null,
        ?int $moduleId = null,
        ?array $extraData = null
    ): Notification {
        return $this->create([
            'userId' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'module' => $module,
            'moduleId' => $moduleId,
            'actorId' => $actorId,
            'data' => $extraData,
        ]);
    }

    /**
     * Get the owner (createdBy) ID of a module.
     */
    protected function getModuleOwnerId(string $module, int $moduleId): ?int
    {
        return match ($module) {
            Constants::MODULE_PROPERTY => Property::where('id', $moduleId)->value('createdBy'),
            Constants::MODULE_ENQUIRY => Enquiry::where('id', $moduleId)->value('createdBy'),
            default => null,
        };
    }
}
