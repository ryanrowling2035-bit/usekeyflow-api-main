<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\AgentResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AgentController extends BaseV1Controller
{
    /**
     * GET /v1/agents
     * Returns agent directory.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|string',
            'department' => 'nullable|string',
            'search' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation error.', 422, $validator->errors()->toArray());
        }

        try {
            $limit = $this->resolveLimit($request, default: 50, max: 100);

            $query = User::query();

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('department') && \Illuminate\Support\Facades\Schema::hasColumn('users', 'department')) {
                $query->where('department', $request->department);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%")
                        ->orWhere('agentCode', 'LIKE', "%{$search}%");
                });
            }

            $query->orderBy('name', 'asc');

            $paginator = $query->paginate($limit);
            $items = AgentResource::collection($paginator->items());

            return $this->paginated($paginator, $items, 'Agent directory retrieved successfully.');
        } catch (\Throwable $e) {
            return $this->handleException('AgentController@index', $e);
        }
    }
}
