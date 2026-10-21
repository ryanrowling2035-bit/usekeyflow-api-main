<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Services\MarketingAssetCategoryService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MarketingAssetCategoryController extends Controller
{
    use ApiResponse;

    public function __construct(
        private MarketingAssetCategoryService $categoryService,
    ) {}

    /** GET /marketing/categories/get */
    public function index(Request $request)
    {
        try {
            $categories = $this->categoryService->list($request);

            return $this->success($categories, 'Marketing asset categories retrieved successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('MarketingAssetCategoryController : index', $e);
        }
    }

    /** GET /marketing/categories/select — active categories only, for dropdowns. */
    public function select()
    {
        try {
            return $this->success(
                $this->categoryService->select(),
                'Active marketing asset categories retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('MarketingAssetCategoryController : select', $e);
        }
    }

    /** POST /marketing/categories/add */
    public function store(Request $request)
    {
        if (isAgent()) {
            return $this->error('Agents are not allowed to manage marketing asset categories.', 403);
        }

        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $data = $validator->validated();
            $data['status'] = $data['status'] ?? 'active';
            $data['createdBy'] = user()?->id;

            $category = $this->categoryService->store($data);

            return $this->success($category, 'Marketing asset category created successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('MarketingAssetCategoryController : store', $e);
        }
    }

    /** PUT /marketing/categories/update/{id} */
    public function update(Request $request, string $id)
    {
        if (isAgent()) {
            return $this->error('Agents are not allowed to manage marketing asset categories.', 403);
        }

        if (! $this->categoryService->getById((int) $id)) {
            return $this->error('Marketing asset category not found.', 404);
        }

        $validator = Validator::make($request->all(), $this->rules((int) $id));

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $category = $this->categoryService->update((int) $id, $validator->validated());

            return $this->success($category, 'Marketing asset category updated successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('MarketingAssetCategoryController : update', $e);
        }
    }

    /** DELETE /marketing/categories/delete/{id} */
    public function destroy(string $id)
    {
        if (isAgent()) {
            return $this->error('Agents are not allowed to manage marketing asset categories.', 403);
        }

        try {
            if (! $this->categoryService->delete((int) $id)) {
                return $this->error('Marketing asset category not found.', 404);
            }

            return $this->success(null, 'Marketing asset category deleted successfully');
        } catch (\Exception $e) {
            return $this->exceptionResponse('MarketingAssetCategoryController : destroy', $e);
        }
    }

    /**
     * Shared validation. Name is unique among non-deleted rows (ignoring the row
     * being updated).
     *
     * @return array<string, mixed>
     */
    private function rules(?int $ignoreId = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('marketing_asset_categories', 'name')
                    ->whereNull('deleted_at')
                    ->ignore($ignoreId),
            ],
            'description' => 'nullable|string|max:1000',
            'status' => 'nullable|string|in:'.implode(',', array_keys(Constants::MARKETING_ASSET_STATUS)),
        ];
    }
}
