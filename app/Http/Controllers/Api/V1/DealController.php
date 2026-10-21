<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\DealResource;
use App\Models\Closing;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DealController extends BaseV1Controller
{
    /**
     * GET /v1/deals
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

            $query = Closing::query()
                ->with(['assignedAgent', 'buyer', 'property', 'enquiry']);

            if ($request->filled('agent_id')) {
                $query->where('assignedAgentId', $request->agent_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('referenceNo', 'LIKE', "%{$search}%")
                        ->orWhere('status', 'LIKE', "%{$search}%")
                        ->orWhereHas('buyer', function ($sub) use ($search) {
                            $sub->where('name', 'LIKE', "%{$search}%")
                                ->orWhere('email', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('property', function ($sub) use ($search) {
                            $sub->where('referenceNumber', 'LIKE', "%{$search}%")
                                ->orWhere('title', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('assignedAgent', function ($sub) use ($search) {
                            $sub->where('name', 'LIKE', "%{$search}%");
                        });
                });
            }

            if ($request->filled('from')) {
                $from = Carbon::parse($request->from)->startOfDay();
                $query->where(function ($q) use ($from) {
                    $q->where('closingDate', '>=', $from->toDateString())
                        ->orWhere(function ($sub) use ($from) {
                            $sub->whereNull('closingDate')
                                ->where('created_at', '>=', $from);
                        });
                });
            }

            if ($request->filled('to')) {
                $to = Carbon::parse($request->to)->endOfDay();
                $query->where(function ($q) use ($to) {
                    $q->where('closingDate', '<=', $to->toDateString())
                        ->orWhere(function ($sub) use ($to) {
                            $sub->whereNull('closingDate')
                                ->where('created_at', '<=', $to);
                        });
                });
            }

            if ($request->filled('updated_after')) {
                $updatedAfter = Carbon::parse($request->updated_after);
                $query->where('updated_at', '>=', $updatedAfter);
            }

            $query->orderBy('created_at', 'desc');

            $paginator = $query->paginate($limit);
            $items = DealResource::collection($paginator->items());

            return $this->paginated($paginator, $items, 'Deals and commission details retrieved successfully.');
        } catch (\Throwable $e) {
            return $this->handleException('DealController@index', $e);
        }
    }
}
