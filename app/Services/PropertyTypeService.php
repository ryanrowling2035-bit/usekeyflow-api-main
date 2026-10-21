<?php

namespace App\Services;

use App\Repositories\PropertyTypeRepository;
use Illuminate\Http\Request;

class PropertyTypeService
{
    public function __construct(
        protected PropertyTypeRepository $propertyTypeRepository
    ) {}

    public function getAll(Request $request)
    {
        return $this->propertyTypeRepository->getAll($request);
    }
}
