<?php

namespace App\Services;

use App\Models\MarketingAssetCategory;

class MarketingAssetCategoryService
{
    /**
     * Paginated list with optional search (name / description) and status filter.
     */
    public function list($request)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? (int) $request->per_page
            : 10;

        // assetsCount = number of marketing assets assigned to each category.
        $query = MarketingAssetCategory::withCount(['assets as assetsCount']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Lightweight active-only list for dropdowns: [{value, label}], name-sorted.
     */
    public function select()
    {
        return MarketingAssetCategory::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name])
            ->values();
    }

    public function store(array $data): MarketingAssetCategory
    {
        return MarketingAssetCategory::create($data);
    }

    public function getById(int $id): ?MarketingAssetCategory
    {
        return MarketingAssetCategory::find($id);
    }

    public function update(int $id, array $data): MarketingAssetCategory
    {
        $category = MarketingAssetCategory::findOrFail($id);
        $category->update($data);

        return $category;
    }

    public function delete(int $id): bool
    {
        $category = MarketingAssetCategory::find($id);

        if (! $category) {
            return false;
        }

        $category->delete();

        return true;
    }
}
