<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PropertyLocationResource\GetAllPropertyLocationResource;
use App\Http\Resources\PropertyLocationResource\GetPropertyLocationResource;
use App\Http\Resources\PropertyLocationResource\PropertyLocationResource;
use App\Models\PropertyCommunity;
use App\Services\PropertyLocationService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PropertyLocationController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected PropertyLocationService $propertyLocationService
    ) {}

    public function getCities(Request $request)
    {
        try {
            $locations = $this->propertyLocationService->getAll($request);

            return $this->success(
                GetAllPropertyLocationResource::collection($locations),
                'Property locations retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyLocationController : getCities', $e);
        }
    }

    public function getChildLocations(Request $request)
    {
        $type = $request->input('filter.type', $request->input('type'));

        if (! $type) {
            if ($request->filled('subCommunityId')) {
                $type = 'TOWER';
            } elseif ($request->filled('communityId')) {
                $type = 'SUBCOMMUNITY';
            } elseif ($request->filled('city')) {
                $type = 'COMMUNITY';
            }
        }

        $typeUpper = strtoupper((string) $type);

        $parentIds = match ($typeUpper) {
            'COMMUNITY' => $this->parseIdList($request->input('city')),
            'SUBCOMMUNITY' => $this->parseIdList($request->input('communityId')),
            'BUILDINGTOWER', 'TOWER' => $this->parseIdList($request->input('subCommunityId'))
                ?: $this->parseIdList($request->input('communityId')),
            default => [],
        };

        if (empty($parentIds)) {
            return $this->error('Provide either city, communityId or subCommunityId.', 422);
        }

        try {
            $children = $this->propertyLocationService
                ->getChildLocations($parentIds, $typeUpper, $request);

            return $this->success(
                PropertyLocationResource::collection($children),
                'Child locations retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyLocationController : getChildLocations', $e);
        }
    }

    private function parseIdList($value): array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif ($value === null || $value === '') {
            return [];
        } else {
            $items = explode(',', (string) $value);
        }

        $ids = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item === '' || ! ctype_digit($item)) {
                continue;
            }
            $ids[] = (int) $item;
        }

        return array_values(array_unique($ids));
    }

    public function addPropertyLocation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'locationId' => 'required|string',
            'name' => 'required|string',
            'type' => 'required|in:city',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $location = $this->propertyLocationService->create($validator->validated());

            $params = [
                'action' => 'created',
                'module' => 'property_location',
                'moduleId' => $location->id,
                'description' => "Created property location: {$location->name}",
            ];
            logUserActivity($params);

            return $this->success(
                new GetPropertyLocationResource($location),
                'Property location created successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyLocationController : addPropertyLocation', $e);
        }
    }

    public function getPropertyLocationById(int $id)
    {
        try {
            $location = $this->propertyLocationService->getById($id);

            if (! $location) {
                return $this->error('The requested property location was not found.', 422);
            }

            return $this->success(
                new GetPropertyLocationResource($location),
                'Property location retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyLocationController : getPropertyLocationById', $e);
        }
    }

    public function updatePropertyLocation(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'locationId' => 'sometimes|required|string',
            'name' => 'sometimes|required|string',
            'type' => 'sometimes|required|in:city',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $location = $this->propertyLocationService->update($id, $validator->validated());

            if (! $location) {
                return $this->error('The requested property location was not found.', 422);
            }

            $params = [
                'action' => 'updated',
                'module' => 'property_location',
                'moduleId' => $location->id,
                'description' => "Updated property location: {$location->name}",
            ];
            logUserActivity($params);

            return $this->success(
                new GetPropertyLocationResource($location),
                'Property location updated successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyLocationController : updatePropertyLocation', $e);
        }
    }

    public function deletePropertyLocation(int $id)
    {
        try {
            $location = $this->propertyLocationService->getById($id);

            if (! $location) {
                return $this->error('The requested property location was not found.', 422);
            }

            $locationName = $location->name;
            $this->propertyLocationService->delete($id);

            $params = [
                'action' => 'deleted',
                'module' => 'property_location',
                'moduleId' => $id,
                'description' => "Deleted property location: {$locationName}",
            ];
            logUserActivity($params);

            return $this->success([], 'Property location deleted successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyLocationController : deletePropertyLocation', $e);
        }
    }

    public function updateCommunityCode(Request $request, $communityId)
    {
        $validator = Validator::make($request->all(), [
            'communityCode' => 'required|unique:property_communities,communityCode',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $community = PropertyCommunity::where('communityId', $communityId)->first();

            if (! $community) {
                return response()->json([
                    'status' => false,
                    'message' => 'Community not found.',
                    'errors' => [],
                ], 422);
            }

            $community->update([
                'communityCode' => $request->communityCode,
            ]);

            $params = [
                'action' => 'updated',
                'module' => 'property_location',
                'moduleId' => $community->id,
                'description' => "Updated community code for: {$community->name}",
            ];
            logUserActivity($params);

            return response()->json([
                'status' => true,
                'message' => 'Community code updated successfully.',
                'data' => $community,
            ]);
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyLocationController : updateCommunityCode', $e);
        }
    }

    public function selectCommunitiesByCity($cityId = null)
    {
        try {
            $communities = $this->propertyLocationService->selectCommunitiesByCity($cityId);

            return $this->success(
                $communities,
                'Communities retrieved successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyLocationController : selectCommunitiesByCity', $e);
        }
    }

    public function selectSubCommunitiesByCommunity($communityId)
    {
        try {
            $subCommunities = $this->propertyLocationService->selectSubCommunitiesByCommunity($communityId);

            return $this->success(
                $subCommunities,
                'Sub-communities retrieved successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyLocationController : selectSubCommunitiesByCommunity', $e);
        }
    }

    public function selectBuildingsBySubCommunity($subCommunityId)
    {
        try {
            $buildings = $this->propertyLocationService->selectBuildingsBySubCommunity($subCommunityId);

            return $this->success(
                $buildings,
                'Buildings retrieved successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyLocationController : selectBuildingsBySubCommunity', $e);
        }
    }

    public function getCommunitiesByCity(Request $request, $cityId)
    {
        try {
            $communities = $this->propertyLocationService->getCommunitiesByCity($cityId, $request);

            return $this->success(
                $communities,
                'Property communities retrieved successfully.'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyLocationController : getCommunitiesByCity', $e);
        }
    }

    public function updatePropertyCommunity(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'cityId' => 'sometimes|required|integer|exists:pf_location_cities,id',
            'communityId' => 'sometimes|required',
            'name' => 'sometimes|required',
            'communityCode' => 'nullable',
            'type' => 'sometimes|required|string|in:community',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed.', 422, $validator->errors());
        }

        try {
            $community = $this->propertyLocationService->updateCommunity($id, $request->all());

            $params = [
                'action' => 'updated',
                'module' => 'property_location',
                'moduleId' => $id,
                'description' => "Updated property community: #{$id}",
            ];
            logUserActivity($params);

            return $this->success($community, 'Property community updated successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyLocationController : updatePropertyCommunity', $e);
        }
    }
}
