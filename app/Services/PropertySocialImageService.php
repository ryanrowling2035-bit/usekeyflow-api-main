<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\GlobalSetting;
use App\Models\Property;
use Illuminate\Support\Facades\Http;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

class PropertySocialImageService
{
    private ImageManager $manager;

    private string $navy = '0a1628';

    private string $gold = 'c9a84c';

    private const MAX_PUBLIC_READ_BYTES = 10485760; // 10 MB

    /** @var string[] Temp files downloaded from S3 during a single render. */
    private array $tempFiles = [];

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver);
    }

    /**
     * Get available social image platforms.
     */
    public function getPlatforms(): array
    {
        $platforms = [];
        foreach (Constants::SOCIAL_IMAGE_PLATFORMS as $key => $config) {
            $platforms[] = [
                'value' => $key,
                'label' => $config['label'],
                'width' => $config['width'],
                'height' => $config['height'],
            ];
        }

        return $platforms;
    }

    /**
     * Generate a social image for the given property and platform.
     */
    public function generateImage(Property $property, string $bannerTitle): string
    {
        $property->load(['mediaCompressed']);

        $width = 1080;
        $height = 1350;

        try {
            $imagePaths = $this->getAllImagePaths($property);
            $title = $property->title ?? 'Untitled Property';

            return $this->buildSocialImage($title, $width, $height, $property, $bannerTitle);
        } finally {
            $this->cleanupTempFiles();
        }
        // return match ($platform) {
        //     Constants::SOCIAL_PLATFORM_INSTAGRAM => $this->buildInstagram($title, $width, $height, $property, $bannerTitle),
        //     Constants::SOCIAL_PLATFORM_LINKEDIN => $this->buildLinkedIn($title, $width, $height, $property, $bannerTitle),
        //     Constants::SOCIAL_PLATFORM_PRINT_A4 => $this->buildPrintA4($title, $width, $height, $property, $bannerTitle),
        //     default => $this->buildInstagram($title, $width, $height, $property, $bannerTitle),
        // };
    }

    /**
     * Social Image — 1080x1350.
     */
    private function buildSocialImage(string $title, int $width, int $height, $property, $bannerTitle): string
    {
        $canvas = $this->manager->create($width, $height)->fill('#000000');
        $this->placeBackgroundImage($canvas, $property, $width, $height);
        $this->drawDarkOverlay($canvas, $width, $height);

        $config = $this->resolveBannerConfigSocial($bannerTitle, $property);

        if ($bannerTitle !== 'exclusive_listing') {
            $this->getSocialHeader($canvas, $property);
        }

        $formatted_title = strtoupper(str_replace(' ', "\n", $config['title_text']));
        $this->drawText($canvas, $formatted_title, $config['title_x'], $config['title_y'], $config['title_size'], $config['title_align'], 'BodoniModa_9pt-Medium.ttf', 1.2);
        $this->drawText($canvas, $config['property_text'], 110, $config['property_y'], 30, 'left', 'Montserrat-Medium.ttf', 1.8);

        if (! empty($config['extra_fn'])) {
            ($config['extra_fn'])($canvas);
        }

        $this->getSocialFooter($canvas, $property, $bannerTitle);

        return (string) $canvas->toJpeg();
    }

    /**
     * Instagram — 1080x1080 square.
     * Layout: header(logo+company) | banner(1st) | title bar | gallery grid(2nd+) | footer
     */
    private function buildInstagram(string $title, int $width, int $height, $property, $bannerTitle): string
    {
        $canvas = $this->manager->create($width, $height)->fill('#000000');
        $this->placeBackgroundImage($canvas, $property, $width, $height);
        $this->drawDarkOverlay($canvas, $width, $height);

        $config = $this->resolveBannerConfigInsta($bannerTitle, $property);

        if ($bannerTitle !== 'exclusive_listing') {
            $this->getInstaHeader($canvas, $property);
        }

        $formatted_title = strtoupper(str_replace(' ', "\n", $config['title_text']));
        $this->drawText($canvas, $formatted_title, $config['title_x'], $config['title_y'], 110, $config['title_align'], 'BodoniModa_9pt-Medium.ttf', 1.2);
        $this->drawText($canvas, $config['property_text'], 110, $config['property_y'], 24, 'left', 'Montserrat-Medium.ttf', 1.7);

        if (! empty($config['extra_fn'])) {
            ($config['extra_fn'])($canvas);
        }

        $this->getInstaFooter($canvas, $property);

        return (string) $canvas->toJpeg();
    }

    /**
     * LinkedIn — 1200x627 landscape.
     * Layout: header | banner(1st) | title bar | gallery grid(2nd+) | footer
     */
    private function buildLinkedIn(string $title, int $width, int $height, $property, $bannerTitle): string
    {
        $canvas = $this->manager->create($width, $height)->fill('#000000');

        $this->placeBackgroundImage($canvas, $property, $width, $height);
        $this->drawDarkOverlay($canvas, $width, $height);

        $config = $this->resolveBannerConfigLinkedIn($bannerTitle, $property);

        if ($bannerTitle !== 'exclusive_listing') {
            $this->getLinkedInHeader($canvas, $property);
        }

        $formatted_title = strtoupper(str_replace(' ', "\n", $config['title_text']));
        $this->drawText($canvas, $formatted_title, $config['title_x'], $config['title_y'], 62, $config['title_align'], 'BodoniModa_9pt-Medium.ttf', 1.2);
        $this->drawText($canvas, $config['property_text'], 121, $config['property_y'], 14, 'left', 'Montserrat-Medium.ttf', 1.9);

        if (! empty($config['extra_fn'])) {
            ($config['extra_fn'])($canvas);
        }

        $this->getLinkedInFooter($canvas, $property);

        return (string) $canvas->toJpeg();
    }

    /**
     * Print A4 — 2480x3508 high-res portrait.
     * Layout: header | banner(1st) | title bar | gallery grid(2nd+, 2 columns) | footer
     */
    private function buildPrintA4(string $title, int $width, int $height, $property, $bannerTitle): string
    {
        $canvas = $this->manager->create($width, $height)->fill('#000000');

        $this->placeBackgroundImage($canvas, $property, $width, $height);
        $this->drawDarkOverlay($canvas, $width, $height);

        $config = $this->resolveBannerConfigA4($bannerTitle, $property);

        if ($bannerTitle !== 'exclusive_listing') {
            $this->getPrint4Header($canvas, $property);
        }

        $formatted_title = strtoupper(str_replace(' ', "\n", $config['title_text']));
        $this->drawText($canvas, $formatted_title, $config['title_x'], $config['title_y'], 400, $config['title_align'], 'BodoniModa_9pt-Medium.ttf', 1.2);
        $this->drawText($canvas, $config['property_text'], 253, $config['property_y'], 60, 'left', 'Montserrat-Medium.ttf', 1.7);

        if (! empty($config['extra_fn'])) {
            ($config['extra_fn'])($canvas);
        }

        $this->getPrint4Footer($canvas, $property);

        return (string) $canvas->toJpeg();
    }

    private function resolveBannerConfigA4(string $bannerTitle, $property): array
    {
        $communityName = $property->sub_community?->name ?? $property->community?->name ?? '';
        $city = $property->city?->name ?? '';
        $price = number_format($property->price);
        $sqft = number_format($property->sizeArea);

        $locationOnly = joinNonEmpty([$communityName, $city]);
        $fullProperty = "$locationOnly\nAED $price";

        // Defaults
        $config = [
            'title_text' => Constants::SOCIAL_IMAGE_TITLE[$bannerTitle],
            'title_x' => 253,
            'title_y' => 750,
            'title_align' => 'left',
            'property_text' => $fullProperty,
            'property_y' => 1770,
            'extra_fn' => null,
        ];

        switch ($bannerTitle) {
            case 'sold':
            case 'rented':
                $config['title_y'] = 960;
                $config['property_y'] = 1350;
                $config['property_text'] = $locationOnly;
                break;

            case 'sale_agreed':
                $config['property_y'] = 1550;
                $config['property_text'] = $locationOnly;
                break;

            case 'price_reduced_sale':
            case 'price_reduced_rent':
                $tag = ($bannerTitle === 'price_reduced_sale') ? 'FOR SALE' : 'FOR RENT';
                $config['title_text'] = 'Price Reduced';
                $config['extra_fn'] = function ($canvas) use ($tag) {
                    $this->drawText($canvas, $tag, 253, 1690, 60, 'left', 'Montserrat-Medium.ttf', 1.7);
                    $this->placeSaleTagImage($canvas, 150, 750, 190, 200);
                };
                break;

            case 'exclusive_listing':
                $bedsText = $this->buildSpecsText($property->bedrooms, $property->bathrooms, $sqft);
                $tag = ($property->listingType === Constants::PROPERTY_PURPOSE_RENT) ? 'FOR RENT' : 'FOR SALE';
                $config['title_align'] = 'center';
                $config['title_x'] = 1240;
                $config['title_y'] = 550;
                $config['property_y'] = 2240;
                $config['extra_fn'] = function ($canvas) use ($bedsText, $tag) {
                    $this->drawText($canvas, $bedsText, 253, 2395, 60, 'left', 'Montserrat-Medium.ttf', 1.7);
                    $this->drawText($canvas, $tag, 1240, 1320, 60, 'center', 'Montserrat-Medium.ttf', 1.7);
                    $this->placeHeaderLogo($canvas, 915, 160, 650, 250, 'white');
                };
                break;
        }

        return $config;
    }

    private function resolveBannerConfigInsta(string $bannerTitle, $property): array
    {
        $communityName = $property->sub_community?->name ?? $property->community?->name ?? '';
        $city = $property->city?->name ?? '';
        $price = number_format($property->price);
        $sqft = number_format($property->sizeArea);

        $locationOnly = joinNonEmpty([$communityName, $city]);
        $fullProperty = "$locationOnly\nAED $price";

        // Defaults
        $config = [
            'title_text' => Constants::SOCIAL_IMAGE_TITLE[$bannerTitle],
            'title_x' => 110,
            'title_y' => 245,
            'title_align' => 'left',
            'property_text' => $fullProperty,
            'property_y' => 545,
            'extra_fn' => null,
        ];

        switch ($bannerTitle) {
            case 'sold':
            case 'rented':
                $config['title_y'] = 300;
                $config['property_y'] = 420;
                $config['property_text'] = $locationOnly;
                break;

            case 'sale_agreed':
                $config['property_y'] = 475;
                $config['property_text'] = $locationOnly;
                break;

            case 'price_reduced_sale':
            case 'price_reduced_rent':
                $tag = ($bannerTitle === 'price_reduced_sale') ? 'FOR SALE' : 'FOR RENT';
                $config['title_text'] = 'Price Reduced';
                $config['extra_fn'] = function ($canvas) use ($tag) {
                    $this->drawText($canvas, $tag, 110, 515, 24, 'left', 'Montserrat-Medium.ttf', 1.7);
                    $this->placeSaleTagImage($canvas, 75, 245, 55, 58);
                };
                break;

            case 'exclusive_listing':
                $bedsText = $this->buildSpecsText($property->bedrooms, $property->bathrooms, $sqft);
                $tag = ($property->listingType === Constants::PROPERTY_PURPOSE_RENT) ? 'FOR RENT' : 'FOR SALE';
                $config['title_align'] = 'center';
                $config['title_x'] = 540;
                $config['title_y'] = 175;
                $config['property_y'] = 655;
                $config['extra_fn'] = function ($canvas) use ($bedsText, $tag) {
                    $this->drawText($canvas, $bedsText, 110, 715, 24, 'left', 'Montserrat-Medium.ttf', 1.7);
                    $this->drawText($canvas, $tag, 540, 395, 26, 'center', 'Montserrat-Medium.ttf', 1.7);
                    $this->placeHeaderLogo($canvas, 355, 40, 370, 100, 'white');
                };
                break;
        }

        return $config;
    }

    private function getInstaHeader($canvas, $property)
    {
        $sqft = number_format($property->sizeArea);
        $text = $this->buildSpecsText($property->bedrooms, $property->bathrooms, $sqft);
        $this->drawText($canvas, $text, 110, 115, 24, 'left', 'Montserrat-Medium.ttf', 1.7);

        $this->placeHeaderLogo($canvas, 625, 115, 370, 80, 'white');

    }

    private function getInstaFooter($canvas, $property)
    {
        if (! empty($property->agent)) {
            $agent = $property->agent;
            if (! empty($agent->profilePhoto)) {
                $agentImage = $agent->profilePhoto;
                $agentImagePath = $this->resolveStoragePath($agentImage);
                if ($agentImage && file_exists($agentImagePath)) {
                    $agentCanva = $this->manager->read($agentImagePath)
                        ->resize(210, 210);

                    // $agent = $this->circleMask($agent, 200);

                    $canvas->place($agentCanva, 'top-left', 110, 810);
                }
            }

            $x_axis = (isset($agentImage)) ? 350 : 110;
            $agentName = $agent->name ?? '';
            $agentPhone = $agent->phone ?? '';
            $agentBrn = $agent->meta->firstWhere('metaKey', '_licenseNumber')?->metaValue ?? '';
            $agentText = "$agentName\n$agentPhone\nBRN: $agentBrn";
            $this->drawText($canvas, $agentText, $x_axis, 936, 24, 'left', 'Montserrat-Medium.ttf', 1.7);
        }

        if (! empty($property->govtQR)) {
            $govtQRPath = $this->resolveStoragePath($property->govtQR);
            if ($property->govtQR && file_exists($govtQRPath)) {
                $qr = $this->manager->read($govtQRPath)
                    ->resize(130, 130);

                $canvas->place($qr, 'top-left', 850, 885);
            }
        }
    }

    private function resolveBannerConfigSocial(string $bannerTitle, $property): array
    {
        $communityName = $property->sub_community?->name ?? $property->community?->name ?? '';
        $city = $property->city?->name ?? '';
        $price = number_format($property->price);

        $locationOnly = joinNonEmpty([$communityName, $city]);
        $locationOnly = strtoupper($locationOnly);
        $fullProperty = "$locationOnly\nAED $price";

        // Defaults
        $config = [
            'title_text' => Constants::SOCIAL_IMAGE_TITLE[$bannerTitle],
            'title_x' => 110,
            'title_y' => 306,
            'title_size' => 162,
            'title_align' => 'left',
            'property_text' => $fullProperty,
            'property_y' => 680,
            'extra_fn' => null,
        ];

        switch ($bannerTitle) {
            case 'sold':
            case 'rented':
                $config['title_y'] = 370;
                $config['title_size'] = 169;
                $config['property_y'] = 526;
                $config['property_text'] = $locationOnly;
                break;

            case 'sale_agreed':
                $config['property_y'] = 612;
                $config['property_text'] = $locationOnly;
                break;

            case 'just_listed':
                $config['title_y'] = 290;
                $config['title_size'] = 165;
                break;

            case 'price_reduced_sale':
            case 'price_reduced_rent':
                $tag = ($bannerTitle === 'price_reduced_sale') ? 'FOR SALE' : 'FOR RENT';
                $config['title_text'] = 'Price Reduced';
                $config['title_y'] = 288;
                $config['property_y'] = 721;
                $config['extra_fn'] = function ($canvas) use ($tag) {
                    $this->drawText($canvas, $tag, 110, 680, 30, 'left', 'Montserrat-Medium.ttf', 1.8);
                    $this->placeSaleTagImage($canvas, 65, 285, 75, 80);
                };
                break;

            case 'exclusive_listing':
                $bedsText = $this->buildSocialSpecsText($property);
                $tag = ($property->listingType === Constants::PROPERTY_PURPOSE_RENT) ? 'FOR RENT' : 'FOR SALE';
                $config['title_align'] = 'center';
                $config['title_x'] = 540;
                $config['title_y'] = 214;
                $config['title_size'] = 137;
                $config['property_y'] = 814;
                $config['extra_fn'] = function ($canvas) use ($bedsText, $tag) {
                    $this->drawText($canvas, $bedsText, 110, 896, 30, 'left', 'Montserrat-Medium.ttf', 1.8);
                    $this->drawText($canvas, $tag, 540, 488, 30, 'center', 'Montserrat-Medium.ttf', 1.8);
                    $this->placeHeaderLogo($canvas, 410, 48, 260, 120, 'white');
                };
                break;
        }

        return $config;
    }

    private function getSocialHeader($canvas, $property)
    {
        $text = $this->buildSocialSpecsText($property);
        $this->drawText($canvas, $text, 110, 113, 30, 'left', 'Montserrat-Medium.ttf', 1.8);
        $this->placeHeaderLogo($canvas, 760, 80, 258, 115, 'white');

    }

    private function getSocialFooter($canvas, $property, $bannerTitle)
    {
        $agent = user();

        if (! empty($agent)) {
            if (! empty($agent->profilePhoto)) {
                $agentImage = $agent->profilePhoto;
                $agentImagePath = $this->resolveStoragePath($agentImage);
                if ($agentImage && file_exists($agentImagePath)) {
                    $agentCanva = $this->roundedImage($agentImagePath, 262, 35);

                    $canvas->place($agentCanva, 'top-left', 110, 1022);
                }
            }

            $x_axis = (isset($agentImage)) ? 404 : 110;
            $agentName = $agent->name ?? '';
            $agentPhone = $agent->phone ? $this->formatUAEPhone($agent->phone) : '';
            $agentBrn = $agent->meta->firstWhere('metaKey', '_licenseNumber')?->metaValue ?? '';
            $agentText = "$agentName\n$agentPhone\nBRN: $agentBrn";
            $this->drawText($canvas, $agentText, $x_axis, 1171, 30, 'left', 'Montserrat-Medium.ttf', 1.8);
        }

        if ($bannerTitle == 'rented' || $bannerTitle == 'sold') {
            return; // Skip QR for sold and rented as per original design
        }

        if (! empty($property->govtQR)) {
            $govtQRPath = $this->resolveStoragePath($property->govtQR);
            if ($property->govtQR && file_exists($govtQRPath)) {
                $qr = $this->manager->read($govtQRPath)
                    ->resize(164, 164);

                $canvas->place($qr, 'top-left', 833, 1120);
            }
        }
    }

    private function formatUAEPhone($phone)
    {
        $clean = preg_replace('/\D/', '', $phone);

        return '+'.
            substr($clean, 0, 3).' '.
            substr($clean, 3, 2).' '.
            substr($clean, 5, 3).' '.
            substr($clean, 8, 4);
    }

    private function roundedImage($imagePath, $size = 262, $radius = 35)
    {
        $src = imagecreatefromstring(file_get_contents($imagePath));

        $final = imagecreatetruecolor($size, $size);

        imagealphablending($final, false);
        imagesavealpha($final, true);

        $transparent = imagecolorallocatealpha($final, 0, 0, 0, 127);
        imagefill($final, 0, 0, $transparent);

        $mask = imagecreatetruecolor($size, $size);

        imagealphablending($mask, false);
        imagesavealpha($mask, true);

        $maskTransparent = imagecolorallocatealpha($mask, 0, 0, 0, 127);
        imagefill($mask, 0, 0, $maskTransparent);

        $white = imagecolorallocate($mask, 255, 255, 255);

        imagefilledrectangle($mask, $radius, 0, $size - $radius, $size, $white);
        imagefilledrectangle($mask, 0, $radius, $size, $size - $radius, $white);

        imagefilledellipse($mask, $radius, $radius, $radius * 2, $radius * 2, $white);
        imagefilledellipse($mask, $size - $radius, $radius, $radius * 2, $radius * 2, $white);
        imagefilledellipse($mask, $radius, $size - $radius, $radius * 2, $radius * 2, $white);
        imagefilledellipse($mask, $size - $radius, $size - $radius, $radius * 2, $radius * 2, $white);

        imagecopyresampled(
            $final,
            $src,
            0,
            0,
            0,
            0,
            $size,
            $size,
            imagesx($src),
            imagesy($src)
        );

        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {

                $maskPixel = imagecolorat($mask, $x, $y);

                if ($maskPixel == $maskTransparent) {
                    imagesetpixel($final, $x, $y, $transparent);
                }
            }
        }

        return $this->manager->read($final);
    }

    private function resolveBannerConfigLinkedIn(string $bannerTitle, $property): array
    {
        $communityName = $property->sub_community?->name ?? $property->community?->name ?? '';
        $city = $property->city?->name ?? '';
        $price = number_format($property->price);
        $sqft = number_format($property->sizeArea);

        $locationOnly = joinNonEmpty([$communityName, $city]);
        $fullProperty = "$locationOnly\nAED $price";

        // Defaults
        $config = [
            'title_text' => Constants::SOCIAL_IMAGE_TITLE[$bannerTitle],
            'title_x' => 121,
            'title_y' => 140,
            'title_align' => 'left',
            'property_text' => $fullProperty,
            'property_y' => 312,
            'extra_fn' => null,
        ];

        switch ($bannerTitle) {
            case 'sold':
            case 'rented':
                $config['title_y'] = 170;
                $config['property_y'] = 230;
                $config['property_text'] = $locationOnly;
                break;

            case 'sale_agreed':
                $config['property_y'] = 265;
                $config['property_text'] = $locationOnly;
                break;

            case 'price_reduced_sale':
            case 'price_reduced_rent':
                $tag = ($bannerTitle === 'price_reduced_sale') ? 'FOR SALE' : 'FOR RENT';
                $config['title_text'] = 'Price Reduced';
                $config['extra_fn'] = function ($canvas) use ($tag) {
                    $this->drawText($canvas, $tag, 121, 290, 14, 'left', 'Montserrat-Medium.ttf', 1.9);
                    $this->placeSaleTagImage($canvas, 93, 139, 41, 37);
                };
                break;

            case 'exclusive_listing':
                $bedsText = $this->buildSpecsText($property->bedrooms, $property->bathrooms, $sqft);
                $tag = ($property->listingType === Constants::PROPERTY_PURPOSE_RENT) ? 'FOR RENT' : 'FOR SALE';
                $config['title_align'] = 'center';
                $config['title_x'] = 600;
                $config['title_y'] = 125;
                $config['property_y'] = 365;
                $config['extra_fn'] = function ($canvas) use ($bedsText, $tag) {
                    $this->drawText($canvas, $bedsText, 121, 402, 14, 'left', 'Montserrat-Medium.ttf', 1.9);
                    $this->drawText($canvas, $tag, 600, 250, 16, 'center', 'Montserrat-Medium.ttf', 1.9);
                    $this->placeHeaderLogo($canvas, 450, 45, 300, 65, 'white');
                };
                break;
        }

        return $config;
    }

    private function getLinkedInHeader($canvas, $property)
    {
        $sqft = number_format($property->sizeArea);
        $text = $this->buildSpecsText($property->bedrooms, $property->bathrooms, $sqft);
        $this->drawText($canvas, $text, 121, 51, 14, 'left', 'Montserrat-Medium.ttf', 1.9);

        $this->placeHeaderLogo($canvas, 810, 51, 270, 40, 'white');

    }

    private function getLinkedInFooter($canvas, $property)
    {
        if (! empty($property->agent)) {
            $agent = $property->agent;
            if (! empty($agent->profilePhoto)) {
                $agentImage = $agent->profilePhoto;
                $agentImagePath = $this->resolveStoragePath($agentImage);
                if ($agentImage && file_exists($agentImagePath)) {
                    $agentCanva = $this->manager->read($agentImagePath)
                        ->resize(135, 135);

                    // $agent = $this->circleMask($agent, 200);

                    $canvas->place($agentCanva, 'top-left', 121, 455);
                }
            }

            $x_axis = (isset($agentImage)) ? 280 : 121;
            $agentName = $agent->name ?? '';
            $agentPhone = $agent->phone ?? '';
            $agentBrn = $agent->meta->firstWhere('metaKey', '_licenseNumber')?->metaValue ?? '';
            $agentText = "$agentName\n$agentPhone\nBRN: $agentBrn";
            $this->drawText($canvas, $agentText, $x_axis, 540, 14, 'left', 'Montserrat-Medium.ttf', 1.9);
        }

        if (! empty($property->govtQR)) {
            $govtQRPath = $this->resolveStoragePath($property->govtQR);
            if ($property->govtQR && file_exists($govtQRPath)) {
                $qr = $this->manager->read($govtQRPath)
                    ->resize(76, 76);

                $canvas->place($qr, 'top-left', 1004, 510);
            }
        }
    }

    /**
     * Build the property specs block (bedrooms / bathrooms / BUA).
     *
     * Each line is trimmed so that a missing bedrooms/bathrooms count does not
     * leave a leading space, which would otherwise push those lines out of
     * left-alignment with the "BUA … SQFT" line.
     */
    /**
     * Specs block for the 1080x1350 social image.
     *
     * Commercial listings have no meaningful bedroom/bathroom counts, so they get
     * the BUA line only.
     */
    private function buildSocialSpecsText($property): string
    {
        $sqft = (string) (int) $property->sizeArea;

        if ($property->category === Constants::PROPERTY_CATEGORY_COMMERCIAL) {
            return "BUA $sqft SQFT";
        }

        // Passed raw (like the other callers) so a null bedrooms stays blank instead of
        // casting to 0 and reading as a studio.
        return $this->buildSpecsText($property->bedrooms, $property->bathrooms, $sqft);
    }

    private function buildSpecsText($bedrooms, $bathrooms, string $sqft): string
    {
        $bedrooms = trim((string) $bedrooms);
        // Strip a trailing ".0" from the decimal bathrooms column (3.0 => "3", 3.5 => "3.5").
        $bathrooms = trim((string) formatDecimal($bathrooms));

        // A zero-bedroom unit is a studio (the Salesforce feed sends literal "Studio" and
        // resolveBedrooms() stores it as 0), so it reads "STUDIO APARTMENT" rather than "0 BEDROOMS".
        if ($bedrooms === '') {
            $bedLine = '';
        } elseif ((int) $bedrooms === 0) {
            $bedLine = 'STUDIO';
        } else {
            $bedLine = "$bedrooms BEDROOMS";
        }
        $bathLine = $bathrooms !== '' ? "$bathrooms BATHROOMS" : 'BATHROOMS';

        return "$bedLine\n$bathLine\nBUA $sqft SQFT";
    }

    private function drawText($canvas, string $text, int $x, int $y, int $size, string $align, string $fontFile, float $lineHeight = 1.5, string $color = '#ffffff'): void
    {
        $canvas->text($text, $x, $y, function ($font) use ($size, $align, $fontFile, $lineHeight, $color) {
            $font->file(storage_path("fonts/$fontFile"));
            $font->size($size);
            $font->color($color);
            $font->align($align);
            $font->valign('top');
            $font->lineHeight($lineHeight);
        });
    }

    private function placeHeaderLogo($canvas, int $x, int $y, int $resizeWidth, int $resizeHeight, string $variant)
    {
        $logoPath = public_path('images/social-image-logo.png');
        // $logoPath = $this->getLogoPath($variant);
        if ($logoPath && file_exists($logoPath)) {
            $logo = $this->manager->read($logoPath)->resize($resizeWidth, $resizeHeight);
            $canvas->place($logo, 'top-left', $x, $y);
        }
    }

    private function placeBackgroundImage($canvas, $property, int $width, int $height): void
    {
        $images = $this->getOriginalImage($property);
        $path = $images[0] ?? null;

        if ($path && file_exists($path)) {
            $canvas->place(
                $this->manager->read($path)->cover($width, $height),
                'top-left', 0, 0
            );
        }
    }

    private function drawDarkOverlay($canvas, int $width, int $height): void
    {
        $canvas->drawRectangle(0, 0, function ($draw) use ($width, $height) {
            $draw->size($width, $height);
            $draw->background('rgba(0,0,0,0.45)');
        });
    }

    private function placeSaleTagImage($canvas, $x, $y, $width, $height): void
    {
        $path = public_path('images/sale-tag.png');
        if (file_exists($path)) {
            $canvas->place(
                $this->manager->read($path)->resize($width, $height),
                'top-left', $x, $y
            );
        }
    }

    private function getPrint4Header($canvas, $property)
    {
        $sqft = number_format($property->sizeArea);
        $text = $this->buildSpecsText($property->bedrooms, $property->bathrooms, $sqft);
        $this->drawText($canvas, $text, 253, 286, 60, 'left', 'Montserrat-Medium.ttf', 1.7);
        $this->placeHeaderLogo($canvas, 1520, 286, 700, 250, 'white');
    }

    private function getPrint4Footer($canvas, $property)
    {
        if (! empty($property->agent)) {
            $agent = $property->agent;
            if (! empty($agent->profilePhoto)) {
                $agentImage = $agent->profilePhoto;
                $agentImagePath = $this->resolveStoragePath($agentImage);
                if ($agentImage && file_exists($agentImagePath)) {
                    $agentCanva = $this->manager->read($agentImagePath)
                        ->resize(650, 650);

                    // $agent = $this->circleMask($agent, 200);

                    $canvas->place($agentCanva, 'top-left', 253, 2660);
                }
            }

            $x_axis = (isset($agentImage)) ? 980 : 253;
            $agentName = $agent->name ?? '';
            $agentPhone = $agent->phone ?? '';
            $agentBrn = $agent->meta->firstWhere('metaKey', '_licenseNumber')?->metaValue ?? '';
            $agentText = "$agentName\n$agentPhone\nBRN: $agentBrn";
            $this->drawText($canvas, $agentText, $x_axis, 3050, 70, 'left', 'Montserrat-Medium.ttf', 1.7);
        }

        if (! empty($property->govtQR)) {
            $govtQRPath = $this->resolveStoragePath($property->govtQR);
            if ($property->govtQR && file_exists($govtQRPath)) {
                $qr = $this->manager->read($govtQRPath)
                    ->resize(415, 415);

                $canvas->place($qr, 'top-left', 1920, 2880);
            }
        }
    }

    private function getOriginalImage($property)
    {
        return $property->mediaCompressed
            ->where('mediaType', 'uploadPhotos')
            ->sortBy('ordering')
            ->values()
            ->map(fn ($img) => $this->resolveStoragePath($img->originalPath ?? $img->filePath))
            ->filter(fn ($path) => $path && file_exists($path))
            ->values()
            ->toArray();
    }
    // ================================================================
    // Helper methods
    // ================================================================

    private function getAllImagePaths(Property $property): array
    {
        return $property->mediaCompressed
            ->where('mediaType', 'uploadPhotos')
            ->sortBy('ordering')
            ->values()
            ->map(fn ($img) => $this->resolveStoragePath($img->compressedPath ?? $img->filePath))
            ->filter(fn ($path) => $path && file_exists($path))
            ->values()
            ->toArray();
    }

    private function placeImageAt($canvas, ?string $path, int $x, int $y, int $w, int $h): int
    {
        $w = max(1, $w);
        $h = max(1, $h);

        if ($path && file_exists($path)) {
            $img = $this->manager->read($path);
            $img->cover($w, $h);
            $canvas->place($img, 'top-left', $x, $y);
        } else {
            $this->drawRect($canvas, $x, $y, $w, $h, 'e8e8e8');
        }

        return $y + $h;
    }

    private function drawRect($canvas, int $x, int $y, int $w, int $h, string $color): void
    {
        $canvas->drawRectangle($x, $y, function ($draw) use ($w, $h, $color) {
            $draw->size($w, $h);
            $draw->background($color);
        });
    }

    private function drawOverlay($canvas, int $x, int $y, int $w, int $h, int $opacity = 170): void
    {
        $overlay = $this->manager->create($w, $h)->fill('0a1628');
        $canvas->place($overlay, 'top-left', $x, $y, $opacity);
    }

    private function drawSimpleFooter($canvas, int $width, int $height, int $footerH): void
    {
        $this->drawRect($canvas, 0, $height - $footerH, $width, $footerH, $this->navy);
        $this->drawRect($canvas, 0, $height - $footerH - 3, $width, 3, $this->gold);

        $this->placeLogo($canvas, 20, $height - $footerH + (int) (($footerH - 28) / 2), 28, 'white');

        $company = $this->getCompanyDetails();
        $canvas->text($company['website'], $width - 20, $height - (int) ($footerH / 2), function ($font) {
            $font->size(14);
            $font->color('cccccc');
            $font->align('right');
            $font->valign('middle');
        });
    }

    private function placeLogo($canvas, int $x, int $y, int $maxHeight, string $variant): void
    {
        $logoPath = $this->getLogoPath($variant);
        if ($logoPath && file_exists($logoPath)) {
            $logo = $this->manager->read($logoPath);
            $logo->scaleDown(height: $maxHeight);
            $canvas->place($logo, 'top-left', $x, $y);
        }
    }

    private function resolveStoragePath(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        $path = str_replace('\\/', '/', $path);

        // New S3-hosted images: download to a temp file so GD/Intervention and the
        // file_exists() guards keep working. Temp files are removed after rendering.
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $bytes = app(FileStorageService::class)->readBytes($path);
            $bytes ??= $this->readExternalPublicS3Url($path);

            if ($bytes === null) {
                return null;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'social_');
            file_put_contents($tmp, $bytes);
            $this->tempFiles[] = $tmp;

            return $tmp;
        }

        // Legacy local public-disk file.
        $relative = preg_replace('#^/?storage/#', '', $path);

        return storage_path('app/public/'.$relative);
    }

    private function readExternalPublicS3Url(string $url): ?string
    {
        if (! $this->isPublicS3Url($url)) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $bytes = $response->body();

        return strlen($bytes) <= self::MAX_PUBLIC_READ_BYTES ? $bytes : null;
    }

    private function isPublicS3Url(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host)) {
            return false;
        }

        $host = strtolower($host);

        return (bool) preg_match('/(^|\\.)s3([.-][a-z0-9-]+)?\\.amazonaws\\.com$/', $host);
    }

    private function cleanupTempFiles(): void
    {
        foreach ($this->tempFiles as $tmp) {
            if (is_string($tmp) && file_exists($tmp)) {
                @unlink($tmp);
            }
        }

        $this->tempFiles = [];
    }

    private function getLogoPath(string $variant = 'white'): ?string
    {
        $settingKey = $variant === 'dark' ? '_appLogoDark' : '_appLogo';
        $setting = GlobalSetting::where('settingKey', $settingKey)->first();

        if ($setting && ! empty($setting->settingValue)) {
            $resolved = $this->resolveStoragePath($setting->settingValue);
            if ($resolved && file_exists($resolved)) {
                return $resolved;
            }
        }

        $staticFile = $variant === 'dark' ? 'logo-dark.png' : 'logo.png';
        $staticPath = public_path('icons/'.$staticFile);

        if (file_exists($staticPath)) {
            return $staticPath;
        }

        return null;
    }

    private function getCompanyDetails(): array
    {
        $title = GlobalSetting::where('settingKey', '_websiteTitle')->first();

        return [
            'name' => $title?->settingValue ?? 'Exclusive Links',
            'website' => 'www.exclusive-links.com',
        ];
    }

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) > $max) {
            return mb_substr($text, 0, $max - 3).'...';
        }

        return $text;
    }
}
