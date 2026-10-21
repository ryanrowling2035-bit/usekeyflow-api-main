<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\LeadResource;
use App\Models\Enquiry;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LeadController extends BaseV1Controller
{
    /**
     * GET /v1/leads
     * Filters: from, to, agent_id, status, source, updated_after, page, limit.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'agent_id' => 'nullable|integer',
            'status' => 'nullable|string',
            'source' => 'nullable|string',
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

            $query = Enquiry::query()
                ->with(['agent', 'contact', 'listing']);

            if ($request->filled('agent_id')) {
                $query->where('agentId', $request->agent_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('source')) {
                $source = $request->source;
                $query->where(function ($q) use ($source) {
                    $q->where('leadSource', $source)
                        ->orWhere('technicalSource', $source);
                });
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('referenceNo', 'LIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%")
                        ->orWhere('leadSource', 'LIKE', "%{$search}%")
                        ->orWhere('technicalSource', 'LIKE', "%{$search}%")
                        ->orWhere('comments', 'LIKE', "%{$search}%")
                        ->orWhereHas('contact', function ($sub) use ($search) {
                            $sub->where('name', 'LIKE', "%{$search}%")
                                ->orWhere('email', 'LIKE', "%{$search}%")
                                ->orWhere('phone', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('agent', function ($sub) use ($search) {
                            $sub->where('name', 'LIKE', "%{$search}%")
                                ->orWhere('email', 'LIKE', "%{$search}%");
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
            $items = LeadResource::collection($paginator->items());

            return $this->paginated($paginator, $items, 'Leads retrieved successfully.');
        } catch (\Throwable $e) {
            return $this->handleException('LeadController@index', $e);
        }
    }
}
