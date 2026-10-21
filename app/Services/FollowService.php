<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\Enquiry;
use App\Models\Follow;
use App\Models\Property;
use App\Models\User;
use App\Repositories\FollowRepository;

class FollowService
{
    public function __construct(
        protected FollowRepository $followRepository,
        protected NotificationService $notificationService
    ) {}

    public function add(array $data): array
    {
        $exists = Follow::where('userId', $data['userId'])
            ->where('moduleId', $data['moduleId'])
            ->where('module', $data['module'])
            ->exists();

        if ($exists) {
            return [
                'success' => false,
                'message' => 'Already following',
            ];
        }

        $follow = Follow::create($data);

        // Notify the owner that someone followed their listing/enquiry
        $this->notifyOnFollow($data['userId'], $data['moduleId'], $data['module']);

        return [
            'success' => true,
            'message' => 'Followed successfully',
            'data' => $follow,
        ];
    }

    public function toggleFollow(int $userId, int $moduleId, string $module): array
    {
        $follow = Follow::where('userId', $userId)
            ->where('moduleId', $moduleId)
            ->where('module', $module)
            ->first();

        if ($follow) {
            $follow->delete();

            return [
                'success' => true,
                'message' => ucfirst($module).' unfollowed successfully',
                'isFollowing' => false,
            ];
        }

        Follow::create([
            'userId' => $userId,
            'moduleId' => $moduleId,
            'module' => $module,
        ]);

        // Notify the owner that someone followed their listing/enquiry
        $this->notifyOnFollow($userId, $moduleId, $module);

        return [
            'success' => true,
            'message' => ucfirst($module).' followed successfully',
            'isFollowing' => true,
        ];
    }

    public function deleteMultiple(array $ids, int $userId): int
    {
        return Follow::whereIn('id', $ids)
            // ->where('followerId', $userId)
            ->delete();
    }

    public function getUserFollows(int $userId, string $module)
    {
        return Follow::where('userId', $userId)
            ->where('module', $module)
            ->latest()
            ->get();
    }

    public function followingListings($userId)
    {
        return $this->followRepository->followingListings($userId);
    }

    public function followingCount(int $userId): int
    {
        return $this->followRepository->followingCount($userId);
    }

    public function deleteAllFollowsById($userId): int
    {
        return $this->followRepository->deleteByUserId($userId);
    }

    /**
     * Notify the owner when someone follows their Property or Enquiry.
     */
    protected function notifyOnFollow(int $actorId, int $moduleId, string $module): void
    {
        $actor = User::find($actorId);
        if (! $actor) {
            return;
        }

        $moduleName = $this->getModuleName($module, $moduleId);

        $this->notificationService->notifyOwner(
            module: $module,
            moduleId: $moduleId,
            actorId: $actorId,
            type: Constants::NOTIFICATION_TYPES['new_follow'],
            title: "{$actor->name} started following your {$module}",
            message: "{$actor->name} is now following {$moduleName}",
            extraData: [
                'actorName' => $actor->name,
                'moduleName' => $moduleName,
            ]
        );
    }

    /**
     * Get a human-readable name for the module.
     */
    protected function getModuleName(string $module, int $moduleId): string
    {
        return match ($module) {
            Constants::MODULE_PROPERTY => Property::where('id', $moduleId)->value('title') ?? "Property #{$moduleId}",
            Constants::MODULE_ENQUIRY => "Enquiry #{$moduleId}",
            default => "{$module} #{$moduleId}",
        };
    }
}
