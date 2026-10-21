<?php

namespace App\Repositories;

use App\Models\PropertyType;
use Illuminate\Http\Request;

class PropertyTypeRepository
{
    public function getAll(Request $request)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        $query = PropertyType::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('typeSlug', 'LIKE', "%{$search}%")
                    ->orWhere('publisher', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('publisherType')) {
            $query->where('publisher', $request->publisherType);
        }

        if ($request->filled('categoryType')) {
            $query->where('categoryType', $request->categoryType);
        }

        return $query->latest()->paginate($perPage);
    }
}
