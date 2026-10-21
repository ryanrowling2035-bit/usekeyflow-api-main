<?php

use App\Http\Middleware\BasicAuthLogs;
use App\Http\Middleware\EnforceAppVersion;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\LogApiRequest;
use App\Http\Middleware\LogSlowRequests;
use App\Http\Middleware\LogTokenRequest;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use PHPOpenSourceSaver\JWTAuth\Http\Middleware\Authenticate as JwtAuthenticate;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['jwt.auth']],
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->api(prepend: [
            // Outermost so it wraps auth:sanctum / throttle too — those are
            // priority-sorted ahead of appended middleware, so an appended
            // logger never runs when they throw (401/429). Prepended, it does.
            LogApiRequest::class,
            ForceJsonResponse::class,
            EnforceAppVersion::class,
        ], append: [
            LogSlowRequests::class,
        ]);

        $middleware->alias([
            'jwt.auth' => JwtAuthenticate::class,
            'log.token' => LogTokenRequest::class,
            'auth.logs' => BasicAuthLogs::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {

        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'status' => false,
                    'message' => $e->getMessage() ?: 'Unauthenticated.',
                    'errors' => [],
                ], 401);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            if ($request->is('api/*')) {
                $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);

                return response()->json([
                    'status' => false,
                    'message' => "Too many requests. Please try again in {$retryAfter} seconds.",
                    'errors' => ['retry_after' => $retryAfter],
                ], 429, $e->getHeaders());
            }
        });

        $exceptions->render(function (TokenExpiredException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'status' => false,
                    'code' => 'TOKEN_EXPIRED',
                    'message' => 'Token expired',
                    'errors' => [],
                ], 401);
            }
        });

        $exceptions->render(function (TokenInvalidException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'status' => false,
                    'message' => 'Token invalid',
                    'errors' => [],
                ], 401);
            }
        });

        $exceptions->render(function (UnauthorizedHttpException $e, $request) {
            if ($request->is('api/*')) {
                $previous = $e->getPrevious();

                if ($previous instanceof TokenExpiredException) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Token expired',
                        'errors' => [],
                    ], 401);
                }

                if ($previous instanceof TokenInvalidException) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Token invalid',
                        'errors' => [],
                    ], 401);
                }

                if ($previous instanceof JWTException) {
                    return response()->json([
                        'status' => false,
                        'code' => 'AUTH_ERROR',
                        'message' => 'Authentication error: '.$previous->getMessage(),
                        'errors' => [],
                    ], 401);
                }

                return response()->json([
                    'status' => false,
                    'message' => 'Token not provided',
                    'errors' => [],
                ], 401);
            }
        });

        $exceptions->render(function (JWTException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'status' => false,
                    'code' => 'AUTH_ERROR',
                    'message' => 'Authentication error',
                    'errors' => [],
                ], 401);
            }
        });

    })
    ->create();
