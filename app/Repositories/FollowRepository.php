<?php

namespace App\Repositories;

use App\Constants\Constants;
use App\Models\Favorite;
use App\Models\Follow;

class FollowRepository
{
    public function find(int $userId, int $moduleId, string $module)
    {
        return Follow::where('userId', $userId)->where('moduleId', $moduleId)->where('module', $module)->first();
    }

    public function create(array $data)
    {
        return Follow::create($data);
    }

    public function delete(Follow $follow)
    {
        return $follow->delete();
    }

    public function getUserFollows(int $userId, string $module)
    {
        return Follow::with($module === 'property' ? 'property' : null)
            ->where('userId', $userId)
            ->where('module', $module)
            ->latest()
            ->get();
    }

    public function followingListings(int $userId)
    {
        return Follow::with([
            'property' => function ($q) {
                $q->with(Favorite::propertyEagerRelations())
                    ->withCount('acceptedOffers');
            },
            'enquiry',
        ])
            ->where('userId', $userId)
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('module', Constants::MODULE_PROPERTY)
                        ->whereHas('property');
                })->orWhere(function ($q) {
                    $q->where('module', Constants::MODULE_ENQUIRY)
                        ->whereHas('enquiry');
                });
            })
            ->latest()
            ->get();
    }

    public function followingCount(int $userId): int
    {
        return Follow::where('userId', $userId)
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('module', Constants::MODULE_PROPERTY)
                        ->whereHas('property');
                })->orWhere(function ($q) {
                    $q->where('module', Constants::MODULE_ENQUIRY)
                        ->whereHas('enquiry');
                });
            })
            ->count();
    }

    public function deleteByUserId(int $userId): int
    {
        return Follow::where('userId', $userId)->delete();
    }
}
