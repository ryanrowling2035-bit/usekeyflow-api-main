<?php

namespace App\Repositories;

use App\Constants\Constants;
use App\Models\Favorite;

class FavoriteRepository
{
    public function find(int $userId, int $moduleId, string $module)
    {
        return Favorite::where('userId', $userId)->where('moduleId', $moduleId)->where('module', $module)->first();
    }

    public function create(array $data)
    {
        return Favorite::create($data);
    }

    public function delete(Favorite $favorite)
    {
        return $favorite->delete();
    }

    public function getUserFavorites(int $userId, string $module)
    {
        return Favorite::with($module)->where('userId', $userId)->where('module', $module)->latest()->get();
    }

    public function favoriteListings(int $userId)
    {
        return Favorite::with([
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
                })->orWhere('module', '!=', Constants::MODULE_PROPERTY);
            })
            ->latest()
            ->get();
    }

    public function favoriteCount(int $userId): int
    {
        return Favorite::where('userId', $userId)
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('module', Constants::MODULE_PROPERTY)
                        ->whereHas('property');
                })->orWhere('module', '!=', Constants::MODULE_PROPERTY);
            })
            ->count();
    }

    public function deleteByUserId(int $userId): int
    {
        return Favorite::where('userId', $userId)->delete();
    }
}
