<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiService
{
    protected ?string $baseUrl;

    protected ?string $apiKey;

    protected string $model;

    public function __construct()
    {
        $this->baseUrl = env('OPENAI_API_URL', 'https://api.openai.com/v1');
        $this->apiKey = env('OPENAI_API_KEY');
        $this->model = env('OPENAI_MODEL', 'gpt-4o-mini');
    }

    public function generateDescription(string $prompt, array $options = []): array
    {
        if (! $this->apiKey) {
            Log::error('[OpenAI API] Missing API key');

            return [
                'success' => false,
                'status' => 500,
                'error' => 'OpenAI API key not configured',
            ];
        }

        if (trim($prompt) === '') {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Prompt is required',
            ];
        }

        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $options['system'] ?? 'You are a helpful assistant that writes clear, concise, and engaging descriptions.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 500,
        ];

        $url = "{$this->baseUrl}/chat/completions";

        Log::info('[OpenAI API] Calling chat/completions', ['url' => $url, 'model' => $payload['model']]);

        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(60)
            ->post($url, $payload);

        if (! $response->successful()) {
            Log::error('[OpenAI API] Error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'success' => false,
                'status' => $response->status(),
                'error' => 'Failed to generate description from OpenAI',
                'details' => $response->body(),
            ];
        }

        $data = $response->json();
        $description = $data['choices'][0]['message']['content'] ?? null;

        Log::info('[OpenAI API] Success', ['tokens' => $data['usage']['total_tokens'] ?? null]);

        return [
            'success' => true,
            'status' => 200,
            'data' => [
                'description' => is_string($description) ? trim($description) : null,
                'usage' => $data['usage'] ?? null,
            ],
        ];
    }

    public function generatePropertyDescription(array $payload, bool $short = false): array
    {
        $payload = $this->hydrateFromProperty($payload);

        $prompt = $this->buildPropertyPrompt($payload, $short);

        $system = 'You are an experienced Dubai real estate marketing copywriter. You write polished, SEO-friendly property listing descriptions in British English for online portals and websites. You write naturally and never fabricate facts that are not provided.';

        return $this->generateDescription($prompt, [
            'system' => $system,
            'temperature' => 0.7,
            'max_tokens' => $short ? 100 : 900,
        ]);
    }

    public function generateFlyerDescription(array $payload): array
    {
        $payload = $this->hydrateFromProperty($payload);

        $flyerData = $this->decodeJsonField($payload['flyerData'] ?? null);

        if (empty($flyerData)) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'No flyer data found for this property',
            ];
        }

        $prompt = $this->buildFlyerPrompt($payload, $flyerData);

        $system = 'You are an experienced Dubai real estate marketing copywriter. You write short, punchy, benefit-led flyer copy in British English for printed and digital marketing material. You never invent facts that are not provided.';

        return $this->generateDescription($prompt, [
            'system' => $system,
            'temperature' => 0.7,
            'max_tokens' => 300,
        ]);
    }

    protected function hydrateFromProperty(array $payload): array
    {
        $propertyId = $payload['propertyId'] ?? $payload['id'] ?? null;
        if (! $propertyId) {
            return $payload;
        }

        $property = Property::with('meta')->find($propertyId);
        if (! $property) {
            return $payload;
        }

        $meta = $property->meta->pluck('metaValue', 'metaKey');

        $defaults = [
            'title' => $property->title,
            'description' => $property->description,
            'category' => $property->category,
            'listingType' => $property->listingType,
            'sizeArea' => $property->sizeArea,
            'bedrooms' => $property->bedrooms,
            'bathrooms' => formatDecimal($property->bathrooms),
            'completionStatus' => $property->completionStatus,
            'price' => $property->price,
            'view' => $property->view,
            'projectText' => $property->projectText,
            'bayutLocation' => $property->bayutLocation,
            'pfAmenities' => $property->pfAmenities,
            'bayutAmenities' => $property->bayutAmenities,
            'pfFurnishingType' => $meta->get('pfFurnishingType'),
            'pfFloorNumber' => $meta->get('pfFloorNumber'),
            'flyerData' => $meta->get('flyerData'),
        ];

        foreach ($defaults as $key => $value) {
            $current = $payload[$key] ?? null;
            $isEmpty = $current === null || $current === '' || (is_array($current) && empty($current));
            if ($isEmpty && $value !== null && $value !== '') {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    protected function buildPropertyPrompt(array $payload, bool $short = false): string
    {
        $location = $this->parseBayutLocation($payload['bayutLocation'] ?? null);

        $amenities = array_merge(
            is_array($payload['pfAmenities'] ?? null) ? $payload['pfAmenities'] : [],
            is_array($payload['bayutAmenities'] ?? null) ? $payload['bayutAmenities'] : []
        );

        $fields = [
            'Listing title' => $payload['title'] ?? null,
            'Listing type (Sale / Rent / International / Off Plan)' => $payload['listingType'] ?? null,
            'Property type' => $payload['category'] ?? null,
            'Furnishing status' => $payload['pfFurnishingType'] ?? null,
            'Completion status' => $payload['completionStatus'] ?? null,
            'Price' => $payload['price'] ?? null,
            'Bedrooms' => $payload['bedrooms'] ?? null,
            'Bathrooms' => $payload['bathrooms'] ?? null,
            'Size (area)' => $payload['sizeArea'] ?? null,
            'Community' => $location['community'] ?? null,
            'Sub-community' => $location['subCommunity'] ?? null,
            'Building / project name' => $location['building'] ?? ($payload['projectText'] ?? null),
            'View' => $payload['view'] ?? null,
            'Floor level' => $payload['pfFloorNumber'] ?? null,
            'Amenities' => $amenities ? implode(', ', array_unique($amenities)) : null,
            'Existing description' => $payload['description'] ?? null,
        ];

        $details = $this->formatFields($fields);

        if ($short) {
            return "Write a short property listing description for online portals, website listings, and SEO using only the details below.\n\n"
                ."Property details:\n{$details}\n\n"
                ."Requirements:\n"
                ."- LENGTH: The description must be no more than 80 words. Keep it tight and never exceed this limit.\n"
                ."- Open with a strong SEO hook line. Where the data allows, work the property type, bedroom count, community/location, and the key selling point into the first sentence. Keep it natural and engaging, never robotic.\n"
                ."- Include the location/community early. SEO keywords should appear naturally.\n"
                ."- Do not over-repeat the basic listing facts (bedrooms, bathrooms, size, price) — these are already shown on the listing page. Focus on lifestyle, location appeal, and the key selling points.\n"
                ."- Where a recognised community or sub-community is provided, include genuinely well-known area benefits. Never invent facts that are not provided or not generally known about the area.\n"
                ."- Write as one concise, flowing paragraph WITHOUT any headings, and finish with a brief call-to-action.\n"
                ."- TONE: professional, polished, client-focused, and appropriate for luxury Dubai real estate. Avoid generic AI phrasing such as \"this property offers a unique opportunity\", \"nestled in the heart of\", \"boasts\", and \"the perfect blend of comfort and convenience\".\n"
                ."- Write in British English.\n"
                ."- NEVER mention the unit number of the listing.\n"
                ."- Do not hallucinate or add anything untrue; avoid false claims or invented amenities.\n"
                .'- Return clean plain text only — no HTML, no markdown, no headings, and no bullet points.';
        }

        return "Write a property listing description for online portals, website listings, and SEO using only the details below.\n\n"
            ."Property details:\n{$details}\n\n"
            ."Requirements:\n"
            ."- LENGTH: The description must be between 1,000 and 2,000 characters including spaces. Stay within this range.\n"
            ."- Open with a strong SEO hook line that makes the reader want to continue. Where the data allows, work the property type, bedroom count, community/location, and the key selling point into the first sentence (for example: \"Discover a beautifully presented [bedroom count] [property type] in [community], offering [key selling point] in one of Dubai's most sought-after locations.\"). Keep it natural and engaging, never robotic.\n"
            ."- Include the location/community in the first paragraph. SEO keywords should appear naturally throughout.\n"
            ."- Do not over-repeat the basic listing facts (bedrooms, bathrooms, size, price) — these are already shown on the listing page. Mention them only briefly if useful. Focus instead on lifestyle, location appeal, key selling points, and why the property is attractive.\n"
            ."- Where a recognised community or sub-community is provided (such as Dubai Marina, Downtown Dubai, Palm Jumeirah, JVC, Arabian Ranches), include genuinely well-known area benefits: lifestyle, nearby facilities, accessibility, and family, investment, or lifestyle appeal. Never invent facts that are not provided or not generally known about the area.\n"
            ."- Follow this flow WITHOUT using any headings: strong SEO opening hook, short property overview, key selling points and lifestyle benefits, community/sub-community highlights, and a closing call-to-action inviting the reader to arrange a viewing, request more information, or contact the agent.\n"
            ."- TONE: professional, polished, client-focused, and appropriate for luxury Dubai real estate. Sound like an experienced human marketer. Avoid generic AI phrasing such as \"this property offers a unique opportunity\", \"nestled in the heart of\", \"boasts\", and \"the perfect blend of comfort and convenience\". Do not repeat the same point multiple times or sound exaggerated or unrealistic.\n"
            ."- Write in British English.\n"
            ."- NEVER mention the unit number of the listing.\n"
            ."- Do not hallucinate or add anything untrue; avoid false claims or invented amenities.\n"
            .'- Return clean plain text only — no HTML, no markdown, no headings, and no bullet points.';
    }

    protected function buildFlyerPrompt(array $payload, $flyerData): string
    {
        $highlights = $this->normaliseFlyerHighlights($flyerData);
        $highlightsText = $highlights
            ? implode("\n", array_map(fn ($h) => "- {$h}", $highlights))
            : (is_array($flyerData) || is_object($flyerData)
                ? json_encode($flyerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : (string) $flyerData);

        $location = $this->parseBayutLocation($payload['bayutLocation'] ?? null);

        $context = $this->formatFields([
            'Title' => $payload['title'] ?? null,
            'Listing type (Sale / Rent / International / Off Plan)' => $payload['listingType'] ?? null,
            'Property type' => $payload['category'] ?? null,
            'Community' => $location['community'] ?? null,
            'Sub-community' => $location['subCommunity'] ?? null,
            'Building / project name' => $location['building'] ?? ($payload['projectText'] ?? null),
            'Existing description' => $payload['description'] ?? null,
        ]);

        return "Write a short, persuasive real estate flyer description. The flyer highlights below are the primary selling points and MUST all be incorporated into the copy.\n\n"
            ."Flyer highlights (every one of these must appear, woven in naturally):\n{$highlightsText}\n\n"
            ."Property context (use only to frame the highlights — do not pad with extra detail):\n{$context}\n\n"
            ."Requirements:\n"
            ."- LENGTH: The description must be between 300 and 500 characters including spaces. Stay within this range.\n"
            ."- Open with the strongest flyer highlight, then weave the remaining highlights in naturally as the body of the copy.\n"
            ."- Use the property context only for factual framing (location, type, project). Do not introduce facilities or features not listed above.\n"
            ."- Where an existing property description is provided, you may reuse the strongest material from it, but the flyer highlights remain the focus.\n"
            ."- Keep it concise, commercial, and easy to read — suitable for printed or digital marketing material.\n"
            ."- Write in British English.\n"
            ."- Do not invent any information beyond what is provided in the flyer highlights and property context.\n"
            .'- Return clean plain text only — no headings, bullet points, markdown, or HTML.';
    }

    protected function normaliseFlyerHighlights($flyerData): array
    {
        if (! is_array($flyerData) && ! is_object($flyerData)) {
            return [];
        }

        $highlights = [];
        foreach ((array) $flyerData as $value) {
            if (is_string($value) && trim($value) !== '') {
                $highlights[] = trim($value);
            }
        }

        return $highlights;
    }

    protected function formatFields(array $fields): string
    {
        $lines = [];
        foreach ($fields as $label => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = "- {$label}: {$value}";
            }
        }

        return implode("\n", $lines);
    }

    protected function parseBayutLocation(?string $location): array
    {
        if (! $location) {
            return [];
        }

        $parts = array_values(array_filter(array_map('trim', explode('>', $location)), fn ($p) => $p !== ''));
        $count = count($parts);

        return [
            'city' => $parts[0] ?? null,
            'community' => $parts[1] ?? null,
            'subCommunity' => $count >= 4 ? $parts[2] : null,
            'building' => $count >= 4 ? $parts[$count - 1] : ($parts[2] ?? null),
        ];
    }

    protected function decodeJsonField($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
