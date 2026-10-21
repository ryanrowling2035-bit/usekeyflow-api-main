<?php

namespace App\Repositories;

use App\Models\PropertyCategory;
use Illuminate\Http\Request;

class PropertyCategoryRepository
{
    public function getAll(Request $request)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        $query = PropertyCategory::query();

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('id')) {
            $query->orderByRaw(
                'CASE WHEN id = ? THEN 0 ELSE 1 END',
                [$request->id]
            );
        }

        $query->orderBy('id', 'desc');

        return $query->paginate($perPage);
    }

    public function create(array $data)
    {
        return PropertyCategory::create($data);
    }

    public function findById(int $id)
    {
        return PropertyCategory::find($id);
    }

    public function update(int $id, array $data)
    {
        $category = $this->findById($id);
        $category->update($data);

        return $category;
    }

    public function delete(int $id): bool
    {
        $category = $this->findById($id);

        return (bool) $category->delete();
    }
}
