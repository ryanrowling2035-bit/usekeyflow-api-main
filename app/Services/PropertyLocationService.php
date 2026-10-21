<?php

namespace App\Services;

use App\Repositories\PropertyLocationRepository;
use Illuminate\Http\Request;

class PropertyLocationService
{
    protected PropertyLocationRepository $propertyLocationRepository;

    public function __construct(PropertyLocationRepository $propertyLocationRepository)
    {
        $this->propertyLocationRepository = $propertyLocationRepository;
    }

    public function getAll(Request $request)
    {

        return $this->propertyLocationRepository->getAll($request);
    }

    public function getByType(string $type)
    {
        return $this->propertyLocationRepository->getByType($type);
    }

    public function getChildLocations(array $parentIds, ?string $type, Request $request)
    {
        return $this->propertyLocationRepository->getChildLocations($parentIds, $type, $request);
    }

    public function create(array $data)
    {
        return $this->propertyLocationRepository->create($data);
    }

    public function getById(int $id)
    {
        return $this->propertyLocationRepository->findById($id);
    }

    public function update(int $id, array $data)
    {
        return $this->propertyLocationRepository->update($id, $data);
    }

    public function delete(int $id)
    {
        return $this->propertyLocationRepository->delete($id);
    }

    public function getCommunitiesByCity(int $cityId, Request $request)
    {
        return $this->propertyLocationRepository->getCommunitiesByCity($cityId, $request);
    }

    public function selectCommunitiesByCity(?int $cityId = null)
    {
        return $this->propertyLocationRepository->selectCommunitiesByCity($cityId);
    }

    public function selectSubCommunitiesByCommunity(int $communityId)
    {
        return $this->propertyLocationRepository->selectSubCommunitiesByCommunity($communityId);
    }

    public function selectBuildingsBySubCommunity(int $subCommunityId)
    {
        return $this->propertyLocationRepository->selectBuildingsBySubCommunity($subCommunityId);
    }

    public function updateCommunity(int $id, array $data)
    {
        return $this->propertyLocationRepository->updateCommunity($id, $data);
    }
}
