<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WebPushService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected WebPushService $webPushService
    ) {}

    /**
     * VAPID public key for the browser's pushManager.subscribe() call.
     */
    public function publicKey()
    {
        try {
            return $this->success(
                [
                    'publicKey' => $this->webPushService->getPublicKey(),
                    'enabled' => $this->webPushService->isConfigured(),
                ],
                'Push public key retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->exceptionResponse('PushSubscriptionController : publicKey', $e);
        }
    }

    /**
     * Push subscriptions for the profile page. Defaults to the caller; an
     * explicit userId lets an admin see the devices on someone else's profile.
     *
     * The browser should pass its own endpoint (from pushManager.getSubscription())
     * so the matching row comes back flagged isCurrentDevice — without it the
     * server has no way to know which of the user's devices is asking.
     */
    public function index(Request $request)
    {
        try {
            $validated = $request->validate([
                'userId' => 'nullable|integer|exists:users,id',
                'endpoint' => 'nullable|string|max:500',
            ]);

            $userId = (int) ($validated['userId'] ?? $request->user()->id);
            $currentEndpoint = $validated['endpoint'] ?? null;

            $subscriptions = $this->webPushService->listForUser($userId, $currentEndpoint);

            return $this->success(
                [
                    'enabled' => $this->webPushService->isConfigured(),
                    'subscriptions' => $subscriptions,
                    // True only when the calling browser sent an endpoint we still hold.
                    // A false here with permission granted in the browser means the
                    // subscription was pruned server-side — re-subscribe.
                    'enabledOnCurrentDevice' => $currentEndpoint !== null
                        && collect($subscriptions)->contains('isCurrentDevice', true),
                ],
                'Push notifications retrieved successfully'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Invalid request', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->exceptionResponse('PushSubscriptionController : index', $e);
        }
    }

    /**
     * Register the browser's push subscription for the authenticated user.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'endpoint' => 'required|string|max:500',
                'keys' => 'required|array',
                'keys.p256dh' => 'required|string|max:255',
                'keys.auth' => 'required|string|max:255',
                'contentEncoding' => 'nullable|string|in:aesgcm,aes128gcm',
            ]);

            $validated['userAgent'] = substr((string) $request->userAgent(), 0, 512);

            $subscription = $this->webPushService->subscribe($request->user()->id, $validated);

            return $this->success(
                ['id' => $subscription->id],
                'Push notification saved successfully'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Invalid push subscription', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->exceptionResponse('PushSubscriptionController : store', $e);
        }
    }

    /**
     * Drop a subscription on logout or when the user revokes permission.
     */
    public function destroy(Request $request)
    {
        try {
            $validated = $request->validate([
                'endpoint' => 'required|string|max:500',
            ]);

            $deleted = $this->webPushService->unsubscribe($request->user()->id, $validated['endpoint']);

            if (! $deleted) {
                return $this->error('Push notification not found', 404);
            }

            return $this->success([], 'Push notification removed successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Invalid push subscription', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->exceptionResponse('PushSubscriptionController : destroy', $e);
        }
    }
}
