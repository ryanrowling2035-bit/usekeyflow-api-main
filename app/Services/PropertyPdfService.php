<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\GlobalSetting;
use App\Models\Property;
use App\Models\PropertyMediaFile;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class PropertyPdfService
{
    public function __construct(
        protected FileStorageService $fileStorage
    ) {}

    /**
     * Hard cap on the number of bulk photos encoded per render — applies to the
     * full-gallery (allImagesSrc) and floor-plan (floorPlansSrc) loops only.
     * Hero, gallery sidebar, building, developer/development logos, agent photo
     * and QR are bounded separately and are NOT counted against this limit.
     */
    private const MAX_BULK_IMAGES = 10;

    /**
     * Which heavy image arrays each template actually consumes. Only the arrays
     * listed for the requested slug are base64-encoded; the rest are skipped so
     * a flyer doesn't pay for the brochure's full-gallery encoding (and vice
     * versa). Unknown slugs fall back to a4_single_page_flyer, which needs only
     * the gallery — see $needs* computation in buildTemplateData().
     */
    private const TEMPLATE_IMAGE_REQUIREMENTS = [
        // Sidebar gallery images.
        'gallery' => [
            Constants::PDF_TEMPLATE_A4_SHOP_FLYER,
            Constants::PDF_TEMPLATE_A4_SHOP_FLYER_HIGH_QUALITY,
            Constants::PDF_TEMPLATE_A4_SINGLE_PAGE_FLYER,
            Constants::PDF_TEMPLATE_OFFPLAN_FACT_SHEET,
            Constants::PDF_TEMPLATE_OFFPLAN_FACT_SHEET_USER,
            Constants::PDF_TEMPLATE_PERMIT_TEST,
        ],
        // Offplan fact sheets: dedicated building image + developer/development logos.
        'offplan' => [
            Constants::PDF_TEMPLATE_OFFPLAN_FACT_SHEET,
            Constants::PDF_TEMPLATE_OFFPLAN_FACT_SHEET_USER,
        ],
        // Brochures: full image gallery + floor plans.
        'brochure' => [
            Constants::PDF_TEMPLATE_BROCHURE_MULTI_PAGE,
            Constants::PDF_TEMPLATE_BROCHURE_MULTI_PAGE_USER,
        ],
        // Templates that render the listing's govt QR ($govtQRSrc). Deliberately
        // excludes the Riezl flyer: the printed Riezl sheet carries its own
        // dedicated QR, so the listing QR must never appear on it.
        'qr' => [
            Constants::PDF_TEMPLATE_A4_SHOP_FLYER,
            Constants::PDF_TEMPLATE_A4_SINGLE_PAGE_FLYER,
            Constants::PDF_TEMPLATE_BROCHURE_MULTI_PAGE,
            Constants::PDF_TEMPLATE_BROCHURE_MULTI_PAGE_USER,
            Constants::PDF_TEMPLATE_OFFPLAN_FACT_SHEET,
            Constants::PDF_TEMPLATE_OFFPLAN_FACT_SHEET_USER,
        ],
    ];

    /**
     * Per-request memo for encoded images, keyed by path + maxWidth.
     * The same photo is referenced by several template sections in one render
     * (allImages, gallery, hero, building); this ensures each S3 download + GD
     * re-encode happens once. Reset at the end of every buildTemplateData() call.
     */
    private array $imageBase64Cache = [];

    /**
     * Per-request memo for raw (un-encoded) image bytes, keyed by storage
     * reference. Warmed in one parallel batch by prefetchRawBytes() before the
     * encode loops run, so each image's S3 download happens concurrently instead
     * of sequentially. getImageBase64()/getLogoBase64() read bytes from here and
     * fall back to a direct read for anything not pre-warmed. Reset at the end of
     * every buildTemplateData() call alongside $imageBase64Cache.
     */
    private array $rawBytesCache = [];

    /**
     * Per-request memo for encoded company-logo data URIs, keyed by variant
     * (white/dark/stacked). The same variant is referenced from several
     * sections in one render; this downsizes + encodes each variant once.
     */
    private array $logoBase64Cache = [];

    /**
     * Short token correlating all timing log lines of a single PDF render, so
     * concurrent requests on the live server can be told apart in the log.
     */
    private string $logToken = '';

    /**
     * Log one timing step and return the current timestamp so the caller can
     * chain it into the next step: $t = $this->logStep('label', $t).
     */
    private function logStep(string $label, float $since, array $extra = []): float
    {
        $now = microtime(true);
        Log::info('[PropertyPdf] '.$label, array_merge([
            'token' => $this->logToken,
            'ms' => round(($now - $since) * 1000, 1),
        ], $extra));

        return $now;
    }

    /**
     * Get PDF templates from constants.
     */
    public function getTemplates()
    {
        $templates = [];
        foreach (Constants::PDF_TEMPLATES as $slug => $name) {
            $templates[] = [
                'value' => $slug,
                'label' => $name,
            ];
        }

        return $templates;
    }

    /**
     * Generate PDF for a property using the specified template slug.
     */
    public function generatePdf(Property $property, string $templateSlug): \Barryvdh\DomPDF\PDF
    {
        // DomPDF decodes each embedded image into raw pixel buffers; multi-image
        // templates (brochure, gallery) blow past the default 256M quickly.
        @ini_set('memory_limit', '1024M');
        @set_time_limit(300);

        $this->logToken = substr(md5(uniqid('', true)), 0, 8);
        $start = microtime(true);
        $t = $start;
        Log::info('[PropertyPdf] generatePdf:start', [
            'token' => $this->logToken,
            'propertyId' => $property->id,
            'template' => $templateSlug,
        ]);

        $this->loadPropertyRelations($property);
        $t = $this->logStep('loadPropertyRelations', $t);

        $data = $this->buildTemplateData($property, $templateSlug);
        $t = $this->logStep('buildTemplateData (total)', $t);

        $bladeView = $this->resolveBladeView($templateSlug);
        $orientation = $this->getTemplateOrientation($templateSlug);

        $pdf = Pdf::loadView($bladeView, $data);
        $pdf->setPaper('a4', $orientation);
        $pdf->setOption([
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'defaultFont' => 'Helvetica',
        ]);
        $t = $this->logStep('loadView + setOptions', $t);

        $this->injectPageNumbers($pdf, $templateSlug);
        $this->logStep('injectPageNumbers', $t);

        $this->logStep('generatePdf:done (excludes output())', $start);

        return $pdf;
    }

    private function injectPageNumbers(\Barryvdh\DomPDF\PDF $pdf, string $templateSlug): void
    {
        $templatesWithPageNumbers = [
            Constants::PDF_TEMPLATE_COMPARABLE_NO_IMAGE,
            Constants::PDF_TEMPLATE_COMPARABLE_SMALL_LANDSCAPE,
            Constants::PDF_TEMPLATE_CMA,
        ];

        if (! in_array($templateSlug, $templatesWithPageNumbers, true)) {
            return;
        }

        // Force render so pages exist before page_text iterates over them.
        $pdf->render();

        $domPdf = $pdf->getDomPDF();
        $canvas = $domPdf->getCanvas();
        $fontMetrics = $domPdf->getFontMetrics();
        $font = $fontMetrics->getFont('Helvetica', 'normal');
        $size = 9;
        $color = [0.33, 0.33, 0.33];
        $sideMargin = 22;
        $bottomY = $canvas->get_height() - 18 - $size;
        $pageWidth = $canvas->get_width();

        // Left: generation date (m/d/y)
        $dateText = now()->format('m/d/y');
        $canvas->page_text($sideMargin, $bottomY, $dateText, $font, $size, $color);

        // Center: logged-in user's name + email (with trailing comma to match reference)
        $user = Auth::user();
        if ($user) {
            $userText = trim(($user->name ?? '').(! empty($user->email) ? ', '.$user->email.',' : ''));
            if ($userText !== '') {
                $userWidth = $fontMetrics->getTextWidth($userText, $font, $size);
                $canvas->page_text(
                    ($pageWidth - $userWidth) / 2,
                    $bottomY,
                    $userText,
                    $font,
                    $size,
                    $color
                );
            }
        }

        // Right: Page X of Y (placeholders substituted per-page by the adapter)
        $pageText = 'Page {PAGE_NUM} of {PAGE_COUNT}';
        // {PAGE_NUM}/{PAGE_COUNT} are substituted later; for width estimation,
        // use realistic max digits the placeholder will resolve to.
        $pageTextSample = str_replace(['{PAGE_NUM}', '{PAGE_COUNT}'], ['00', '00'], $pageText);
        $pageTextWidth = $fontMetrics->getTextWidth($pageTextSample, $font, $size);
        $canvas->page_text(
            $pageWidth - $sideMargin - $pageTextWidth,
            $bottomY,
            $pageText,
            $font,
            $size,
            $color
        );
    }

    /**
     * Get template orientation based on slug.
     */
    private function getTemplateOrientation(string $slug): string
    {
        $landscapeTemplates = [
            Constants::PDF_TEMPLATE_COMPARABLE_SMALL_LANDSCAPE,
        ];

        return in_array($slug, $landscapeTemplates) ? 'landscape' : 'portrait';
    }

    /**
     * Generate an image (PNG) for a property using the specified template.
     * Renders the same Blade template as HTML, then converts PDF page 1 to image.
     */
    public function generateImage(Property $property, string $templateSlug): string
    {
        $pdf = $this->generatePdf($property, $templateSlug);
        $pdfContent = $pdf->output();

        // Try Imagick first (best quality)
        if (extension_loaded('imagick')) {
            $imagick = new \Imagick;
            $imagick->setResolution(150, 150);
            $imagick->readImageBlob($pdfContent);
            $imagick->setImagePage(0, 0, 0, 0);
            $imagick->setImageFormat('png');

            return $imagick->getImageBlob();
        }

        // Fallback: use intervention/image to build a simple image card
        return $this->buildFallbackImage($property);
    }

    /**
     * Load all relations needed for PDF generation.
     */
    private function loadPropertyRelations(Property $property): void
    {
        $property->load([
            'mediaCompressed',
            'mediaThumbnails',
            'propertyCategories',
            'city',
            'community',
            'subCommunity',
            'buildingTower',
            'creator',
            'agent',
            'agent.meta',
            'creator.meta',
            'meta',
        ]);
    }

    /**
     * Resolve the Blade view name from template slug.
     */
    private function resolveBladeView(string $slug): string
    {
        $bladeView = 'pdf-templates.'.$slug;

        if (! view()->exists($bladeView)) {
            $bladeView = 'pdf-templates.a4_single_page_flyer';
        }

        return $bladeView;
    }

    /**
     * Build data array passed to Blade templates.
     */
    private function buildTemplateData(Property $property, string $templateSlug): array
    {
        // Only encode the image arrays the requested template actually renders.
        // Unknown slugs resolve to a4_single_page_flyer (gallery-only) in
        // resolveBladeView(), so treat them as needing just the gallery.
        $isKnownTemplate = array_key_exists($templateSlug, Constants::PDF_TEMPLATES);
        $needsGallery = ! $isKnownTemplate
            || in_array($templateSlug, self::TEMPLATE_IMAGE_REQUIREMENTS['gallery'], true);
        // Offplan sheets need the building image + developer/development logos.
        $needsOffplan = in_array($templateSlug, self::TEMPLATE_IMAGE_REQUIREMENTS['offplan'], true);
        // Brochures need the full image gallery + floor plans.
        $needsBrochure = in_array($templateSlug, self::TEMPLATE_IMAGE_REQUIREMENTS['brochure'], true);
        // Only some templates render the listing QR. Unknown slugs fall back to
        // a4_single_page_flyer, which does render it, so they need it too.
        $needsQr = ! $isKnownTemplate
            || in_array($templateSlug, self::TEMPLATE_IMAGE_REQUIREMENTS['qr'], true);

        $images = $property->mediaCompressed
            ->where('mediaType', 'uploadPhotos')
            ->sortBy('ordering')
            ->values();

        $flyerScopedTemplates = [
            Constants::PDF_TEMPLATE_A4_SHOP_FLYER,
            Constants::PDF_TEMPLATE_A4_SHOP_FLYER_HIGH_QUALITY,
            Constants::PDF_TEMPLATE_A4_SINGLE_PAGE_FLYER,
            Constants::PDF_TEMPLATE_OFFPLAN_FACT_SHEET,
            Constants::PDF_TEMPLATE_OFFPLAN_FACT_SHEET_USER,
        ];
        if (in_array($templateSlug, $flyerScopedTemplates, true)) {
            $flyerSelected = $images->where('isFlyer', true)->values();
            if ($flyerSelected->isNotEmpty()) {
                $images = $flyerSelected;
            }
        }

        $floorPlans = $property->mediaCompressed
            ->where('mediaType', 'floorPlans')
            ->sortBy('ordering')
            ->values();

        $heroImage = $images->first();

        $metaPluck = $property->relationLoaded('meta')
            ? $property->meta->pluck('metaValue', 'metaKey')
            : $property->meta()->pluck('metaValue', 'metaKey');

        $unitTypes = [];
        $unitTypesRaw = $metaPluck->get('unitTypes');
        if (! empty($unitTypesRaw)) {
            $decoded = is_array($unitTypesRaw) ? $unitTypesRaw : json_decode($unitTypesRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $bedroom = $row['unitType'] ?? null;
                    $area = $row['builtUpArea'] ?? null;
                    $price = $row['priceStartingFrom'] ?? null;

                    if (blank($bedroom) && blank($area) && blank($price)) {
                        continue;
                    }

                    $unitTypes[] = [
                        'bedroom' => $bedroom,
                        'area' => $area,
                        'price' => $price,
                    ];
                }
            }
        }

        if (empty($unitTypes)) {
            foreach (range(1, 6) as $n) {
                $bedroom = $metaPluck->get("unitType{$n}");
                $area = $metaPluck->get("unitTypeBuildUpArea{$n}");
                $price = $metaPluck->get("unitTypePrice{$n}");

                if (blank($bedroom) && blank($area) && blank($price)) {
                    continue;
                }

                $unitTypes[] = [
                    'bedroom' => $bedroom,
                    'area' => $area,
                    'price' => $price,
                ];
            }
        }

        // Completion quarter from spaceDetails.completionDate (e.g. "Q2 2026").
        $completionQuarter = null;
        $spaceDetailsRaw = $metaPluck->get('spaceDetails');
        if (! empty($spaceDetailsRaw)) {
            $spaceDecoded = is_array($spaceDetailsRaw) ? $spaceDetailsRaw : json_decode($spaceDetailsRaw, true);
            $dateStr = is_array($spaceDecoded) ? ($spaceDecoded['completionDate'] ?? null) : null;
            if (! empty($dateStr)) {
                try {
                    $dt = new \DateTimeImmutable($dateStr);
                    $quarter = (int) ceil(((int) $dt->format('n')) / 3);
                    $completionQuarter = 'Q'.$quarter.' '.$dt->format('Y');
                } catch (\Throwable $e) {
                    $completionQuarter = null;
                }
            }
        }

        // Timestamp for per-block image-encoding timing below.
        $bt = microtime(true);

        // Scan media tags for developer / development logos. First match wins.
        // Only the offplan fact sheets render these, so skip the scan otherwise.
        $developerLogoSrc = null;
        $developmentLogoSrc = null;
        if ($needsOffplan) {
            foreach ($property->mediaCompressed as $mediaFile) {
                $rawTags = $mediaFile->tags ?? [];
                if (! is_array($rawTags) || empty($rawTags)) {
                    continue;
                }
                $tags = array_map(fn ($t) => trim((string) $t), $rawTags);

                // Accept both the normalised key (developerLogo) and the raw Salesforce label
                // (Developer Logo) so listings tagged either way — legacy CRM edits or the
                // salesforce:import-image-tags backfill — still resolve the logo.
                if ($developerLogoSrc === null && (in_array('developerLogo', $tags, true) || in_array('Developer Logo', $tags, true))) {
                    $developerLogoSrc = $this->getImageBase64($mediaFile->originalPath ?? $mediaFile->filePath, 400);
                }
                if ($developmentLogoSrc === null && (in_array('developmentLogo', $tags, true) || in_array('Development Logo', $tags, true))) {
                    $developmentLogoSrc = $this->getImageBase64($mediaFile->originalPath ?? $mediaFile->filePath, 400);
                }

                if ($developerLogoSrc !== null && $developmentLogoSrc !== null) {
                    break;
                }
            }
            $bt = $this->logStep('encode: developer/development logos', $bt);
        }

        $amenities = [];
        if (is_array($property->pfAmenities)) {
            $amenities = $property->pfAmenities;
        }
        if (is_array($property->bayutAmenities)) {
            $amenities = array_unique(array_merge($amenities, $property->bayutAmenities));
        }

        // Map amenity slugs to human-readable labels (PF first, then Bayut, fallback to humanized slug).
        $amenityLabels = [];
        foreach ($amenities as $slug) {
            $label = Constants::PF_AMENITIES['all'][$slug]
                ?? Constants::PROPERTY_BAYUT_AMENITIES_KEYS[$slug]
                ?? ucwords(trim(str_replace(['-', '_'], ' ', preg_replace('/([a-z])([A-Z])/', '$1 $2', (string) $slug))));
            $amenityLabels[] = $label;
        }

        $flyerData = $metaPluck->get('flyerData');
        $locationProximityText = [];
        $flyerHighlights = [];
        $paymentPlanYears = $projectROI = $shortDescription = null;

        $flyerTitle = null;
        if (! empty($flyerData)) {
            $fData = json_decode($flyerData, true);

            $flyerTitle = (isset($fData['developmentName']) && ! empty(trim((string) $fData['developmentName'])))
                ? trim((string) $fData['developmentName'])
                : null;

            // Flyer Highlight 1-5, in order, skipping blanks. Kept separate from $amenities so the
            // existing amenity/keyFeature data stays untouched for the templates that rely on it.
            // Symbols an agent may have typed are dropped so the bullets stay clean; commas,
            // periods and slashes are left alone ("24/7 Security" must survive intact).
            for ($i = 1; $i <= 5; $i++) {
                $highlight = str_replace(
                    ['!', '@', '#', '$', '%', '^', '&', '*', '(', ')', '~', '>', '<', '{', '}', '[', ']', '?'],
                    '',
                    (string) ($fData["flyerHighlight{$i}"] ?? '')
                );
                // Removing a symbol can leave a double space behind ("Sea & View" -> "Sea  View").
                $highlight = trim(preg_replace('/\s+/', ' ', $highlight));
                if ($highlight !== '') {
                    $flyerHighlights[] = $highlight;
                }
            }

            $keyFeatures = ! empty($fData['keyFeatures'])
                ? array_map('trim', explode(',', $fData['keyFeatures']))
                : [];

            $keyFacilities = ! empty($fData['keyFacilities'])
                ? array_map('trim', explode(',', $fData['keyFacilities']))
                : [];

            $locationProximityText = ! empty($fData['locationProximity'])
                ? array_map('trim', explode(',', $fData['locationProximity']))
                : [];

            if (! empty($keyFeatures)) {
                $amenities = $keyFeatures;
                // $amenities = array_unique(array_merge($amenities, $keyFeatures));
            }
            if (! empty($keyFacilities)) {
                $amenityLabels = $keyFacilities;
                // $amenityLabels = array_unique(array_merge($amenityLabels, $keyFacilities));
            }

            $paymentPlanYears = (isset($fData['paymentPlanYears']) && ! empty($fData['paymentPlanYears']))
                ? $fData['paymentPlanYears'] : null;

            $projectROI = (isset($fData['projectROI']) && ! empty($fData['projectROI']))
                ? $fData['projectROI'] : null;

            $shortDescription = (isset($fData['shortDescription']) && ! empty($fData['shortDescription']))
                ? $fData['shortDescription'] : ((isset($fData['templateDescription']) && ! empty($fData['templateDescription']))
                ? $fData['templateDescription'] : null);
        }

        $propertyCountry = ! empty($metaPluck->get('propertyCountry')) ? $metaPluck->get('propertyCountry') : '';

        // Map caption: derive the place name from the coordinates the user
        // entered (reverse geocode). Only fall back to the community name when
        // no coordinates were entered, or the lookup returns nothing.
        $numericCoord = fn ($v) => is_numeric($v) ? $v : null;
        $captionLat = $numericCoord($metaPluck->get('propertyLat'))
            ?? $numericCoord($metaPluck->get('flyerLatitude'))
            ?? $numericCoord($property->pfLatitude);
        $captionLng = $numericCoord($metaPluck->get('propertyLong'))
            ?? $numericCoord($metaPluck->get('flyerLongitude'))
            ?? $numericCoord($property->pfLongitude);
        $communityLabel = trim((string) ($property->community?->name ?? ''));
        $mapLabel = ($captionLat && $captionLng)
            ? ($this->reverseGeocodeLabel((float) $captionLat, (float) $captionLng) ?: $communityLabel)
            : $communityLabel;

        // $agent = $property->agent ?? $property->creator;
        if (in_array($templateSlug, Constants::PDF_AGENT_SCOPED_TEMPLATES, true)) {
            $agent = $property->agent ?? $property->creator;
        } else {
            $agent = user();
        }

        $prefetch = [];
        $ref = fn ($img) => $img ? ($img->originalPath ?? $img->filePath) : null;

        if ($needsGallery || $needsOffplan) {
            foreach ($images->slice(1, 5) as $img) {
                $prefetch[] = $ref($img);
            }
        }
        if ($needsOffplan) {
            foreach ([4, 3, 2, 1, 0] as $idx) {
                $prefetch[] = $ref($images->get($idx));
            }
        }
        if ($needsBrochure) {
            foreach ($images->take(self::MAX_BULK_IMAGES) as $img) {
                $prefetch[] = $ref($img);
            }
            foreach ($floorPlans->take(self::MAX_BULK_IMAGES) as $fp) {
                $prefetch[] = $ref($fp);
            }
        }
        $agentPhotoRef = $agent ? ($agent->profilePhoto ?: $agent->thumbnailPath) : null;
        $prefetch[] = $agentPhotoRef;
        $prefetch[] = $needsQr ? ($property->govtQR ?: null) : null;

        $this->prefetchRawBytes($prefetch);
        $bt = $this->logStep('prefetch images (parallel)', $bt, ['count' => count(array_filter($prefetch))]);

        // Build gallery images as base64 (sidebar images for flyer layouts).
        // Use originalPath (unwatermarked) — compressedPath has a watermark baked in.
        $galleryImagesSrc = [];
        if ($needsGallery || $needsOffplan) {
            // $needsOffplan included because the building fallback below reuses
            // an already-encoded gallery image.
            foreach ($images->slice(1, 5) as $img) {
                $src = $this->getImageBase64($img->originalPath ?? $img->filePath);
                if ($src) {
                    $galleryImagesSrc[] = $src;
                }
            }
            $bt = $this->logStep('encode: gallery', $bt, ['count' => count($galleryImagesSrc)]);
        }

        // Dedicated building image — encoded separately (with different maxWidth)
        // so DomPDF receives a unique base64 string and won't reuse cache from gallery/hero.
        // Walk preferred indices first, then any remaining image — guarantees a render
        // whenever the property has at least one photo whose file resolves on disk.
        $buildingImageSrc = null;
        if ($needsOffplan) {
            $candidateOrder = [4, 3, 2, 1, 0];
            foreach ($candidateOrder as $idx) {
                $candidate = $images->get($idx);
                if (! $candidate) {
                    continue;
                }
                $buildingImageSrc = $this->getImageBase64(
                    $candidate->originalPath ?? $candidate->filePath,
                    900
                );
                if ($buildingImageSrc) {
                    break;
                }
            }
            // Last-resort fallback: reuse an already-encoded gallery image so the
            // section never shows the empty navy placeholder when photos exist.
            if (! $buildingImageSrc && ! empty($galleryImagesSrc)) {
                $buildingImageSrc = end($galleryImagesSrc);
            }
            $bt = $this->logStep('encode: building image', $bt);
        }

        // Build all images as base64 for templates that need full gallery.
        // Capped at MAX_BULK_IMAGES so a property with dozens of photos doesn't
        // balloon render time / memory.
        $allImagesSrc = [];
        if ($needsBrochure) {
            foreach ($images->take(self::MAX_BULK_IMAGES) as $img) {
                $src = $this->getImageBase64($img->originalPath ?? $img->filePath);
                if ($src) {
                    $allImagesSrc[] = $src;
                }
            }
            $bt = $this->logStep('encode: allImages', $bt, ['count' => count($allImagesSrc)]);
        }

        // Build floor plan images as base64 (also capped at MAX_BULK_IMAGES).
        $floorPlansSrc = [];
        if ($needsBrochure) {
            foreach ($floorPlans->take(self::MAX_BULK_IMAGES) as $fp) {
                $src = $this->getImageBase64($fp->originalPath ?? $fp->filePath);
                if ($src) {
                    $floorPlansSrc[] = $src;
                }
            }
            $bt = $this->logStep('encode: floorPlans', $bt, ['count' => count($floorPlansSrc)]);
        }

        // Company details from global settings
        $company = $this->getCompanyDetails();

        $listingBadge = strtoupper($property->listingType ?? 'sale') === 'RENT' ? 'FOR RENT' : 'FOR SALE';

        // Build the location map image. Prefer Google Static Maps (English labels
        // in Dubai); fall back to OpenStreetMap only if Google is unavailable
        // (missing API key, quota, or request failure) so the PDF still renders.
        $mapImageSrc = $this->buildGoogleStaticMap($property) ?? $this->buildOsmStaticMap($property);
        $bt = $this->logStep('buildStaticMap', $bt);

        $flyerLocation = joinNonEmpty([
            $property->buildingTower?->name,
            $property->subCommunity?->name,
            $property->community?->name,
        ]);

        // A zero-bedroom unit is a studio (the Salesforce feed sends literal "Studio" and
        // resolveBedrooms() stores it as 0), so it reads "Studio" rather than "0 bed".
        $bedroomsSpec = $property->bedrooms === null
            ? '- bed'
            : ((int) $property->bedrooms === 0 ? 'Studio' : (int) $property->bedrooms.' bed');

        // Commercial listings have no bed/bath count (PropertyService waives those
        // fields for them), so brochures and flyers omit the specs entirely.
        $isCommercial = $property->category === Constants::PROPERTY_CATEGORY_COMMERCIAL;

        // Permit artwork (DLD) needs the real unit type and furnishing. There is no
        // propertyType/furnishing column: the type is pfPropertyType (fallback Bayut),
        // and furnishing lives in property meta (pfFurnishingType / bayutFurnishingStatus).
        $permitType = $property->pfPropertyType ?: $property->bayutPropertyType;
        $propertyTypeLabel = $permitType ? ucwords(str_replace('-', ' ', $permitType)) : null;

        $furnishingRaw = $metaPluck->get('pfFurnishingType') ?: $metaPluck->get('bayutFurnishingStatus');
        $furnishingLabel = $furnishingRaw
            ? (Constants::PROPERTY_FURNISHING_TYPE[$furnishingRaw] ?? ucwords(str_replace('-', ' ', $furnishingRaw)))
            : null;

        // Security deposit & agency fee are amounts (AED). Agency fee shows its
        // percentage alongside the amount when both are recorded.
        $money = fn ($v) => ($v !== null && $v !== '' && (float) $v > 0) ? 'AED '.number_format((float) $v) : null;
        $securityDeposit = $money($property->deposit);
        $agencyFee = $money($property->agencyFee);
        if ($agencyFee && $property->agencyFeePercent && (float) $property->agencyFeePercent > 0) {
            $agencyFee .= ' ('.rtrim(rtrim(number_format((float) $property->agencyFeePercent, 2), '0'), '.').'%)';
        }

        // Primary view — the property's actual `view` field (NOT an amenity),
        // humanised for display (e.g. "burj_khalifa" -> "Burj Khalifa").
        $primaryView = $property->view
            ? ucwords(str_replace(['_', '-'], ' ', (string) $property->view))
            : null;

        $templateData = [
            'property' => $property,
            'propertyTypeLabel' => $propertyTypeLabel,
            'furnishingLabel' => $furnishingLabel,
            'securityDeposit' => $securityDeposit,
            'agencyFee' => $agencyFee,
            'primaryView' => $primaryView,
            'isCommercial' => $isCommercial,
            'templateSlug' => $templateSlug,
            'templateName' => Constants::PDF_TEMPLATES[$templateSlug] ?? $templateSlug,
            'images' => $images,
            'floorPlans' => $floorPlans,
            'heroImage' => $heroImage,
            'amenities' => $amenities,
            'flyerHighlights' => $flyerHighlights,
            'flyerLocation' => $flyerLocation,
            'bedroomsSpec' => $bedroomsSpec,
            'agent' => $agent,
            'listingBadge' => $listingBadge,
            'logoPath' => $this->getLogoBase64('white'),       // white logo (dark bg) - backward compat
            'logoWhitePath' => $this->getLogoBase64('white'),  // white logo (dark bg)
            'logoDarkPath' => $this->getLogoBase64('dark'),    // colored logo (light bg)
            'logoStackedPath' => $this->getLogoBase64('stacked'), // stacked logo variant
            'developerLogoSrc' => $developerLogoSrc,
            'developmentLogoSrc' => $developmentLogoSrc,
            'unitTypes' => $unitTypes,
            'amenityLabels' => $amenityLabels,
            'locationProximityText' => $locationProximityText,
            'projectROI' => $projectROI,
            'shortDescription' => $shortDescription,
            'paymentPlanYears' => $paymentPlanYears,
            'propertyCountry' => $propertyCountry,
            'completionQuarter' => $completionQuarter,
            'flyerTitle' => $flyerTitle ?: $property->title,
            'heroImageSrc' => $heroImage ? $this->getImageBase64($heroImage->originalPath ?? $heroImage->filePath) : null,
            'agentProfile' => $this->getImageBase64($agentPhotoRef, 600),
            'govtQRSrc' => $needsQr && $property->govtQR ? $this->getImageBase64($property->govtQR) : null,
            'agentDescription' => $agent?->meta?->where('metaKey', '_description')->first()?->metaValue,
            'agentPhone' => $agent?->phone ?? $agent?->meta?->where('metaKey', '_phone')->first()?->metaValue,
            'agentBrn' => $agent?->meta?->firstWhere('metaKey', '_licenseNumber')?->metaValue,
            'bedIconSrc' => $this->getSvgIconBase64('bed'),
            'bathIconSrc' => $this->getSvgIconBase64('bath'),
            'instaIconSrc' => $this->getSocialIconBase64('insta'),
            'facebookIconSrc' => $this->getSocialIconBase64('facebook'),
            'twitterIconSrc' => $this->getSocialIconBase64('twitter'),
            'youtubeIconSrc' => $this->getSocialIconBase64('youtube'),
            'linkedinIconSrc' => $this->getSocialIconBase64('linkedin'),
            'galleryImagesSrc' => $galleryImagesSrc,
            'buildingImageSrc' => $buildingImageSrc,
            'allImagesSrc' => $allImagesSrc,
            'floorPlansSrc' => $floorPlansSrc,
            'mapImageSrc' => $mapImageSrc,
            'mapLabel' => $mapLabel,
            'company' => $company,
            'pdfUser' => Auth::user(),
            'pdfGeneratedAt' => now(),
        ];
        // The array literal above lazily ran logo/hero/agent/QR/icon encodes.
        $this->logStep('encode: logos + hero + agent + qr + icons', $bt);

        // Request is done with image encoding — clear the per-request memos so
        // they never leak into a subsequent render (the service may be reused).
        $this->imageBase64Cache = [];
        $this->rawBytesCache = [];
        $this->logoBase64Cache = [];

        return $templateData;
    }

    private function reverseGeocodeLabel(float $lat, float $lng): ?string
    {
        $cacheKey = 'osm_revgeo:'.md5($lat.','.$lng);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === '' ? null : $cached;
        }

        $url = sprintf(
            'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=%F&lon=%F&zoom=14&addressdetails=1&accept-language=en',
            $lat,
            $lng
        );
        $context = stream_context_create([
            'http' => [
                'timeout' => 6,
                'header' => "User-Agent: AppendixCRM-PDF/1.0 (contact@exclusive-links.com)\r\n"
                    ."Accept-Language: en\r\n",
            ],
        ]);

        $label = null;
        try {
            $json = @file_get_contents($url, false, $context);
            $data = $json ? json_decode($json, true) : null;
            if (is_array($data)) {
                $addr = $data['address'] ?? [];
                $label = $addr['neighbourhood']
                    ?? $addr['suburb']
                    ?? $addr['city_district']
                    ?? $addr['quarter']
                    ?? $addr['residential']
                    ?? $addr['town']
                    ?? $addr['village']
                    ?? $addr['city']
                    ?? ($data['name'] ?? null);
                if (! $label && ! empty($data['display_name'])) {
                    $label = trim(explode(',', $data['display_name'])[0]);
                }
                $label = $label ? trim((string) $label) : null;
            }
        } catch (\Throwable $e) {
            $label = null;
        }

        // Cache the result (empty-string sentinel for "no match") for 30 days.
        Cache::put($cacheKey, $label ?? '', now()->addDays(30));

        return $label;
    }

    /**
     * Resolve the listing's map coordinates from (in order): flyer/property meta,
     * the PF lat/lng, then the building / sub-community / community fallback.
     * Returns [lat, lng] (either may be null when nothing is set).
     */
    private function resolveMapCoordinates(Property $property): array
    {
        $meta = $property->relationLoaded('meta')
            ? $property->meta->pluck('metaValue', 'metaKey')
            : $property->meta()->pluck('metaValue', 'metaKey');
        $numeric = fn ($v) => is_numeric($v) ? $v : null;
        $metaLat = $numeric($meta->get('propertyLat')) ?? $numeric($meta->get('flyerLatitude'));
        $metaLng = $numeric($meta->get('propertyLong')) ?? $numeric($meta->get('flyerLongitude'));

        $lat = $metaLat
            ?: ($property->pfLatitude
            ?: ($property->buildingTower->latitude
            ?? $property->subCommunity->latitude
            ?? $property->community->latitude
            ?? null));
        $lng = $metaLng
            ?: ($property->pfLongitude
            ?: ($property->buildingTower->longitude
            ?? $property->subCommunity->longitude
            ?? $property->community->longitude
            ?? null));

        return [$lat, $lng];
    }

    /**
     * Build the location map image via the Google Maps Static API and return it
     * as a base64 PNG data URI. Renders labels in English (language=en, region=AE),
     * which OpenStreetMap does not for Dubai. Returns null when no API key is
     * configured, coordinates are missing, or the request fails — the caller then
     * falls back to the OSM tile map.
     */
    private function buildGoogleStaticMap(Property $property): ?string
    {
        $key = config('services.google_maps.static_key');
        if (! $key) {
            return null;
        }

        [$lat, $lng] = $this->resolveMapCoordinates($property);
        if (! $lat || ! $lng) {
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;
        $zoom = 15;
        // Static Maps standard tier caps each dimension at 640; scale=2 doubles the
        // pixel density. 502x640 keeps the portrait aspect the PDF map page expects.
        $width = 502;
        $height = 640;

        $cacheKey = 'gmap_static:'.md5($lat.','.$lng.','.$zoom.','.$width.'x'.$height);
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        try {
            $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/staticmap', [
                'center' => "{$lat},{$lng}",
                'zoom' => $zoom,
                'size' => "{$width}x{$height}",
                'scale' => 2,
                'maptype' => 'roadmap',
                'language' => 'en',
                'region' => 'AE',
                'markers' => "color:red|{$lat},{$lng}",
                'key' => $key,
            ]);

            // Google returns 200 with an image on success; errors come back as a
            // non-image body (e.g. "The Google Maps Platform server rejected...").
            if ($response->failed() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
                Log::warning('Google Static Map request failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 300),
                ]);

                return null;
            }

            $dataUri = 'data:image/png;base64,'.base64_encode($response->body());
            Cache::put($cacheKey, $dataUri, now()->addDays(7));

            return $dataUri;
        } catch (\Throwable $e) {
            Log::warning('Google Static Map exception: '.$e->getMessage());

            return null;
        }
    }

    private function buildOsmStaticMap(Property $property): ?string
    {
        [$lat, $lng] = $this->resolveMapCoordinates($property);

        if (! $lat || ! $lng || ! extension_loaded('gd')) {
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;
        $zoom = 15;
        $viewportW = 800;
        $viewportH = 1020;

        $cacheKey = 'osm_map:'.md5($lat.','.$lng.','.$zoom.','.$viewportW.'x'.$viewportH);
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        try {
            $dataUri = $this->stitchOsmTiles($lat, $lng, $zoom, $viewportW, $viewportH);
        } catch (\Throwable $e) {
            return null;
        }

        if ($dataUri) {
            Cache::put($cacheKey, $dataUri, now()->addDays(7));
        }

        return $dataUri;
    }

    /**
     * Stitch OpenStreetMap tiles centered on (lat, lng) into a single PNG and return a base64 data URI.
     * Uses the standard slippy-map projection; draws a red marker pin at the center.
     */
    private function stitchOsmTiles(float $lat, float $lng, int $zoom, int $width, int $height): ?string
    {
        $tileSize = 256;
        $n = 2 ** $zoom;

        // Project lat/lng to fractional tile coords (slippy map).
        $latRad = deg2rad($lat);
        $xTileF = ($lng + 180.0) / 360.0 * $n;
        $yTileF = (1.0 - log(tan($latRad) + (1 / cos($latRad))) / M_PI) / 2.0 * $n;

        // Center pixel in world pixel-space, then viewport bounds.
        $centerPxX = $xTileF * $tileSize;
        $centerPxY = $yTileF * $tileSize;
        $left = $centerPxX - $width / 2;
        $top = $centerPxY - $height / 2;

        $tileXStart = (int) floor($left / $tileSize);
        $tileYStart = (int) floor($top / $tileSize);
        $tileXEnd = (int) floor(($left + $width - 1) / $tileSize);
        $tileYEnd = (int) floor(($top + $height - 1) / $tileSize);

        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 230, 230, 230));

        $originX = (int) round($tileXStart * $tileSize - $left);
        $originY = (int) round($tileYStart * $tileSize - $top);

        $context = stream_context_create([
            'http' => [
                'timeout' => 6,
                'header' => "User-Agent: AppendixCRM-PDF/1.0 (contact@exclusive-links.com)\r\n",
            ],
        ]);

        $maxTile = $n - 1;
        for ($ty = $tileYStart; $ty <= $tileYEnd; $ty++) {
            if ($ty < 0 || $ty > $maxTile) {
                continue;
            }
            for ($tx = $tileXStart; $tx <= $tileXEnd; $tx++) {
                $wrappedX = (($tx % $n) + $n) % $n;
                $url = sprintf('https://tile.openstreetmap.org/%d/%d/%d.png', $zoom, $wrappedX, $ty);
                $png = @file_get_contents($url, false, $context);
                if ($png === false || $png === '') {
                    continue;
                }
                $tile = @imagecreatefromstring($png);
                if (! $tile) {
                    continue;
                }
                $dx = $originX + ($tx - $tileXStart) * $tileSize;
                $dy = $originY + ($ty - $tileYStart) * $tileSize;
                imagecopy($canvas, $tile, $dx, $dy, 0, 0, $tileSize, $tileSize);
                imagedestroy($tile);
            }
        }

        $this->drawMapMarker($canvas, (int) round($width / 2), (int) round($height / 2));

        ob_start();
        imagepng($canvas);
        $png = ob_get_clean();
        imagedestroy($canvas);

        if (! $png) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($png);
    }

    /**
     * Draw a simple teardrop marker pin whose tip sits at ($x, $y).
     */
    private function drawMapMarker($canvas, int $x, int $y): void
    {
        $red = imagecolorallocate($canvas, 220, 40, 40);
        $darkRed = imagecolorallocate($canvas, 120, 20, 20);
        $white = imagecolorallocate($canvas, 255, 255, 255);

        $headCy = $y - 18;
        imagefilledellipse($canvas, $x, $headCy, 22, 22, $red);
        imageellipse($canvas, $x, $headCy, 22, 22, $darkRed);

        $tail = [
            $x - 7,
            $headCy + 6,
            $x + 7,
            $headCy + 6,
            $x,
            $y,
        ];
        imagefilledpolygon($canvas, $tail, $red);

        imagefilledellipse($canvas, $x, $headCy, 8, 8, $white);
    }

    /**
     * Get company details from global settings with sensible defaults.
     */
    private function getCompanyDetails(): array
    {
        $keys = [
            '_websiteTitle',
            '_facebookLink',
            '_instagramLink',
            '_twitterLink',
            '_youtubeLink',
            '_linkedinLink',
        ];
        $settings = GlobalSetting::whereIn('settingKey', $keys)
            ->pluck('settingValue', 'settingKey');

        $website = 'https://www.exclusive-links.com';

        return [
            'name' => $settings['_websiteTitle'] ?? 'Exclusive Links',
            'phone' => '+971 4 399 4937',
            'website' => 'www.exclusive-links.com',
            'tagline' => 'The Complete Property Experience',
            'services' => 'sales | leasing | management | vacation homes',
            'social' => [
                'facebook' => $this->normalizeUrl($settings['_facebookLink'] ?? null, $website),
                'instagram' => $this->normalizeUrl($settings['_instagramLink'] ?? null, $website),
                'twitter' => $this->normalizeUrl($settings['_twitterLink'] ?? null, $website),
                'youtube' => $this->normalizeUrl($settings['_youtubeLink'] ?? null, $website),
                'linkedin' => $this->normalizeUrl($settings['_linkedinLink'] ?? null, $website),
            ],
        ];
    }

    /**
     * Normalize a URL for DomPDF link rendering: trims whitespace, treats blank
     * values as missing, and prefixes a protocol so the PDF link is clickable.
     * DomPDF only emits a real /URI annotation when href starts with http(s)://.
     */
    private function normalizeUrl(?string $url, string $fallback): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            $url = $fallback;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.ltrim($url, '/');
        }

        return $url;
    }

    /**
     * Fallback image generator using intervention/image when Imagick is not available.
     * Creates an A4-sized (2480x3508 @ 300dpi) property card image.
     */
    private function buildFallbackImage(Property $property): string
    {
        $manager = new ImageManager(new Driver);

        // A4 size at 150dpi = 1240x1754
        $width = 1240;
        $height = 1754;

        $canvas = $manager->create($width, $height)->fill('ffffff');

        // Header bar
        $canvas->drawRectangle(0, 0, function ($draw) use ($width) {
            $draw->size($width, 80);
            $draw->background('00002a');
        });

        // Gold line
        $canvas->drawRectangle(0, 80, function ($draw) use ($width) {
            $draw->size($width, 4);
            $draw->background('c9a84c');
        });

        // Hero image
        $heroImg = $property->mediaCompressed
            ->where('mediaType', 'uploadPhotos')
            ->sortBy('ordering')
            ->first();

        $yOffset = 84;

        if ($heroImg) {
            $heroBytes = $this->fileStorage->readBytes($heroImg->originalPath ?? $heroImg->filePath);

            if ($heroBytes !== null) {
                $hero = $manager->read($heroBytes);
                $hero->cover($width, 500);
                $canvas->place($hero, 'top-left', 0, $yOffset);
                $yOffset += 500;
            }
        }

        $yOffset += 30;

        // Title
        $canvas->text($property->title ?? 'Untitled Property', 40, $yOffset, function ($font) {
            $font->size(36);
            $font->color('00002a');
        });

        $yOffset += 50;

        // Location
        $location = $property->community?->community ?? '';
        if ($property->city) {
            $location .= ($location ? ', ' : '').$property->city->location;
        }
        if ($location) {
            $canvas->text($location, 40, $yOffset, function ($font) {
                $font->size(22);
                $font->color('666666');
            });
            $yOffset += 40;
        }

        // Price
        $canvas->text('AED '.number_format($property->price ?? 0), 40, $yOffset, function ($font) {
            $font->size(32);
            $font->color('00002a');
        });

        $yOffset += 60;

        // Specs
        // A zero-bedroom unit is a Studio (see resolveBedrooms), and the real unit
        // type is pfPropertyType (fallback Bayut) — there is no propertyType column.
        $bedroomsText = $property->bedrooms === null
            ? 'N/A'
            : ((int) $property->bedrooms === 0 ? 'Studio' : (int) $property->bedrooms);
        $typeRaw = $property->pfPropertyType ?: $property->bayutPropertyType;
        $typeText = $typeRaw ? ucwords(str_replace('-', ' ', $typeRaw)) : 'N/A';

        $specs = [
            'Bedrooms: '.$bedroomsText,
            'Bathrooms: '.(formatDecimal($property->bathrooms) ?? 'N/A'),
            'Size: '.($property->sizeArea ? number_format($property->sizeArea).' sq.ft' : 'N/A'),
            'Furnishing: '.ucfirst(
                optional($property->meta?->firstWhere('metaKey', 'bayutFurnishingStatus'))->metaValue
                    ?? optional($property->meta?->firstWhere('metaKey', 'pfFurnishingType'))->metaValue
                    ?? 'N/A'
            ),
            'Type: '.$typeText,
            'Ref: '.($property->referenceNumber ?? 'N/A'),
        ];

        foreach ($specs as $spec) {
            $canvas->text($spec, 40, $yOffset, function ($font) {
                $font->size(20);
                $font->color('555555');
            });
            $yOffset += 35;
        }

        // Footer bar
        $canvas->drawRectangle(0, $height - 60, function ($draw) use ($width) {
            $draw->size($width, 60);
            $draw->background('00002a');
        });

        $canvas->text('Exclusive Links Real Estate | exclusive-links.com', 40, $height - 30, function ($font) {
            $font->size(16);
            $font->color('ffffff');
        });

        return (string) $canvas->toPng();
    }

    /**
     * Read raw bytes for a reference, preferring the per-request batch-warmed
     * cache so the (already completed) parallel S3 fetch is reused. Anything not
     * pre-warmed is read directly and memoized for the rest of the request.
     */
    private function readBytesCached(?string $reference): ?string
    {
        if (empty($reference)) {
            return null;
        }

        if (array_key_exists($reference, $this->rawBytesCache)) {
            return $this->rawBytesCache[$reference];
        }

        return $this->rawBytesCache[$reference] = $this->fileStorage->readBytes($reference);
    }

    /**
     * Fetch every given storage reference in one parallel batch and store the
     * raw bytes in $rawBytesCache, so the subsequent per-image encode loops read
     * already-downloaded bytes instead of issuing a sequential S3 request each.
     * This is the single biggest lever for multi-image templates: the dozens of
     * S3 downloads a brochure needs run concurrently rather than one after
     * another. Output is unchanged — only the fetch ordering differs.
     */
    private function prefetchRawBytes(array $references): void
    {
        $missing = [];
        foreach ($references as $ref) {
            if (! empty($ref) && ! array_key_exists($ref, $this->rawBytesCache)) {
                $missing[] = $ref;
            }
        }

        if (empty($missing)) {
            return;
        }

        $fetched = $this->fileStorage->readManyBytes($missing);
        foreach ($fetched as $ref => $bytes) {
            $this->rawBytesCache[$ref] = $bytes;
        }
    }

    /**
     * Convert a storage image path to a base64 data URI for embedding in PDF.
     * Large raster images are downscaled so DomPDF doesn't exhaust memory
     * decoding them into raw pixel buffers.
     */
    private function getImageBase64(?string $path, int $maxWidth = 1200): ?string
    {
        if (empty($path)) {
            return null;
        }

        // Per-request memo: a single render references the same photo from
        // several sections (allImages, gallery, hero, building). Encode once.
        $memoKey = $path.'|'.$maxWidth;
        if (array_key_exists($memoKey, $this->imageBase64Cache)) {
            return $this->imageBase64Cache[$memoKey];
        }

        $bytes = $this->readBytesCached($path);

        if ($bytes === null) {
            return $this->imageBase64Cache[$memoKey] = null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';

        // Only try to resize raster formats that Intervention/GD handles well.
        $resizable = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);

        if ($resizable) {
            try {
                $manager = new ImageManager(new Driver);
                $image = $manager->read($bytes);

                if ($image->width() > $maxWidth) {
                    $image->scaleDown(width: $maxWidth);
                }

                $encoded = (string) $image->toJpeg(quality: 82);

                return $this->imageBase64Cache[$memoKey] = 'data:image/jpeg;base64,'.base64_encode($encoded);
            } catch (\Throwable $e) {
                // Fall through to raw embed if Intervention fails for any reason.
            }
        }

        return $this->imageBase64Cache[$memoKey] = "data:{$mime};base64,".base64_encode($bytes);
    }

    /**
     * Downscale a logo image to a print-appropriate width while preserving PNG
     * transparency, returning a base64 data URI. Company logos are routinely
     * stored at 1000px+ (100KB+ PNGs) but render at only 50-130px in the PDF.
     * Embedding them full-size forces DomPDF to decode a huge alpha PNG on every
     * page footer — ~1s of render time each, which dominated the brochure. A
     * 500px source is visually identical at display size and an order of
     * magnitude cheaper to rasterise. toPng() keeps the alpha channel, so the
     * logo stays transparent on dark backgrounds (the JPEG path in
     * getImageBase64() would strip it and leave a visible block).
     */
    private function downscaleLogoToDataUri(string $bytes, int $maxWidth = 500): string
    {
        // Raw GD with PNG compression level 1 (fast) rather than Intervention's
        // toPng() (max compression) — the latter is ~6x slower for no benefit
        // here, and on the slow live CPU that re-encode would itself cost a
        // second+ per render. Alpha is preserved so the logo stays transparent.
        try {
            $src = @imagecreatefromstring($bytes);
            if ($src === false) {
                throw new \RuntimeException('not a decodable raster');
            }

            $w = imagesx($src);
            $h = imagesy($src);

            if ($w > $maxWidth) {
                $nw = $maxWidth;
                $nh = (int) round($h * $maxWidth / $w);

                $dst = imagecreatetruecolor($nw, $nh);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocatealpha($dst, 0, 0, 0, 127));
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($src);
            } else {
                // Already small enough — keep as-is but normalise to PNG.
                $dst = $src;
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }

            ob_start();
            imagepng($dst, null, 1);
            $png = ob_get_clean();
            imagedestroy($dst);

            return 'data:image/png;base64,'.base64_encode($png);
        } catch (\Throwable $e) {
            // Fall back to the raw embed if GD fails for any reason.
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/png';

            return "data:{$mime};base64,".base64_encode($bytes);
        }
    }

    /**
     * Get the company logo as base64 data URI.
     *
     * @param  string  $variant  'white' for dark backgrounds, 'dark' for light backgrounds
     */
    private function getLogoBase64(string $variant = 'white'): ?string
    {
        // Logos are referenced several times per render (e.g. logoPath and
        // logoWhitePath are both 'white'); encode each variant once per request.
        if (array_key_exists($variant, $this->logoBase64Cache)) {
            return $this->logoBase64Cache[$variant];
        }

        // Setting keys: _appLogo (white/original), _appLogoDark (colored for light bg)
        $settingKey = $variant === 'dark' ? '_appLogoDark' : '_appLogo';
        $setting = GlobalSetting::where('settingKey', $settingKey)->first();

        // Try dynamic logo from settings first. Downscale (keeping alpha) so a
        // large source PNG doesn't make DomPDF crawl on every footer.
        if ($setting && ! empty($setting->settingValue)) {
            $bytes = $this->readBytesCached($setting->settingValue);
            if ($bytes !== null) {
                return $this->logoBase64Cache[$variant] = $this->downscaleLogoToDataUri($bytes);
            }
        }

        // Fallback to static files in public/icons/
        // logo.png = white (for dark backgrounds), logo-dark.png = colored (for light backgrounds), logo-stacked.png = stacked variant
        $staticFile = match ($variant) {
            'dark' => 'logo-dark.png',
            'stacked' => 'logo-stacked.png',
            default => 'logo.png',
        };
        $staticPath = public_path('icons/'.$staticFile);

        if (file_exists($staticPath)) {
            return $this->logoBase64Cache[$variant] = $this->downscaleLogoToDataUri(file_get_contents($staticPath));
        }

        // Final fallback: logo.png
        if ($variant === 'dark') {
            $defaultPath = public_path('icons/logo.png');
            if (file_exists($defaultPath)) {
                return $this->logoBase64Cache[$variant] = $this->downscaleLogoToDataUri(file_get_contents($defaultPath));
            }
        }

        return $this->logoBase64Cache[$variant] = null;
    }

    /**
     * Convert a local file path to base64 data URI.
     */
    private function fileToBase64(string $filePath): ?string
    {
        if (! file_exists($filePath)) {
            return null;
        }

        $mime = mime_content_type($filePath);
        $data = base64_encode(file_get_contents($filePath));

        return "data:{$mime};base64,{$data}";
    }

    /**
     * Get SVG icon as base64 data URI for PDF embedding.
     */
    private function getSvgIconBase64(string $icon): string
    {
        // Simple SVGs (rectangles/lines only) — DomPDF renders these reliably,
        // unlike complex multi-curve FontAwesome paths.
        $svgs = [
            'bed' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 40" fill="white">
                <rect x="0" y="2" width="4" height="38"/>
                <rect x="0" y="28" width="64" height="8"/>
                <rect x="14" y="14" width="46" height="14" rx="3"/>
                <rect x="6" y="18" width="10" height="10" rx="2"/>
            </svg>',
            'bath' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 40" fill="white">
                <rect x="0" y="20" width="64" height="4"/>
                <path d="M2 24 L2 30 Q2 36 8 36 L56 36 Q62 36 62 30 L62 24 Z"/>
                <rect x="10" y="8" width="14" height="12" rx="3" fill="none" stroke="white" stroke-width="3"/>
                <rect x="6" y="36" width="3" height="4"/>
                <rect x="55" y="36" width="3" height="4"/>
            </svg>',
        ];

        $svg = $svgs[$icon] ?? '';
        $encoded = base64_encode($svg);

        return "data:image/svg+xml;base64,{$encoded}";
    }

    /**
     * Load a social-media icon from public/icons/{name}.svg as a base64 data URI.
     * Used by PDF templates so DomPDF embeds the icon inline (its built-in URL
     * loader doesn't reliably resolve relative paths during render).
     */
    private function getSocialIconBase64(string $name): ?string
    {
        $path = public_path('icons/'.$name.'.svg');
        if (! file_exists($path)) {
            return null;
        }

        $svg = file_get_contents($path);
        if ($svg === false || $svg === '') {
            return null;
        }

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Auto-generate and store PDFs for a property if it has website media files.
     * Generates one PDF per active template, stored as:
     * propertymediapdf/{propertyId}/{template_slug}/{template_slug}.pdf
     */
    public function autoGeneratePdfForWebsite(int $propertyId): void
    {
        $hasWebsiteMedia = PropertyMediaFile::where('propertyId', $propertyId)
            // ->where('isWebsite', true)
            ->where('mediaType', 'uploadPhotos')
            ->orderBy('ordering')
            ->exists();
        // Delete existing auto-generated PDFs first
        $this->deleteAutoGeneratedPdfs($propertyId);

        if (! $hasWebsiteMedia) {
            return;
        }

        $property = Property::find($propertyId);
        if (! $property) {
            return;
        }

        foreach (Constants::PDF_TEMPLATES as $slug => $name) {
            $pdf = $this->generatePdf($property, $slug);

            $storagePath = "propertymediapdf/{$propertyId}/{$slug}/{$slug}.pdf";
            $this->fileStorage->putPublic($storagePath, $pdf->output());
        }
    }

    /**
     * Delete all auto-generated PDFs for a property.
     */
    public function deleteAutoGeneratedPdfs(int $propertyId): void
    {
        $this->fileStorage->deletePublicDirectory("propertymediapdf/{$propertyId}");
    }
}
