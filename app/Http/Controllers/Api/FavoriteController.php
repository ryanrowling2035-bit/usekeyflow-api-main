<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Http\Resources\FavoriteResources\FavoriteResource;
use App\Models\Favorite;
use App\Services\FavoriteService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FavoriteController extends Controller
{
    use ApiResponse;

    protected FavoriteService $favoriteService;

    public function __construct(FavoriteService $favoriteService)
    {
        $this->favoriteService = $favoriteService;
    }

    public function toggleFavorite(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'moduleId' => 'required|integer',
            'module' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The provided data is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $validated = $validator->validated();

            $response = $this->favoriteService->toggleFavorite(
                $request->user()->id,
                $validated['moduleId'],
                $validated['module']
            );

            $params = [
                'action' => $response['isFavorite'] ? 'favorited' : 'unfavorited',
                'module' => Constants::MODULE_FAVORITE,
                'moduleId' => $validated['moduleId'],
                'description' => ($response['isFavorite'] ? 'Favorited' : 'Unfavorited')." {$validated['module']} #{$validated['moduleId']}",
            ];
            logUserActivity($params);

            if ($validated['module'] === Constants::MODULE_PROPERTY) {
                logPropertyHistory([
                    'propertyId' => $validated['moduleId'],
                    'field' => $response['isFavorite'] ? 'favorited' : 'unfavorited',
                    'newValue' => json_encode(['message' => $response['isFavorite'] ? 'Property favorited' : 'Property unfavorited']),
                ]);
            } elseif ($validated['module'] === Constants::MODULE_ENQUIRY) {
                logEnquiryHistory([
                    'enquiryId' => $validated['moduleId'],
                    'field' => $response['isFavorite'] ? 'favorited' : 'unfavorited',
                    'originalValue' => null,
                    'newValue' => json_encode(['message' => $response['isFavorite'] ? 'Enquiry favorited' : 'Enquiry unfavorited']),
                ]);
            }

            return response()->json([
                'message' => $response['message'],
                'isFavorite' => $response['isFavorite'],
            ], 200);
        } catch (\Exception $e) {
            return $this->exceptionResponse('FavoriteController : toggleFavorite', $e);
        }
    }

    public function deleteFavorite(string $ids, Request $request)
    {
        try {
            $idArray = array_filter(
                array_map('intval', explode(',', $ids))
            );

            $favorites = Favorite::whereIn('id', $idArray)->get();

            $deletedCount = $this->favoriteService->deleteMultiple(
                $idArray,
                $request->user()->id
            );

            $params = [
                'action' => 'deleted',
                'module' => Constants::MODULE_FAVORITE,
                'moduleId' => $idArray[0] ?? 0,
                'description' => "Deleted {$deletedCount} favorite record(s)",
            ];
            logUserActivity($params);

            $enquiryFavorites = $favorites->where('module', Constants::MODULE_ENQUIRY)->where('moduleId', '!=', null);
            $propertyFavorites = $favorites->where('module', Constants::MODULE_PROPERTY)->where('moduleId', '!=', null);

            if ($propertyFavorites->isNotEmpty()) {
                $propertyIds = $propertyFavorites->pluck('moduleId')->toArray();
                logPropertyHistory([
                    'propertyId' => $propertyIds[0],
                    'field' => 'unfavorited',
                    'newValue' => json_encode(['message' => 'Property unfavorited']),
                    'comments' => json_encode([
                        'action' => 'unfavorited',
                        'createdBy' => user()->name ?? 'System',
                        'items' => $propertyIds,
                    ]),
                ]);
            }

            if ($enquiryFavorites->isNotEmpty()) {
                $enquiryIds = $enquiryFavorites->pluck('moduleId')->toArray();
                logEnquiryHistory([
                    'enquiryId' => $enquiryIds[0],
                    'field' => 'unfavorited',
                    'originalValue' => json_encode(['message' => 'Favorites: #'.implode(', #', $enquiryFavorites->pluck('id')->toArray())]),
                    'newValue' => json_encode(['message' => 'Enquiry unfavorited']),
                    'comments' => json_encode(['enquiryIds' => $enquiryIds]),
                ]);
            }

            return response()->json([
                'message' => 'Favorite records deleted successfully.',
                'deleted' => $deletedCount,
            ]);
        } catch (\Exception $e) {
            return $this->exceptionResponse('FavoriteController : deleteFavorite', $e);
        }
    }

    public function getFavorite(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'module' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The provided data is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $validated = $validator->validated();

            $favorites = $this->favoriteService->getUserFavorites(
                $request->user()->id,
                $validated['module']
            );

            if ($favorites->isEmpty()) {
                return response()->json([
                    'message' => 'No favorite records were found.',
                    'data' => [],
                ], 200);
            }

            return response()->json([
                'message' => 'Favorite records retrieved successfully.',
                'data' => FavoriteResource::collection($favorites),
            ], 200);
        } catch (\Exception $e) {
            return $this->exceptionResponse('FavoriteController : getFavorite', $e);
        }
    }

    public function favoriteListings(Request $request)
    {
        try {

            $favorites = $this->favoriteService->favoriteListings($request->user()->id);

            if ($favorites->isEmpty()) {
                return response()->json([
                    'message' => 'No favorite records were found for this user.',
                    'data' => [],
                ], 200);
            }

            return response()->json([
                'message' => 'Favorite records retrieved successfully.',
                'data' => FavoriteResource::collection($favorites),
            ], 200);
        } catch (\Exception $e) {
            return $this->exceptionResponse('FavoriteController : favoriteListings', $e);
        }
    }

    public function favoriteCount(Request $request)
    {
        try {
            $count = $this->favoriteService->favoriteCount($request->user()->id);

            return response()->json([
                'message' => 'Favorite count retrieved successfully',
                'count' => $count,
            ], 200);
        } catch (\Exception $e) {
            return $this->exceptionResponse('FavoriteController : favoriteCount', $e);
        }
    }

    public function deleteAllFavoriteById($userId)
    {
        try {
            $favorites = Favorite::where('userId', $userId)->get();

            $deletedCount = $this->favoriteService->deleteAllFavoriteById($userId);

            if ($deletedCount === 0) {
                return response()->json([
                    'message' => 'No favorite records were found for this user.',
                ], 422);
            }

            $params = [
                'action' => 'deleted',
                'module' => Constants::MODULE_FAVORITE,
                'moduleId' => $userId,
                'description' => "Deleted all {$deletedCount} favorite record(s) for user #{$userId}",
            ];
            logUserActivity($params);

            $enquiryFavorites = $favorites->where('module', Constants::MODULE_ENQUIRY)->where('moduleId', '!=', null);
            $propertyFavorites = $favorites->where('module', Constants::MODULE_PROPERTY)->where('moduleId', '!=', null);

            if ($propertyFavorites->isNotEmpty()) {
                $propertyIds = $propertyFavorites->pluck('moduleId')->toArray();
                logPropertyHistory([
                    'propertyId' => $propertyIds[0],
                    'field' => 'unfavorited',
                    'newValue' => json_encode(['message' => 'Property unfavorited']),
                    'comments' => json_encode([
                        'action' => 'unfavorited',
                        'createdBy' => user()->name ?? 'System',
                        'items' => $propertyIds,
                    ]),
                ]);
            }

            if ($enquiryFavorites->isNotEmpty()) {
                $enquiryIds = $enquiryFavorites->pluck('moduleId')->toArray();
                logEnquiryHistory([
                    'enquiryId' => $enquiryIds[0],
                    'field' => 'unfavorited',
                    'originalValue' => json_encode(['message' => 'Favorites: #'.implode(', #', $enquiryFavorites->pluck('id')->toArray())]),
                    'newValue' => json_encode(['message' => 'Enquiry unfavorited']),
                    'comments' => json_encode(['enquiryIds' => $enquiryIds]),
                ]);
            }

            return response()->json([
                'message' => 'All favorite records were deleted successfully for the user.',
                'deleted' => $deletedCount,
            ], 200);
        } catch (\Exception $e) {
            return $this->exceptionResponse('FavoriteController : deleteAllFavoriteById', $e);
        }
    }
}
