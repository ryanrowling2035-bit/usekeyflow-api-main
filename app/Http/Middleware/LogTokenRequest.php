<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessTokenLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogTokenRequest
{
    /**
     * Endpoints whose response body should be persisted alongside the request
     * log. Add more paths here as other external endpoints need response capture.
     */
    private const RESPONSE_LOGGED_ENDPOINTS = [
        'api/external/enquiry/create',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($user && $token) {
            // Bridge sanctum-authenticated user into the api (JWT) guard so the
            // existing user() helper and downstream auth('api') calls work unchanged.
            auth('api')->setUser($user);
        }

        $response = $next($request);

        if ($user && $token) {
            PersonalAccessTokenLog::create([
                'tokenId' => $token->getKey(),
                'userId' => $user->id,
                'method' => $request->method(),
                'endpoint' => $request->path(),
                'statusCode' => $response->getStatusCode(),
                'ipAddress' => $request->ip(),
                'userAgent' => $request->userAgent(),
                'payload' => $request->all(),
                'response' => $this->responseBody($request, $response),
            ]);
        }

        return $response;
    }

    /**
     * Capture the response body for logging, but only for the whitelisted
     * endpoints. JSON responses are decoded so they land in the json `response`
     * column as structured data; anything else (XML, binary, etc.) is stored as
     * a raw string.
     */
    private function responseBody(Request $request, Response $response)
    {
        if (! $request->is(...self::RESPONSE_LOGGED_ENDPOINTS)) {
            return null;
        }

        $content = $response->getContent();

        if ($content === false || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $content;
    }
}
