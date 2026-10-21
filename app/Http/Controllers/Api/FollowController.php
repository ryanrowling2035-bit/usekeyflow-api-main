<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Http\Resources\FollowResources\FollowResource;
use App\Models\Follow;
use App\Services\FollowService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FollowController extends Controller
{
    use ApiResponse;

    protected FollowService $followService;

    public function __construct(FollowService $followService)
    {
        $this->followService = $followService;
    }

    public function addFollow(Request $request)
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

            $response = $this->followService->add([
                'userId' => $request->user()->id,
                'moduleId' => $validated['moduleId'],
                'module' => $validated['module'],
            ]);

            if (! $response['success']) {
                return response()->json([
                    'message' => $response['message'],
                ], 409);
            }

            $params = [
                'action' => 'followed',
                'module' => Constants::MODULE_FOLLOW,
                'moduleId' => $response['data']->id,
                'description' => "Followed {$validated['module']} #{$validated['moduleId']}",
            ];
            logUserActivity($params);

            if ($validated['module'] === Constants::MODULE_PROPERTY) {
                logPropertyHistory([
                    'propertyId' => $validated['moduleId'],
                    'field' => 'followed',
                    'newValue' => json_encode(['message' => 'Property followed']),
                ]);
            } elseif ($validated['module'] === Constants::MODULE_ENQUIRY) {
                logEnquiryHistory([
                    'enquiryId' => $validated['moduleId'],
                    'field' => 'followed',
                    'originalValue' => null,
                    'newValue' => json_encode(['message' => 'Enquiry followed']),
                ]);
            }

            return response()->json([
                'message' => $response['message'],
                'data' => new FollowResource($response['data']),
            ]);
        } catch (\Exception $e) {
            return $this->exceptionResponse('FollowController : addFollow', $e);
        }
    }

    public function toggleFollow(Request $request)
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

            $response = $this->followService->toggleFollow(
                $request->user()->id,
                $validated['moduleId'],
                $validated['module']
            );

            $params = [
                'action' => $response['isFollowing'] ? 'followed' : 'unfollowed',
                'module' => Constants::MODULE_FOLLOW,
                'moduleId' => $validated['moduleId'],
                'description' => ($response['isFollowing'] ? 'Followed' : 'Unfollowed')." {$validated['module']} #{$validated['moduleId']}",
            ];
            logUserActivity($params);

            if ($validated['module'] === Constants::MODULE_PROPERTY) {
                logPropertyHistory([
                    'propertyId' => $validated['moduleId'],
                    'field' => $response['isFollowing'] ? 'followed' : 'unfollowed',
                    'newValue' => json_encode(['message' => $response['isFollowing'] ? 'Property followed' : 'Property unfollowed']),
                ]);
            } elseif ($validated['module'] === Constants::MODULE_ENQUIRY) {
                logEnquiryHistory([
                    'enquiryId' => $validated['moduleId'],
                    'field' => $response['isFollowing'] ? 'followed' : 'unfollowed',
                    'originalValue' => null,
                    'newValue' => json_encode(['message' => $response['isFollowing'] ? 'Enquiry followed' : 'Enquiry unfollowed']),
                ]);
            }

            return response()->json([
                'message' => $response['message'],
                'isFollowing' => $response['isFollowing'],
            ], 200);
        } catch (\Exception $e) {
            return $this->exceptionResponse('FollowController : toggleFollow', $e);
        }
    }

    public function deleteFollow(string $ids, Request $request)
    {
        try {
            $idArray = array_filter(
                array_map('intval', explode(',', $ids))
            );

            if (empty($idArray)) {
                return response()->json([
                    'message' => 'One or more follow IDs are invalid.',
                ], 422);
            }

            $follows = Follow::whereIn('id', $idArray)->get();

            $deletedCount = $this->followService->deleteMultiple(
                $idArray,
                $request->user()->id
            );

            if ($deletedCount === 0) {
                return response()->json([
                    'message' => 'The requested follow record was not found.',
                ], 422);
            }

            $params = [
                'action' => 'deleted',
                'module' => Constants::MODULE_FOLLOW,
                'moduleId' => $idArray[0] ?? 0,
                'description' => "Deleted {$deletedCount} follow record(s)",
            ];
            logUserActivity($params);

            $enquiryFollows = $follows->where('module', Constants::MODULE_ENQUIRY)->where('moduleId', '!=', null);
            $propertyFollows = $follows->where('module', Constants::MODULE_PROPERTY)->where('moduleId', '!=', null);

            if ($propertyFollows->isNotEmpty()) {
                $propertyIds = $propertyFollows->pluck('moduleId')->toArray();
                logPropertyHistory([
                    'propertyId' => $propertyIds[0],
                    'field' => 'unfollowed',
                    'newValue' => json_encode(['message' => 'Property unfollowed']),
                    'comments' => json_encode([
                        'action' => 'unfollowed',
                        'createdBy' => user()->name ?? 'System',
                        'items' => $propertyIds,
                    ]),
                ]);
            }

            if ($enquiryFollows->isNotEmpty()) {
                $enquiryIds = $enquiryFollows->pluck('moduleId')->toArray();
                logEnquiryHistory([
                    'enquiryId' => $enquiryIds[0],
                    'field' => 'unfollowed',
                    'originalValue' => json_encode(['message' => 'Follows: #'.implode(', #', $enquiryFollows->pluck('id')->toArray())]),
                    'newValue' => json_encode(['message' => 'Enquiry unfollowed']),
                    'comments' => json_encode(['enquiryIds' => $enquiryIds]),
                ]);
            }

            return response()->json([
                'message' => 'Follow record deleted successfully.',
                'deleted' => $deletedCount,
            ], 200);

        } catch (\Exception $e) {
            return $this->exceptionResponse('FollowController : deleteFollow', $e);
        }
    }

    public function getFollow(Request $request)
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

            $follows = $this->followService->getUserFollows(
                $request->user()->id,
                $validated['module']
            );

            if ($follows->isEmpty()) {
                return response()->json([
                    'message' => 'No follow records were found.',
                    'data' => [],
                ], 200);
            }

            return response()->json([
                'message' => 'Follow records retrieved successfully.',
                'data' => FollowResource::collection($follows),
            ], 200);
        } catch (\Exception $e) {
            return $this->exceptionResponse('FollowController : getFollow', $e);
        }
    }

    public function followingListings(Request $request)
    {
        try {
            $follows = $this->followService->followingListings($request->user()->id);

            if ($follows->isEmpty()) {
                return response()->json([
                    'message' => 'No follow records were found for this user.',
                    'data' => [],
                ], 200);
            }

            return response()->json([
                'message' => 'Follow records retrieved successfully.',
                'data' => FollowResource::collection($follows),
            ], 200);
        } catch (\Exception $e) {
            return $this->exceptionResponse('FollowController : followingListings', $e);
        }
    }

    public function followingCount(Request $request)
    {
        try {
            $count = $this->followService->followingCount($request->user()->id);

            return response()->json([
                'message' => 'Following count retrieved successfully',
                'count' => $count,
            ], 200);
        } catch (\Exception $e) {
            return $this->exceptionResponse('FollowController : followingCount', $e);
        }
    }

    public function deleteAllFollowsById($userId)
    {
        try {
            $follows = Follow::where('userId', $userId)->get();

            $deletedCount = $this->followService->deleteAllFollowsById($userId);

            if ($deletedCount === 0) {
                return response()->json([
                    'message' => 'No follow records were found for this user.',
                ], 422);
            }

            $params = [
                'action' => 'deleted',
                'module' => Constants::MODULE_FOLLOW,
                'moduleId' => $userId,
                'description' => "Deleted all {$deletedCount} follow record(s) for user #{$userId}",
            ];
            logUserActivity($params);

            $enquiryFollows = $follows->where('module', Constants::MODULE_ENQUIRY)->where('moduleId', '!=', null);
            $propertyFollows = $follows->where('module', Constants::MODULE_PROPERTY)->where('moduleId', '!=', null);

            if ($propertyFollows->isNotEmpty()) {
                $propertyIds = $propertyFollows->pluck('moduleId')->toArray();
                logPropertyHistory([
                    'propertyId' => $propertyIds[0],
                    'field' => 'unfollowed',
                    'newValue' => json_encode(['message' => 'Property unfollowed']),
                    'comments' => json_encode([
                        'action' => 'unfollowed',
                        'createdBy' => user()->name ?? 'System',
                        'items' => $propertyIds,
                    ]),
                ]);
            }

            if ($enquiryFollows->isNotEmpty()) {
                $enquiryIds = $enquiryFollows->pluck('moduleId')->toArray();
                logEnquiryHistory([
                    'enquiryId' => $enquiryIds[0],
                    'field' => 'unfollowed',
                    'originalValue' => json_encode(['message' => 'Follows: #'.implode(', #', $enquiryFollows->pluck('id')->toArray())]),
                    'newValue' => json_encode(['message' => 'Enquiry unfollowed']),
                    'comments' => json_encode(['enquiryIds' => $enquiryIds]),
                ]);
            }

            return response()->json([
                'message' => 'All follow records were deleted successfully for the user.',
                'deleted' => $deletedCount,
            ], 200);
        } catch (\Exception $e) {
            return $this->exceptionResponse('FollowController : deleteAllFollowsById', $e);
        }
    }
}
