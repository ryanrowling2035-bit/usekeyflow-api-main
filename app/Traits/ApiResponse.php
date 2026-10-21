<?php

namespace App\Traits;

use App\Exceptions\UserFacingException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait ApiResponse
{
    protected function exceptionResponse(string $context, \Throwable $e, int $code = 422, string $message = 'Something went wrong. Please contact support for assistance.')
    {
        $errorId = strtoupper(Str::random(15));
        Log::error("[{$errorId}] {$context} : ".$e->getMessage());

        if ($e instanceof UserFacingException) {
            return $this->error($e->getMessage(), $code);
        }

        if ($duplicate = $this->duplicateEntryMessage($e)) {
            return $this->error($duplicate, $code);
        }

        return $this->error("{$message} (Ref: {$errorId})", $code);
    }

    /**
     * Turn a MySQL duplicate-key rejection (1062) into something the user can act on.
     *
     * A unique index firing means the user typed a value someone else already holds —
     * their own problem to fix, not support's. The generic "contact support" message
     * hid that behind a reference number, so the same phone number kept being retyped.
     * Returns null for anything that isn't a duplicate-key error.
     */
    protected function duplicateEntryMessage(\Throwable $e): ?string
    {
        if (! $e instanceof QueryException || (int) ($e->errorInfo[1] ?? 0) !== 1062) {
            return null;
        }

        // The driver names the index it rejected, e.g. "for key 'contacts.contacts_phone_unique'".
        preg_match("/for key '([^']+)'/", $e->getMessage(), $matches);
        $key = strtolower($matches[1] ?? '');

        return match (true) {
            str_contains($key, 'phone') => 'This phone number already belongs to another record. Please check for an existing contact with this number.',
            str_contains($key, 'email') => 'This email address already belongs to another record. Please check for an existing contact with this address.',
            str_contains($key, 'reference') => 'This reference number is already in use.',
            default => 'A record with these details already exists.',
        };
    }

    protected function success($data = [], string $message = 'Success', int $code = 200)
    {
        if ($data instanceof ResourceCollection && $data->resource instanceof LengthAwarePaginator) {
            $paginator = $data->resource;

            return response()->json([
                'status' => true,
                'message' => $message,
                'data' => $data->collection,
                'meta' => $this->paginationMeta($paginator),
            ], $code);
        }

        if ($data instanceof LengthAwarePaginator) {
            return response()->json([
                'status' => true,
                'message' => $message,
                'data' => $data->items(),
                'meta' => $this->paginationMeta($data),
            ], $code);
        }

        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    protected function error(string $message = 'Error', int $code = 400, $errors = [])
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }

    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'from' => $paginator->firstItem(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ];
    }
}
