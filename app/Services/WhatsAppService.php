<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    /**
     * Send a WhatsApp template message via the 360degreeapps gateway.
     *
     * @param  string  $to  Recipient number (any format; normalised to +<digits>)
     * @param  string  $templateName  Approved Meta/WhatsApp template name
     * @param  array  $components  Template components (use textParams()/imageHeader() helpers)
     * @param  string|null  $language  Language code (defaults to config value)
     */
    public function sendTemplate(string $to, string $templateName, array $components = [], ?string $language = null): bool
    {
        $config = config('services.whatsapp_360');

        if (empty($config['enabled'])) {
            Log::info('WhatsAppService: disabled, skipping send', ['to' => $to]);

            return false;
        }

        $receiver = $this->normalizeNumber($to);
        if (! $receiver) {
            Log::warning('WhatsAppService: missing/invalid recipient number', ['to' => $to]);

            return false;
        }

        try {
            $template = [
                'name' => $templateName,
                'language' => ['code' => $language ?: ($config['language'] ?? 'en')],
            ];

            if (! empty($components)) {
                $template['components'] = $components;
            }

            $payload = [
                'senderNumber' => $config['sender'],
                'receiverNumber' => $receiver,
                'channel' => 'whatsapp',
                'type' => 'template',
                'template' => $template,
            ];

            $response = Http::withToken($config['token'])
                ->asJson()
                ->timeout(15)
                ->post($config['endpoint'], $payload);

            $errorCode = data_get($response->json(), 'message.error_code');

            if ($response->failed() || $errorCode !== null) {
                Log::error('WhatsAppService: sendTemplate failed', [
                    'to' => $receiver,
                    'template' => $templateName,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            Log::info('WhatsAppService: sendTemplate sent', [
                'to' => $receiver,
                'template' => $templateName,
                'status' => $response->status(),
                'messageId' => data_get($response->json(), 'message.id'),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('WhatsAppService: sendTemplate exception: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Build a "body" component from an ordered list of text parameters.
     * Order MUST match the placeholders ({{1}}, {{2}}, ...) in the approved template.
     */
    public static function textParams(array $texts): array
    {
        return [
            'type' => 'body',
            'parameters' => array_map(
                fn ($text) => ['type' => 'text', 'text' => (string) $text],
                array_values($texts)
            ),
        ];
    }

    /**
     * Build a "header" component carrying a single image.
     */
    public static function imageHeader(string $link): array
    {
        return [
            'type' => 'header',
            'parameters' => [
                ['type' => 'image', 'image' => ['link' => $link]],
            ],
        ];
    }

    /**
     * Normalise to international "+<digits>" form expected by the gateway.
     */
    private function normalizeNumber(?string $number): ?string
    {
        if (! $number) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $number);

        return $digits !== '' ? '+'.$digits : null;
    }
}
