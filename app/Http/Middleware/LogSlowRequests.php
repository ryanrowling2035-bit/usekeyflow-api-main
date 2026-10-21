<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;

class LogSlowRequests
{
    public function handle($request, Closure $next)
    {
        $start = microtime(true);
        $response = $next($request);
        $duration = microtime(true) - $start;

        if ($duration > 2) { // log if more than 2 seconds
            Log::channel('slow_api')->warning('Slow API', [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'duration' => round($duration, 2).'s',
                'userId' => auth()->id(),
                'ip' => $request->ip(),
            ]);
        }

        return $response;
    }
}
