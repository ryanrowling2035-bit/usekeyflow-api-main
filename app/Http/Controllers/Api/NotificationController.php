<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResources\NotificationResource;
use App\Http\Resources\NotificationResources\UnreadNotificationResource;
use App\Services\NotificationService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * Get all notifications for the authenticated user.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);

            $scopeUserId = $request->user()->id;
            if (! isAgent() && $request->filled('assignedTo')) {
                $scopeUserId = (int) $request->input('assignedTo');
            }

            $notifications = $this->notificationService->getUserNotifications(
                $scopeUserId,
                $perPage,
                [
                    // status: 'read' | 'unread' (empty = all)
                    'status' => $request->input('status'),
                    // type: the notification's module (enquiry, property, offer, ...)
                    'type' => $request->input('type'),
                    'search' => $request->input('search'),
                ]
            );

            return $this->success(
                NotificationResource::collection($notifications),
                'Notifications retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('NotificationController : index', $e);
        }
    }

    /**
     * Get only unread notifications.
     */
    public function unread(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $notifications = $this->notificationService->getUnreadNotifications($userId, 10);
            $count = $this->notificationService->getUnreadCount($userId);

            return $this->success(
                [
                    'unreadCount' => $count,
                    'notifications' => UnreadNotificationResource::collection($notifications),
                ],
                'Unread notifications retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('NotificationController : unread', $e);
        }
    }

    /**
     * Get the count of unread notifications.
     */
    public function unreadCount(Request $request)
    {
        try {
            $count = $this->notificationService->getUnreadCount($request->user()->id);

            return $this->success(
                ['count' => $count],
                'Unread count retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('NotificationController : unreadCount', $e);
        }
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(int $id, Request $request)
    {
        try {
            $result = $this->notificationService->markAsRead($id, $request->user()->id);

            if (! $result) {
                return $this->error('The requested notification was not found.', 422);
            }

            return $this->success([], 'Notification marked as read');
        } catch (\Exception $e) {
            return $this->exceptionResponse('NotificationController : markAsRead', $e);
        }
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request)
    {
        try {
            $count = $this->notificationService->markAllAsRead($request->user()->id);

            return $this->success(
                ['marked' => $count],
                'All notifications marked as read'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('NotificationController : markAllAsRead', $e);
        }
    }

    /**
     * Delete a specific notification.
     */
    public function destroy(int $id, Request $request)
    {
        try {
            $result = $this->notificationService->delete($id, $request->user()->id);

            if (! $result) {
                return $this->error('The requested notification was not found.', 422);
            }

            return $this->success([], 'Notification deleted successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('NotificationController : destroy', $e);
        }
    }

    /**
     * Delete notifications — bulk IDs or all.
     * Body: { "ids": [1, 2, 3] } for bulk, empty/no body for all.
     */
    public function destroyAll(Request $request)
    {
        try {
            $userId = $request->user()->id;

            if ($request->has('ids') && is_array($request->ids) && count($request->ids) > 0) {
                $count = $this->notificationService->deleteBulk($request->ids, $userId);
                $message = "{$count} notification(s) deleted successfully";
            } else {
                $count = $this->notificationService->deleteAll($userId);
                $message = 'All notifications deleted successfully';
            }

            return $this->success(['deleted' => $count], $message);
        } catch (\Exception $e) {
            return $this->exceptionResponse('NotificationController : destroyAll', $e);
        }
    }
}
