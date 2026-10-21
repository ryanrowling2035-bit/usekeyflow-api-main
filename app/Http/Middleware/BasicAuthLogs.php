<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BasicAuthLogs
{
    public function handle(Request $request, Closure $next): Response
    {
        $username = config('logging.api_log_username');
        $password = config('logging.api_log_password');

        if ($request->getUser() !== $username || $request->getPassword() !== $password) {
            return response('Unauthorized', 401, [
                'WWW-Authenticate' => 'Basic realm="Request Logs"',
            ]);
        }

        return $next($request);
    }
}
