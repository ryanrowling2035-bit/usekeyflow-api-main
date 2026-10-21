<?php

namespace App\Repositories;

use App\Models\PropertyListingHistory;
use Illuminate\Http\Request;

class PropertyHistoryRepository
{
    public function create(array $data)
    {
        return PropertyListingHistory::create($data);
    }

    public function getAll(Request $request)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        $query = PropertyListingHistory::with(['user:id,name,email,profilePhoto,thumbnailPath']);

        if (isAgent()) {
            $query->where('userId', user()->id);
        } elseif ($request->filled('userId')) {
            $query->where('userId', $request->userId);
        }

        if ($request->filled('propertyId')) {
            $query->where('propertyId', $request->propertyId);
        }

        if ($request->filled('field')) {
            $query->where('field', $request->field);
        }

        if ($request->filled('startDate')) {
            $query->where('created_at', '>=', $request->startDate.' 00:00:00');
        }

        if ($request->filled('endDate')) {
            $query->where('created_at', '<=', $request->endDate.' 23:59:59');
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('field', 'LIKE', "%{$search}%")
                    ->orWhere('comments', 'LIKE', "%{$search}%")
                    ->orWhere('originalValue', 'LIKE', "%{$search}%")
                    ->orWhere('newValue', 'LIKE', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }
}
