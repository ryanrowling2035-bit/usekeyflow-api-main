<?php

namespace App\Repositories;

use App\Models\PropertyCommunity;
use App\Models\PropertyLocation;
use App\Models\PropertySubCommunity;
use App\Models\PropertyTower;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PropertyLocationRepository
{
    public function getAll(Request $request)
    {

        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        $query = PropertyLocation::query();

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('locationId', 'LIKE', "%{$search}%")
                    ->orWhere('latitude', 'LIKE', "%{$search}%")
                    ->orWhere('longitude', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('id')) {
            $query->orderByRaw(
                'CASE WHEN id = ? THEN 0 ELSE 1 END',
                [$request->id]
            );
        }

        $query->orderBy('name', 'asc');

        return $query->paginate($perPage);
    }

    public function getByType(string $type)
    {
        return PropertyLocation::where('type', $type)
            ->orderBy('name', 'asc')
            ->get();
    }

    public function getChildLocations(array $parentIds, ?string $type, Request $request)
    {
        $typeUpper = strtoupper((string) $type);

        $parentIds = array_values(array_unique(array_filter(array_map('intval', $parentIds))));

        if (empty($parentIds)) {
            return collect();
        }

        if ($request->boolean('localSearch')) {
            return $this->getChildLocationsFromLocalDb($parentIds, $typeUpper, $request);
        }

        return match ($typeUpper) {
            'COMMUNITY' => $this->getCommunitiesForCities($parentIds, $request),
            'SUBCOMMUNITY' => $this->getSubCommunitiesForCommunities($parentIds, $request),
            'BUILDINGTOWER', 'TOWER' => $this->getTowersForSubCommunities($parentIds, $request),
            default => collect(),
        };
    }

    private function getChildLocationsFromLocalDb(array $parentIds, string $typeUpper, Request $request)
    {
        $query = match ($typeUpper) {
            'COMMUNITY' => PropertyCommunity::whereIn('cityId', $parentIds),
            'SUBCOMMUNITY' => PropertySubCommunity::whereIn('communityId', $parentIds),
            'BUILDINGTOWER', 'TOWER' => PropertyTower::whereIn('subCommunityId', $parentIds),
            default => null,
        };

        if ($query === null) {
            return collect();
        }

        $this->applySearchFilter($query, $request);

        return $query->orderBy('name', 'asc')->get();
    }

    private function applySearchFilter($query, Request $request): void
    {
        if (! $request->filled('search')) {
            return;
        }

        $search = trim((string) $request->search);

        $query->whereRaw(
            'LOWER(TRIM(name)) LIKE ?',
            ['%'.strtolower($search).'%']
        );
    }

    private function getCommunitiesForCities(array $cityExternalIds, Request $request)
    {
        return $this->refreshExternalLocations($cityExternalIds, 'COMMUNITY', $request);
    }

    private function getSubCommunitiesForCommunities(array $communityExternalIds, Request $request)
    {
        return $this->refreshExternalLocations($communityExternalIds, 'SUBCOMMUNITY', $request);
    }

    private function getTowersForSubCommunities(array $subCommunityExternalIds, Request $request)
    {
        return $this->refreshExternalLocations($subCommunityExternalIds, 'TOWER', $request);
    }

    private function refreshExternalLocations(array $parentExternalIds, string $type, Request $request)
    {
        $parent = trim((string) $request->input('parent', ''));
        $shapedLocations = [];

        DB::beginTransaction();

        try {
            foreach ($parentExternalIds as $parentId) {
                $externalData = $this->fetchFromExternalApi(
                    $request->search ?? '',
                    $type,
                    $parentId,
                    $parent !== '' ? $parent : null
                );

                if (empty($externalData)) {
                    continue;
                }

                $this->storeExternalLocations($externalData);

                foreach ($externalData as $location) {
                    $shaped = $this->shapeExternalLocation($location);
                    if ($shaped !== null) {
                        $shapedLocations[] = $shaped;
                    }
                }
            }

            DB::commit();

            $shapedLocations = $this->hydrateCommunityCodes($shapedLocations);

            return collect($shapedLocations)
                ->sortBy(fn ($l) => strtolower((string) ($l['name'] ?? '')))
                ->values();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function hydrateCommunityCodes(array $shapedLocations): array
    {
        $communityIds = [];
        foreach ($shapedLocations as $loc) {
            if (($loc['type'] ?? null) === 'community' && ! empty($loc['communityId'])) {
                $communityIds[] = $loc['communityId'];
            }
        }

        if (empty($communityIds)) {
            return $shapedLocations;
        }

        $codes = PropertyCommunity::whereIn('communityId', $communityIds)
            ->pluck('communityCode', 'communityId');

        foreach ($shapedLocations as &$loc) {
            if (($loc['type'] ?? null) === 'community' && isset($codes[$loc['communityId']])) {
                $loc['communityCode'] = $codes[$loc['communityId']];
            }
        }

        return $shapedLocations;
    }

    private function shapeExternalLocation(array $location): ?array
    {
        $mappedType = $this->mapLocationType($location['type'] ?? '');
        if (! $mappedType) {
            return null;
        }

        $tree = $location['tree'] ?? [];

        $findNode = static function (array $tree, string $type): ?array {
            foreach ($tree as $node) {
                if (strtoupper($node['type'] ?? '') === strtoupper($type)) {
                    return $node;
                }
            }

            return null;
        };

        $id = $location['id'] ?? null;
        $name = $location['name'] ?? null;
        $latitude = $location['coordinates']['lat'] ?? null;
        $longitude = $location['coordinates']['lng'] ?? null;

        $cityNode = $findNode($tree, 'CITY');
        $communityNode = $findNode($tree, 'COMMUNITY');
        $subCommunityNode = $findNode($tree, 'SUBCOMMUNITY');

        return match ($mappedType) {
            'community' => [
                'cityId' => $cityNode['id'] ?? null,
                'communityId' => $id,
                'name' => $name,
                'type' => 'community',
                'communityCode' => $location['communityCode'] ?? null,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
            'subCommunity' => [
                'communityId' => $communityNode['id'] ?? null,
                'subCommunityId' => $id,
                'name' => $name,
                'type' => 'subCommunity',
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
            'buildingTower' => [
                'communityId' => $communityNode['id'] ?? null,
                'subCommunityId' => $subCommunityNode['id'] ?? null,
                'towerId' => $id,
                'name' => $name,
                'type' => 'tower',
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
            default => null,
        };
    }

    private function fetchFromExternalApi(string $search, ?string $type, int $parentExternalId, ?string $parent = null): array
    {
        $token = $this->getAccessToken();

        if (! $token) {
            return [];
        }

        $params = [
            'search' => $search ?: '',
            'filter[type]' => strtoupper($type ?? 'SUBCOMMUNITY'),
        ];

        $parentHierarchy = $parent ?: $this->buildParentHierarchy($parentExternalId, $type);

        if ($parentHierarchy) {
            $params['filter[parent]'] = $parentHierarchy;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->get(env('PROPERTY_FINDER_API_URL').'/locations', $params);

        if ($response->successful()) {
            return $response->json()['data'] ?? [];
        }

        Log::error('External API failed', [
            'response' => $response->body(),
        ]);

        return [];
    }

    private function buildParentHierarchy(int $externalId, ?string $type): ?string
    {
        switch (strtoupper($type)) {

            case 'SUBCOMMUNITY':
                $community = PropertyCommunity::where('communityId', $externalId)->first();
                if (! $community) {
                    return null;
                }

                $city = PropertyLocation::where('locationId', $community->cityId)->first();

                return implode(', ', array_filter([
                    ucfirst($city?->name),
                    $community->name,
                ]));

            case 'TOWER':
            case 'BUILDINGTOWER':

                $subCommunity = PropertySubCommunity::where('propertySubCommunityId', $externalId)->first();

                // Towers can be searched without a sub-community; fall back to
                // treating the external id as a community id for the hierarchy.
                if (! $subCommunity) {
                    $community = PropertyCommunity::where('communityId', $externalId)->first();
                    if (! $community) {
                        return null;
                    }

                    $city = PropertyLocation::where('locationId', $community->cityId)->first();

                    return implode(', ', array_filter([
                        ucfirst($city?->name),
                        $community->name,
                    ]));
                }

                $community = PropertyCommunity::where('communityId', $subCommunity->communityId)->first();
                $city = PropertyLocation::where('locationId', $community?->cityId)->first();

                return implode(', ', array_filter([
                    ucfirst($city?->name),
                    $community?->name,
                    $subCommunity->name,
                ]));

            default:
                return null;
        }
    }

    private function getAccessToken(): ?string
    {

        $response = Http::post(
            env('PROPERTY_FINDER_AUTH_URL'),
            [
                'apiKey' => env('PROPERTY_FINDER_API_KEY'),
                'apiSecret' => env('PROPERTY_FINDER_API_SECRET'),
            ]
        );

        if ($response->successful()) {
            return $response->json()['accessToken'] ?? null;
        }

        return null;
    }

    private function storeExternalLocations(array $locations): void
    {
        $communities = [];
        $subCommunities = [];
        $towers = [];

        $findNode = static function (array $tree, string $type): ?array {
            foreach ($tree as $node) {
                if (strtoupper($node['type'] ?? '') === strtoupper($type)) {
                    return $node;
                }
            }

            return null;
        };

        $now = now();

        foreach ($locations as $location) {

            $mappedType = $this->mapLocationType($location['type'] ?? '');
            if (! $mappedType) {
                continue;
            }

            $tree = $location['tree'] ?? [];

            $cityNode = $findNode($tree, 'CITY');
            $communityNode = $findNode($tree, 'COMMUNITY');
            $subCommunityNode = $findNode($tree, 'SUBCOMMUNITY');

            if ($mappedType === 'community') {

                $communities[] = [
                    'communityId' => $location['id'],
                    'cityId' => $cityNode['id'] ?? null,
                    'name' => $location['name'] ?? null,
                    'type' => 'community',
                    'communityCode' => $location['communityCode'] ?? null,
                    'latitude' => $location['coordinates']['lat'] ?? null,
                    'longitude' => $location['coordinates']['lng'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            } elseif ($mappedType === 'subCommunity') {

                $subCommunities[] = [
                    'propertySubCommunityId' => $location['id'],
                    'communityId' => $communityNode['id'] ?? null,
                    'name' => $location['name'] ?? null,
                    'type' => 'subCommunity',
                    'latitude' => $location['coordinates']['lat'] ?? null,
                    'longitude' => $location['coordinates']['lng'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            } elseif ($mappedType === 'buildingTower') {

                $towers[] = [
                    'propertyTowerId' => $location['id'],
                    'communityId' => $communityNode['id'] ?? null,
                    'subCommunityId' => $subCommunityNode['id'] ?? null,
                    'name' => $location['name'] ?? null,
                    'type' => 'tower',
                    'latitude' => $location['coordinates']['lat'] ?? null,
                    'longitude' => $location['coordinates']['lng'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (! empty($communities)) {
            PropertyCommunity::insertOrIgnore($communities);
        }

        if (! empty($subCommunities)) {
            PropertySubCommunity::insertOrIgnore($subCommunities);
        }

        if (! empty($towers)) {
            PropertyTower::insertOrIgnore($towers);
        }

        Log::info('Insert-or-ignore complete', [
            'communities' => count($communities),
            'subCommunities' => count($subCommunities),
            'towers' => count($towers),
        ]);
    }

    private function mapLocationType(string $externalType): ?string
    {
        return match (strtoupper($externalType)) {
            'CITY' => 'city',
            'COMMUNITY' => 'community',
            'SUBCOMMUNITY' => 'subCommunity',
            'TOWER' => 'buildingTower',
            default => null,
        };
    }

    public function create(array $data)
    {
        return PropertyLocation::create($data);
    }

    public function findById(int $id)
    {
        return PropertyLocation::find($id);
    }

    public function update(int $id, array $data)
    {
        $location = $this->findById($id);
        if (! $location) {
            return null;
        }
        $location->update($data);

        return $location;
    }

    public function delete(int $id): bool
    {
        $location = $this->findById($id);

        return (bool) $location->delete();
    }

    public function getCommunitiesByCity(int $cityId, Request $request)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        $query = PropertyCommunity::with('location')
            ->where('cityId', $cityId);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('communityId', 'LIKE', "%{$search}%")
                    ->orWhere('communityCode', 'LIKE', "%{$search}%")
                    ->orWhere('latitude', 'LIKE', "%{$search}%")
                    ->orWhere('longitude', 'LIKE', "%{$search}%")
                    ->orWhereHas('location', function ($cityQuery) use ($search) {
                        $cityQuery->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        if ($request->filled('id')) {
            $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$request->id]);
        }

        $query->orderBy('name', 'asc');

        return $query->paginate($perPage);
    }

    public function selectCommunitiesByCity(?int $cityId = null)
    {
        return PropertyCommunity::when($cityId, fn ($q) => $q->where('cityId', $cityId))
            ->select('communityId as id', 'name', 'communityCode as code')
            ->orderBy('name', 'asc')
            ->get();
    }

    public function selectSubCommunitiesByCommunity(int $communityId)
    {
        return PropertySubCommunity::where('communityId', $communityId)
            ->select('propertySubCommunityId as id', 'name')
            ->orderBy('name', 'asc')
            ->get();
    }

    public function selectBuildingsBySubCommunity(int $subCommunityId)
    {
        return PropertyTower::where('subCommunityId', $subCommunityId)
            ->select('id', 'name')
            ->orderBy('name', 'asc')
            ->get();
    }

    public function updateCommunity(int $id, array $data)
    {
        $community = PropertyCommunity::findOrFail($id);
        $community->update([
            'cityId' => $data['cityId'],
            'communityId' => $data['communityId'],
            'name' => $data['name'],
            'communityCode' => $data['communityCode'] ?? null,
            'type' => $data['type'],
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
        ]);

        return $community;
    }
}
