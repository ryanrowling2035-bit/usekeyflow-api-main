<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Http\Resources\PropertyCategoryResource\PropertyCategoryResource;
use App\Services\PropertyCategoryService;
use App\Traits\ApiResponse;
use App\Traits\WordFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PropertyCategoryController extends Controller
{
    use ApiResponse,WordFormatter;

    public function __construct(
        protected PropertyCategoryService $propertyCategoryService
    ) {}

    public function getStatus()
    {
        try {
            return $this->success([
                'status' => $this->toValueLabelArray(Constants::USER_STATUS),
            ]);
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyCategoryController : getStatus', $e);
        }
    }

    public function propertyCategories(Request $request)
    {
        try {
            $categories = $this->propertyCategoryService->getAll($request);

            return $this->success(
                PropertyCategoryResource::collection($categories),
                'Property categories retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyCategoryController : propertyCategories', $e);
        }
    }

    public function addPropertyCategory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:property_categories,name',
            'description' => 'nullable',
            'status' => 'in:'.implode(',', array_keys(Constants::USER_STATUS)),
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $category = $this->propertyCategoryService->create($validator->validated());

            $params = [
                'action' => 'created',
                'module' => 'property_category',
                'moduleId' => $category->id,
                'description' => "Created property category: {$category->name}",
            ];
            logUserActivity($params);

            return $this->success(
                new PropertyCategoryResource($category),
                'Property category created successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyCategoryController : addPropertyCategory', $e);
        }
    }

    public function getPropertyCategoryById(int $id)
    {
        try {
            $category = $this->propertyCategoryService->getById($id);

            if (! $category) {
                return $this->error('The requested property category was not found.', 422);
            }

            return $this->success(
                new PropertyCategoryResource($category),
                'Property category retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyCategoryController : getPropertyCategoryById', $e);
        }
    }

    public function updatePropertyCategory(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|unique:property_categories,name,'.$id,
            'description' => 'nullable',
            'status' => 'in:'.implode(',', array_keys(Constants::USER_STATUS)),
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $category = $this->propertyCategoryService->update($id, $validator->validated());

            if (! $category) {
                return $this->error('The requested property category was not found.', 422);
            }

            $params = [
                'action' => 'updated',
                'module' => 'property_category',
                'moduleId' => $category->id,
                'description' => "Updated property category: {$category->name}",
            ];
            logUserActivity($params);

            return $this->success(
                new PropertyCategoryResource($category),
                'Property category updated successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyCategoryController : updatePropertyCategory', $e);
        }
    }

    public function deletePropertyCategory(int $id)
    {
        try {
            $category = $this->propertyCategoryService->getById($id);

            if (! $category) {
                return $this->error('The requested property category was not found.', 422);
            }

            $categoryName = $category->name;
            $this->propertyCategoryService->delete($id);

            $params = [
                'action' => 'deleted',
                'module' => 'property_category',
                'moduleId' => $id,
                'description' => "Deleted property category: {$categoryName}",
            ];
            logUserActivity($params);

            return $this->success([], 'Property category deleted successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('PropertyCategoryController : deletePropertyCategory', $e);
        }
    }
}
