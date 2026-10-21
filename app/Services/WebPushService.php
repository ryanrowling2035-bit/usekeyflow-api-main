<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Self-hosted browser push (Web Push / VAPID). No third-party service: the
 * VAPID keypair is ours and payloads are encrypted for the subscriber's keys,
 * so the browser vendor's push endpoint only ever relays opaque bytes.
 */
class WebPushService
{
    /** Payloads are capped by the spec; keep well under it so nothing is dropped. */
    private const MAX_PAYLOAD_BYTES = 3000;

    protected ?string $publicKey;

    protected ?string $privateKey;

    protected string $subject;

    public function __construct()
    {
        $this->publicKey = env('VAPID_PUBLIC_KEY') ?: null;
        $this->privateKey = env('VAPID_PRIVATE_KEY') ?: null;
        $this->subject = env('VAPID_SUBJECT') ?: (string) config('app.url');
    }

    /**
     * Push is optional infrastructure — without a keypair the whole feature
     * stays dormant rather than throwing on every notification.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->publicKey) && ! empty($this->privateKey);
    }

    /**
     * The VAPID public key the browser needs for pushManager.subscribe().
     */
    public function getPublicKey(): ?string
    {
        return $this->publicKey;
    }

    /**
     * Register (or refresh) a browser subscription. Keyed on endpoint so a
     * re-subscribe from the same browser updates in place, and so a shared
     * machine reassigns the endpoint to whoever logged in last.
     */
    public function subscribe(int $userId, array $data): PushSubscription
    {
        return PushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'userId' => $userId,
                'publicKey' => $data['keys']['p256dh'],
                'authToken' => $data['keys']['auth'],
                'contentEncoding' => $data['contentEncoding'] ?? 'aesgcm',
                'userAgent' => $data['userAgent'] ?? null,
                'lastUsedAt' => now(),
            ]
        );
    }

    /**
     * Every browser the user has subscribed from, newest first. Secrets
     * (publicKey/authToken) stay server-side — the profile only needs to name
     * the device and say when it last received a push.
     *
     * $currentEndpoint is the endpoint the calling browser got back from
     * pushManager.getSubscription(); it is the only reliable way to tell which
     * row is the device the user is sitting at, since one machine can hold
     * several subscriptions and user agents are not unique.
     */
    public function listForUser(int $userId, ?string $currentEndpoint = null): array
    {
        return PushSubscription::where('userId', $userId)
            ->orderByDesc('lastUsedAt')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PushSubscription $subscription) => [
                'id' => $subscription->id,
                'endpoint' => $subscription->endpoint,
                'userAgent' => $subscription->userAgent,
                'contentEncoding' => $subscription->contentEncoding,
                'lastUsedAt' => $subscription->lastUsedAt?->toDateTimeString(),
                'createdAt' => $subscription->created_at?->toDateTimeString(),
                'isCurrentDevice' => $currentEndpoint !== null && $subscription->endpoint === $currentEndpoint,
            ])
            ->all();
    }

    /**
     * Remove a subscription on logout or permission revoke.
     */
    public function unsubscribe(int $userId, string $endpoint): bool
    {
        return PushSubscription::where('userId', $userId)
            ->where('endpoint', $endpoint)
            ->delete() > 0;
    }

    /**
     * Deliver a payload to every browser the user has subscribed from.
     * Endpoints the push service reports as gone are pruned as we go.
     */
    public function sendToUser(int $userId, array $payload): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $subscriptions = PushSubscription::where('userId', $userId)->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $body = $this->encodePayload($payload);

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $this->subject,
                'publicKey' => $this->publicKey,
                'privateKey' => $this->privateKey,
            ],
        ]);

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->publicKey,
                    'authToken' => $subscription->authToken,
                    'contentEncoding' => $subscription->contentEncoding,
                ]),
                $body
            );
        }

        $expiredEndpoints = [];
        $deliveredEndpoints = [];

        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getEndpoint();

            if ($report->isSuccess()) {
                $deliveredEndpoints[] = $endpoint;

                continue;
            }

            // 404/410 means the browser dropped the subscription for good.
            if ($report->isSubscriptionExpired()) {
                $expiredEndpoints[] = $endpoint;

                continue;
            }

            Log::warning('WebPushService : delivery failed for user '.$userId.' : '.$report->getReason());
        }

        if ($expiredEndpoints) {
            PushSubscription::whereIn('endpoint', $expiredEndpoints)->delete();
        }

        if ($deliveredEndpoints) {
            PushSubscription::whereIn('endpoint', $deliveredEndpoints)->update(['lastUsedAt' => now()]);
        }
    }

    /**
     * Encode the payload, dropping the body first if we are over the size cap —
     * a truncated notification still beats a rejected one.
     */
    protected function encodePayload(array $payload): string
    {
        $body = json_encode($payload);

        if (strlen($body) <= self::MAX_PAYLOAD_BYTES) {
            return $body;
        }

        $payload['body'] = null;

        return json_encode($payload);
    }
}
