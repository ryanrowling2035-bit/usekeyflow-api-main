<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

abstract class BaseV1Controller extends Controller
{
    /**
     * Standard success response for single resource or non-paginated data.
     */
    protected function success($data = [], string $message = 'Success', int $code = 200): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    /**
     * Standard success response for paginated collections with explicit page, limit, total.
     */
    protected function paginated(LengthAwarePaginator $paginator, $transformedData, string $message = 'Data retrieved successfully.'): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $transformedData,
            'pagination' => [
                'page' => $paginator->currentPage(),
                'limit' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ], 200);
    }

    /**
     * Standard error response.
     */
    protected function error(string $message = 'Error', int $code = 400, array $errors = []): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }

    /**
     * Handle unhandled exceptions gracefully.
     */
    protected function handleException(string $context, \Throwable $e): JsonResponse
    {
        $ref = strtoupper(Str::random(12));
        Log::error("[V1 API {$ref}] {$context}: ".$e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return $this->error("An unexpected error occurred while processing the request. (Ref: {$ref})", 500);
    }

    /**
     * Resolve pagination limit safely within bounds.
     */
    protected function resolveLimit(Request $request, int $default = 20, int $max = 100): int
    {
        $limit = $request->input('limit', $request->input('per_page', $default));

        return max(1, min((int) $limit, $max));
    }
}
