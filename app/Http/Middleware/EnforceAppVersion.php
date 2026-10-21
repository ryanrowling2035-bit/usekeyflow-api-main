<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceAppVersion
{
    /**
     * Gate app requests on the version the client reports via plain headers.
     *
     * Clients identify themselves with three separate string headers (no
     * JSON, so nothing to mis-serialize):
     *   X-App-Platform:        ios | android
     *   X-App-Current-Version: 1.1.3   (the value iOS is gated on)
     *   X-App-Build:           6       (the value Android is gated on)
     *
     * Required/minimum values come from these env vars (leave the required one
     * empty to disable enforcement for that platform):
     *   - IOS_APP_REQUIRED_VERSION     / MIN_IOS_APP_REQUIRED_VERSION
     *   - ANDROID_APP_REQUIRED_VERSION / MIN_ANDROID_APP_REQUIRED_VERSION
     *
     * Outcomes:
     *   - at or above the required value -> pass through untouched
     *   - matches the minimum value -> pass through, flag "partialUpdate": true
     *   - anything else             -> pass through, flag "forceUpdate": true
     *
     * Web browsers omit these headers and are never gated.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $platform = $request->header('X-App-Platform');

        if ($platform !== null && $platform !== '') {
            switch (strtolower($platform)) {
                case 'ios':
                    $clientValue = $request->header('X-App-Current-Version');
                    $requiredVersion = env('IOS_APP_REQUIRED_VERSION');
                    $minVersion = env('MIN_IOS_APP_REQUIRED_VERSION');
                    break;
                case 'android':
                    $clientValue = $request->header('X-App-Build');
                    $requiredVersion = env('ANDROID_APP_REQUIRED_VERSION');
                    $minVersion = env('MIN_ANDROID_APP_REQUIRED_VERSION');
                    break;
                default:
                    return $next($request);
            }

            // Enforcement disabled for this platform -> pass through.
            if ($requiredVersion === null || $requiredVersion === '') {
                return $next($request);
            }

            // Client didn't report a value to compare -> don't gate blind.
            if ($clientValue === null || $clientValue === '') {
                return $next($request);
            }

            // At or above the required version -> up to date, allow through.
            if (version_compare((string) $clientValue, (string) $requiredVersion, '>=')) {
                return $next($request);
            }

            // From here the client is out of date; run the request, then flag
            // the JSON body so the app can prompt an update.
            $response = $next($request);

            // On the minimum supported version -> allow, flag a partial update.
            if ($minVersion !== null && $minVersion !== '' && (string) $clientValue === (string) $minVersion) {
                if ($response instanceof JsonResponse && is_array($data = $response->getData(true))) {
                    $data['partialUpdate'] = true;
                    $response->setData($data);
                }

                return $response;
            }

            // Below the minimum -> allow through, flag a forced update.
            if ($response instanceof JsonResponse && is_array($data = $response->getData(true))) {
                $data['forceUpdate'] = true;
                $data['message'] = 'Please update the app to the latest version to continue.';
                $response->setData($data);
            }

            return $response;
        }

        return $next($request);
    }
}
