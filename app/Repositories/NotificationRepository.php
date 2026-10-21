<?php

namespace App\Repositories;

use App\Models\Follow;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationRepository
{
    // Type-filter categories are matched by notification `type`, NOT `module`:
    // the module column is the linked record's module, so e.g. a task_assigned
    // notification can carry module=enquiry/offer/property. Matching by type keeps
    // each category accurate regardless of what the notification is linked to.

    /** Approval notifications (property-approval workflow). */
    private const APPROVAL_TYPES = [
        'property_approval_requested',
        'property_status_approved',
        'property_status_rejected',
        'property_permit_entered',
    ];

    /** System / account-level notifications, spread across modules. */
    private const SYSTEM_TYPES = [
        'data_export',
        'restricted_action_attempt',
        'document_missing',
        'document_uploaded',
    ];

    /** Listing notifications on the property module not prefixed "listing_". */
    private const LISTING_PROPERTY_TYPES = [
        'property_published',
        'property_update',
        'pocket_listing_aged',
        'upcoming_listing_aged',
        'published_listing_key_data_changed',
        'new_listing_match',
    ];

    public function create(array $data): Notification
    {
        return Notification::create($data);
    }

    public function getUserNotifications(int $userId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $status = $filters['status'] ?? null;   // 'read' | 'unread'
        $type = $filters['type'] ?? null;       // matches the `module` column
        $search = trim((string) ($filters['search'] ?? ''));

        return Notification::with('actor:id,name,profilePhoto')
            ->where('userId', $userId)
            ->when($status === 'unread', fn ($q) => $q->where('isRead', false))
            ->when($status === 'read', fn ($q) => $q->where('isRead', true))
            ->when(! empty($type), fn ($q) => $this->applyTypeFilter($q, $type))
            ->when($search !== '', fn ($q) => $q->where(function ($sub) use ($search) {
                $sub->where('title', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            }))
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Translate a Type-filter category into a query, matching by notification
     * `type` (module is unreliable — see the class-level note). task/offer/closing
     * use a prefix so future types are covered automatically; inquiry adds its
     * non-prefixed types; listing is the "listing_" prefix plus the property
     * notifications that aren't approvals; approval and system are explicit lists.
     * Any other value falls back to a raw module match (older filters keep working).
     */
    protected function applyTypeFilter(Builder $query, string $type): void
    {
        match ($type) {
            'tasks', 'task' => $query->where(fn ($q) => $q->where('type', 'like', 'task%')->orWhere('type', 'overdue_followups')),
            'inquiry', 'enquiry' => $query->where(fn ($q) => $q->where('type', 'like', 'enquiry%')->orWhereIn('type', ['new_enquiry', 'hot_enquiry_no_activity'])),
            'offer' => $query->where('type', 'like', 'offer%'),
            'closing' => $query->where('type', 'like', 'closing%'),
            'listing', 'property' => $query->where(fn ($q) => $q->where('type', 'like', 'listing%')->orWhereIn('type', self::LISTING_PROPERTY_TYPES)),
            'approval' => $query->whereIn('type', self::APPROVAL_TYPES),
            'system' => $query->whereIn('type', self::SYSTEM_TYPES),
            default => $query->where('module', $type),
        };
    }

    public function getUnreadNotifications(int $userId, int $limit = 10)
    {
        return Notification::where('userId', $userId)
            ->where('isRead', false)
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function getUnreadCount(int $userId): int
    {
        return Notification::where('userId', $userId)
            ->where('isRead', false)
            ->count();
    }

    public function find(int $id): ?Notification
    {
        return Notification::find($id);
    }

    public function findByUser(int $id, int $userId): ?Notification
    {
        return Notification::where('id', $id)
            ->where('userId', $userId)
            ->first();
    }

    public function markAsRead(Notification $notification): bool
    {
        return $notification->update([
            'isRead' => true,
            'readAt' => now(),
        ]);
    }

    public function markAllAsRead(int $userId): int
    {
        return Notification::where('userId', $userId)
            ->where('isRead', false)
            ->update([
                'isRead' => true,
                'readAt' => now(),
            ]);
    }

    public function delete(Notification $notification): bool
    {
        return $notification->delete();
    }

    public function deleteBulkForUser(array $ids, int $userId): int
    {
        return Notification::where('userId', $userId)->whereIn('id', $ids)->delete();
    }

    public function deleteAllForUser(int $userId): int
    {
        return Notification::where('userId', $userId)->delete();
    }

    public function getFollowersForModule(string $module, int $moduleId): array
    {
        return Follow::where('module', $module)
            ->where('moduleId', $moduleId)
            ->pluck('userId')
            ->toArray();
    }
}
