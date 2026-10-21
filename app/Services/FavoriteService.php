<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\Favorite;
use App\Repositories\FavoriteRepository;

class FavoriteService
{
    public function __construct(
        protected FavoriteRepository $favoriteRepository
    ) {}

    public function toggleFavorite(int $userId, int $moduleId, string $module): array
    {
        $favorite = Favorite::where('userId', $userId)
            ->where('moduleId', $moduleId)
            ->where('module', $module)
            ->first();

        if ($favorite) {
            $favorite->delete();

            return [
                'success' => true,
                'message' => ucfirst($module).' unfavorited successfully',
                'isFavorite' => false,
            ];
        }

        Favorite::create([
            'userId' => $userId,
            'moduleId' => $moduleId,
            'module' => $module,
        ]);

        return [
            'success' => true,
            'message' => ucfirst($module).' favorited successfully',
            'isFavorite' => true,
        ];
    }

    public function deleteMultiple(array $ids)
    {
        return Favorite::whereIn('id', $ids)->delete();
    }

    public function getUserFavorites(int $userId, string $module)
    {
        $query = Favorite::where('userId', $userId)
            ->where('module', $module);

        if ($module === Constants::MODULE_PROPERTY) {
            $query->with(['property' => function ($q) {
                $q->with(Favorite::propertyEagerRelations())
                    ->withCount('acceptedOffers');
            }])->whereHas('property');
        } elseif ($module === Constants::MODULE_ENQUIRY) {
            $query->with('enquiry');
        }

        return $query->latest()->get();
    }

    public function favoriteListings($userId)
    {
        return $this->favoriteRepository->favoriteListings($userId);
    }

    public function favoriteCount(int $userId): int
    {
        return $this->favoriteRepository->favoriteCount($userId);
    }

    public function deleteAllFavoriteById($userId): int
    {
        return $this->favoriteRepository->deleteByUserId($userId);
    }
}
