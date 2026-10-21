<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PropertyTypeService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class PropertyTypeController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected PropertyTypeService $propertyTypeService
    ) {}

    public function getPropertyTypes(Request $request)
    {
        try {
            $propertyTypes = $this->propertyTypeService->getAll($request);

            return $this->success($propertyTypes, 'Property types retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyTypeController : getPropertyTypes', $e);
        }
    }
}
