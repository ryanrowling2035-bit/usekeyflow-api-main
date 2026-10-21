<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\ListingResource;
use App\Models\Property;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ListingController extends BaseV1Controller
{
    /**
     * GET /v1/listings
     * Filters: from, to, agent_id, status, updated_after, page, limit.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'agent_id' => 'nullable|integer',
            'status' => 'nullable|string',
            'search' => 'nullable|string',
            'updated_after' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error.', 422, $validator->errors()->toArray());
        }

        try {
            $limit = $this->resolveLimit($request);

            $query = Property::query()
                ->with([
                    'agent',
                    'mediaThumbnails',
                    'city',
                    'community',
                    'subCommunity',
                    'buildingTower',
                ]);

            if ($request->filled('agent_id')) {
                $query->where('agentId', $request->agent_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'LIKE', "%{$search}%")
                        ->orWhere('referenceNumber', 'LIKE', "%{$search}%")
                        ->orWhere('category', 'LIKE', "%{$search}%")
                        ->orWhere('listingType', 'LIKE', "%{$search}%")
                        ->orWhereHas('community', function ($sub) use ($search) {
                            $sub->where('name', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('agent', function ($sub) use ($search) {
                            $sub->where('name', 'LIKE', "%{$search}%");
                        });
                });
            }

            if ($request->filled('from')) {
                $from = Carbon::parse($request->from)->startOfDay();
                $query->where('created_at', '>=', $from);
            }

            if ($request->filled('to')) {
                $to = Carbon::parse($request->to)->endOfDay();
                $query->where('created_at', '<=', $to);
            }

            if ($request->filled('updated_after')) {
                $updatedAfter = Carbon::parse($request->updated_after);
                $query->where('updated_at', '>=', $updatedAfter);
            }

            $query->orderBy('created_at', 'desc');

            $paginator = $query->paginate($limit);
            $items = ListingResource::collection($paginator->items());

            return $this->paginated($paginator, $items, 'Listings retrieved successfully.');
        } catch (\Throwable $e) {
            return $this->handleException('ListingController@index', $e);
        }
    }
}
