<?php

namespace App\Services;

use App\Models\MarketingAsset;

class MarketingAssetService
{
    public function list($request)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        $query = MarketingAsset::query()->with(['uploader']);

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('assetName', 'LIKE', "%{$search}%")
                    ->orWhere('category', 'LIKE', "%{$search}%")
                    ->orWhere('assetType', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('assetType')) {
            $query->where('assetType', $request->assetType);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('startDate') && $request->filled('endDate')) {
            $query->whereBetween('created_at', [
                $request->startDate.' 00:00:00',
                $request->endDate.' 23:59:59',
            ]);
        }

        if ($request->filled('sortBy') || $request->filled('sort_by')) {
            $sortBy = $request->sortBy ?? $request->sort_by;
            $order = $request->get('order', 'desc');

            match ($sortBy) {
                'assetName' => $query->orderBy('assetName', $order),
                'category' => $query->orderBy('category', $order),
                'assetType' => $query->orderBy('assetType', $order),
                'status' => $query->orderBy('status', $order),
                'created_at' => $query->orderBy('created_at', $order),
                default => $query->latest(),
            };
        } else {
            $query->latest();
        }

        return $query->paginate($perPage);
    }

    public function store(array $data)
    {
        return MarketingAsset::create($data);
    }

    public function getById(int $id)
    {
        return MarketingAsset::with(['uploader'])->find($id);
    }

    public function update(int $id, array $data)
    {
        $asset = MarketingAsset::findOrFail($id);
        $asset->update($data);

        return $asset;
    }

    public function delete(int $id): bool
    {
        $asset = MarketingAsset::find($id);

        if (! $asset) {
            return false;
        }

        $asset->delete();

        return true;
    }
}
