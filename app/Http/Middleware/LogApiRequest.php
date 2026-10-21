<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class LogApiRequest
{
    private const SENSITIVE_FIELDS = [
        'password',
        'password_confirmation',
        'token',
        'secret',
        'credit_card',
        'authorization',
        'access_token',
    ];

    private const MAX_RESPONSE_LENGTH = 2000;

    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // Downstream middleware (e.g. auth:sanctum 401, throttle 429) throws
            // before we get a response, so the normal log below would be skipped.
            // Render the exception here only to capture the real status code for
            // the log entry, then re-throw so the framework reports and renders
            // it exactly as before (single report, unchanged client response).
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $response = app(ExceptionHandler::class)->render($request, $e);
            $this->log($request, $response, $duration);

            throw $e;
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        $this->log($request, $response, $duration);

        return $response;
    }

    private function log(Request $request, Response $response, float $duration): void
    {
        try {
            $user = Auth::guard('api')->user();
            $userInfo = $user ? "#{$user->id} ({$user->email})" : 'Guest';

            $timestamp = now()->format('Y-m-d H:i:s');
            $method = $request->method();
            $url = $request->fullUrl();
            $ip = $request->ip();
            $status = $response->getStatusCode();

            $headers = $this->formatHeaders($request);
            $queryString = $request->getQueryString() ?: '-';
            $requestBody = $this->maskSensitiveData($this->requestBody($request));
            $responseBody = $this->truncateResponse($response->getContent());

            $entry = str_repeat('=', 80)."\n";
            $entry .= "[{$timestamp}] REQUEST\n";
            $entry .= str_repeat('-', 80)."\n";
            $entry .= "Method:      {$method}\n";
            $entry .= "URL:         {$url}\n";
            $entry .= "User:        {$userInfo}\n";
            $entry .= "IP:          {$ip}\n";
            $entry .= "Duration:    {$duration}ms\n";
            $entry .= "Status:      {$status}\n";
            $entry .= "Headers:     {$headers}\n";
            $entry .= "Query:       {$queryString}\n";
            $entry .= "Request Body:\n";
            $entry .= $this->indentJson($requestBody)."\n";
            $entry .= "Response Body:\n";
            $entry .= $this->indentJson($responseBody)."\n";
            $entry .= str_repeat('=', 80)."\n\n";

            $filename = 'request-log-'.now()->format('m-d-Y').'.txt';
            $path = storage_path("logs/{$filename}");

            $existing = file_exists($path) ? file_get_contents($path) : '';
            file_put_contents($path, $entry.$existing, LOCK_EX);
        } catch (\Throwable $e) {
            // Silently fail — logging should never break the application
        }
    }

    /**
     * Resolve the request body for logging. Workflow tools (n8n, Make, etc.)
     * sometimes POST JSON without a Content-Type header, so $request->all() is
     * empty; fall back to decoding the raw body — mirroring ExternalController.
     */
    private function requestBody(Request $request): array
    {
        $input = $request->all();

        if (empty($input)) {
            $decoded = json_decode($request->getContent(), true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }

        return $input;
    }

    private function formatHeaders(Request $request): string
    {
        $relevant = ['content-type', 'accept', 'user-agent', 'referer', 'origin', 'x-app-platform', 'x-app-version', 'x-app-current-version', 'x-app-build'];
        $headers = [];

        foreach ($relevant as $key) {
            $value = $request->header($key);
            if (! $value) {
                continue;
            }

            // X-App-Version arrives as a JSON string — normalize it so the log
            // shows clean JSON. A client that sent the literal "[object Object]"
            // (forgot to JSON.stringify) isn't valid JSON, so it's left as-is.
            if ($key === 'x-app-version') {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $value = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            $headers[] = ucwords($key, '-').': '.$value;
        }

        // Pipe-separated, not comma — the X-App-Version header is now a JSON
        // object whose own commas would otherwise blur the header boundaries.
        return $headers ? implode(' | ', $headers) : '-';
    }

    private function maskSensitiveData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->maskSensitiveData($value);
            } elseif (in_array(strtolower($key), self::SENSITIVE_FIELDS, true)) {
                $data[$key] = '********';
            }
        }

        return $data;
    }

    private function truncateResponse(?string $content): mixed
    {
        if ($content === null || $content === '') {
            return '-';
        }

        $decoded = json_decode($content, true);
        $decoded = $this->maskSensitiveData($decoded);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        if (strlen($content) > self::MAX_RESPONSE_LENGTH) {
            return substr($content, 0, self::MAX_RESPONSE_LENGTH).'... [truncated]';
        }

        return $content;
    }

    private function indentJson(mixed $data): string
    {
        if (is_string($data)) {
            return "  {$data}";
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return preg_replace('/^/m', '  ', $json);
    }
}
