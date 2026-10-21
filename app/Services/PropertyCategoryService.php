<?php

namespace App\Services;

use App\Repositories\PropertyCategoryRepository;
use Illuminate\Http\Request;

class PropertyCategoryService
{
    protected PropertyCategoryRepository $propertyCategoryRepository;

    public function __construct(PropertyCategoryRepository $propertyCategoryRepository)
    {
        $this->propertyCategoryRepository = $propertyCategoryRepository;
    }

    public function getAll(Request $request)
    {
        return $this->propertyCategoryRepository->getAll($request);
    }

    public function create(array $data)
    {
        return $this->propertyCategoryRepository->create($data);
    }

    public function getById(int $id)
    {
        return $this->propertyCategoryRepository->findById($id);
    }

    public function update(int $id, array $data)
    {
        return $this->propertyCategoryRepository->update($id, $data);
    }

    public function delete(int $id)
    {
        return $this->propertyCategoryRepository->delete($id);
    }
}
