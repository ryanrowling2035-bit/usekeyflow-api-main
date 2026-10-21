<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessTokenLog;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePublicApi
{
    /**
     * Authenticate public API requests using either:
     * 1. Authorization: Bearer <token> (Sanctum personal access token)
     * 2. X-API-KEY: <key> (Sanctum personal access token or configured public API key)
     * 3. ?api_key=<key> query parameter fallback
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if (! $token) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated. API Key or Bearer Token required.',
                'errors' => [
                    'auth' => 'Provide a valid token via "Authorization: Bearer <token>" or "X-API-KEY: <key>" header.',
                ],
            ], 401);
        }

        // 1. Check against static API key in config/services or .env (if configured)
        $configuredKey = config('services.keyflow_public_api.api_key') ?: env('KEYFLOW_PUBLIC_API_KEY');
        if (! empty($configuredKey) && hash_equals($configuredKey, $token)) {
            return $next($request);
        }

        // 2. Check Sanctum Personal Access Token
        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated. Invalid API Key or Bearer Token.',
                'errors' => [
                    'auth' => 'The provided token is invalid or has expired.',
                ],
            ], 401);
        }

        // Check if token has expired
        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated. Token expired.',
                'errors' => [
                    'auth' => 'The provided token has expired.',
                ],
            ], 401);
        }

        // Update token last_used_at
        $accessToken->forceFill(['last_used_at' => now()])->save();

        // Bind user to request & auth guards
        $user = $accessToken->tokenable;
        if ($user) {
            $request->setUserResolver(fn () => $user);
            if (auth()->guard('sanctum')) {
                auth('sanctum')->setUser($user);
            }
        }

        $response = $next($request);

        // Optional log for auditing
        if ($user) {
            try {
                PersonalAccessTokenLog::create([
                    'tokenId' => $accessToken->getKey(),
                    'userId' => $user->id,
                    'method' => $request->method(),
                    'endpoint' => $request->path(),
                    'statusCode' => $response->getStatusCode(),
                    'ipAddress' => $request->ip(),
                    'userAgent' => $request->userAgent(),
                    'payload' => $request->all(),
                    'response' => null,
                ]);
            } catch (\Throwable) {
                // Silently ignore logging errors so core API flow is unaffected
            }
        }

        return $response;
    }

    /**
     * Extract bearer token or API key from request headers or query params.
     */
    protected function extractToken(Request $request): ?string
    {
        // 1. Authorization: Bearer <token>
        $bearerToken = $request->bearerToken();
        if ($bearerToken) {
            return trim($bearerToken);
        }

        // 2. X-API-KEY header
        $apiKeyHeader = $request->header('X-API-KEY') ?? $request->header('x-api-key');
        if ($apiKeyHeader) {
            return trim($apiKeyHeader);
        }

        // 3. Fallback query parameter ?api_key=
        $queryKey = $request->query('api_key');
        if ($queryKey) {
            return trim((string) $queryKey);
        }

        return null;
    }
}
