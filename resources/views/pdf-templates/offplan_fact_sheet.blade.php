@extends('pdf-templates.pdf_layout')

@section('styles')
<style>
    html {
        background-color: #E8E8E8;
    }
    body {
        margin: 0;
        padding: 0;
        font-family: Helvetica, Arial, sans-serif;
        color: #000;
        background-color: #E8E8E8;
    }
    .logo-header {
        padding: 8px 10px 5px 10px;
        background-color: #ffffff;
    }
    .hero-image {
        width: 100%;
        height: 495px;
        object-fit: cover;
        display: block;
    }

    .completion-bar {
        background-color: #000033;
        padding: 15px 40px 15px;
        color: #ffffff;
    }

    .completion-text {
        font-size: 22px;
        font-weight: 400;
        color: #ffffff;
        border-left: 4px solid #ffffff;
        padding-left: 15px;
    }

    /* Page 2 */
    .page2-content {
        padding: 0;
        background-color: #E8E8E8;
    }
    .gallery-grid {
        width: 100%;
        border-collapse: collapse;
    }
    .gallery-grid td {
        width: 50%;
        vertical-align: top;
        padding: 0;
        background-color: #150442;
    }
    .gallery-grid img {
        width: 100%;
        height: 245px;
        object-fit: cover;
        display: block;
    }
    .section-label {
        font-size: 25px;
        font-weight: 400;
        color: #fff;
        padding: 10px 10px;
    }
    
    .facilities-section {
        padding: 0 25px 10px 25px;
    }
    .building-img {
        width: 100%;
        height: 220px;
        object-fit: cover;
    }
    .agent-footer {
        background-color: #ffffff;
    }
    .agent-footer table {
        width: 100%;
        background-color: #ffffff;
    }
    .agent-footer table {
        width: 100%;
    }
    .agent-photo {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        object-fit: cover;
    }
    .agent-name {
        font-size: 18px;
        color: #000000;
        margin-bottom: 3px;
    }
    .agent-phone {
        font-size: 18px;
        color: #000000;
        margin-bottom: 5px;
    }
    .agent-detail {
        font-size: 14px;
        color: #000;
        margin-bottom: 10px;
    }
    .company-info {
        font-size: 18px;
        color: #150442;
    }
    .permit-text {
        font-size: 12px;
        color: #555555;
    }
    .bottom-buttons {
        background-color: #0a1628;
        padding: 10px 25px;
    }
    .btn {
        display: inline-block;
        background-color: #0a1628;
        color: #ffffff;
        padding: 8px 20px;
        border-radius: 20px;
        font-size: 10px;
        font-weight: bold;
        border: 1px solid #ffffff;
    }

    .social-btn {
        background: #150442;
        color: #fff;
        padding: 13px 8px;
        border-radius: 10px;
        text-align: center;
        font-size: 16px;
        font-weight: 400;
    }


    .icon-img {
        width: 32px;
        height: 32px;
        padding: 8px;
        background: #150442;
        border-radius: 12px;
        margin: 6px 6px 0px;
        display: inline-block;
        vertical-align: middle;
        color: #fff;
    }
    a.icon-link,
    a.icon-link:link,
    a.icon-link:visited,
    a.icon-link:hover {
        display: inline-block;
        line-height: 0;
        vertical-align: middle;
        text-decoration: none;
        border: none;
        outline: none;
    }
    a.btn-link,
    a.btn-link:link,
    a.btn-link:visited,
    a.btn-link:hover {
        display: inline-block;
        text-decoration: none;
        border: none;
        outline: none;
        color: #fff;
    }
    .unit-summary-cell {
        width: 48%;
        padding: 18px 18px 18px 30px;
        vertical-align: top;
        background-color: #E8E8E8;
    }
    .unit-description-cell {
        width: 52%;
        padding: 18px 20px 18px 16px;
        vertical-align: top;
        background-color: #E8E8E8;
    }
    .payment-plan-box {
        /* border: 1px solid #150442; */
        border-radius: 15px;
        padding: 15px 15px;
        margin-bottom: 20px;
        color: #150442;
        background: #ffffff;
    }
    .payment-plan-type {
        font-size: 42px;
        font-weight: 700;
        line-height: 1;
        letter-spacing: 1px;
        display: inline-block;
        vertical-align: middle;
    }
    .payment-plan-label {
        font-size: 24px;
        font-weight: 400;
        display: inline-block;
        vertical-align: middle;
        margin-left: 8px;
    }
    .payment-plan-detail {
        /* border: 1px solid #150442; */
        border-radius: 15px;
        padding: 5px 15px;
        margin-bottom: 20px;
        font-size: 24px;
        color: #150442;
        font-weight: 400;
        background: #ffffff;
    }
    .unit-summary-table {
        width: 100%;
        color: #ffffff;
        font-size: 12px;
        line-height: 1.1;
        border-collapse: collapse;
        background: #150442;
    }
    .unit-summary-table td {
        padding: 5px 8px;
        border-bottom: 1px solid #c9c9c9;
        border-right: 1px solid #c9c9c9;
        background: #150442;
        vertical-align: middle;
        font-weight: 500;
        white-space: nowrap;
    }
    .unit-summary-table tr td:last-child {
        border-right: none;
    }
    .unit-summary-table tr:last-child td {
        border-bottom: none;
    }
    .unit-type-col {
        width: 24%;
    }
    .unit-price-col {
        width: 42%;
    }
    .unit-area-col {
        width: 34%;
    }
    .unit-value-prefix,
    .unit-value-main {
        white-space: nowrap;
    }
</style>
@endsection

@section('body')
{{-- ============ PAGE 1: COVER ============ --}}
<div class="logo-header">
    <table style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="text-align:left; vertical-align:middle;">
            @if(!empty($logoStackedPath))
                    <img src="{{ $logoStackedPath }}" style="height:100px; vertical-align:middle;" alt="Logo">
                @elseif($logoDarkPath)
                    <img src="{{ $logoDarkPath }}" style="height:45px; vertical-align:middle;" alt="Logo">
                @elseif($logoPath)
                    <img src="{{ $logoPath }}" style="height:45px; vertical-align:middle;" alt="Logo">
                @endif
            </td>
            <td style="width:45%; text-align:right; vertical-align:middle;">
                @if(!empty($developerLogoSrc) && !empty($developmentLogoSrc))
                    <table style="width:100%; border-collapse:collapse;">
                        <tr>
                            <td style="width:50%; text-align:right; vertical-align:middle; padding-right:18px;">
                                <img src="{{ $developerLogoSrc }}" style="width:100%; max-height:72px; vertical-align:middle;" alt="Developer Logo">
                            </td>
                            <td style="width:50%; text-align:right; vertical-align:middle;">
                                <img src="{{ $developmentLogoSrc }}" style="width:100%; max-height:72px; vertical-align:middle;" alt="Development Logo">
                            </td>
                        </tr>
                    </table>
                @elseif(!empty($developerLogoSrc))
                    <img src="{{ $developerLogoSrc }}" style="width:100%; max-height:72px; vertical-align:middle;" alt="Developer Logo">
                @elseif(!empty($developmentLogoSrc))
                    <img src="{{ $developmentLogoSrc }}" style="width:100%; max-height:72px; vertical-align:middle;" alt="Development Logo">
                @endif
            </td>
        </tr>
    </table>
</div>

@if($heroImageSrc)
    <img src="{{ $heroImageSrc }}" class="hero-image" alt="Property">
@else
    <div style="width:100%; height:480px; background:#e0e0e0;"></div>
@endif

<div class="completion-bar">
    @if(!empty($flyerTitle))
        <div style="font-size:38px; font-weight:400; color:#ffffff; letter-spacing:1px; text-transform:uppercase; margin-bottom:6px;">{{ $flyerTitle }}</div>
    @endif
    <div style="font-size:16px; color:#ffffff;">
        @php $locationLabel = trim((string) ($property->city?->name ?? $propertyCountry)); @endphp
        {{ ($locationLabel === '' || in_array(strtolower($locationLabel), ['united arab emirates', 'uae'], true)) ? 'Dubai' : $locationLabel }} &nbsp;|&nbsp;
        Expected Completion {{ $completionQuarter ?? (\App\Constants\Constants::COMPLETION_STATUS[$property->completionStatus] ?? ($property->completionStatus ?? 'TBD')) }}
    </div>
</div>

{{-- Property Details  + Description  --}}
<table style="width:100%; border-collapse:collapse; background-color: #E8E8E8;">
    <tr>
        <td class="unit-summary-cell">
            @if(!empty($paymentPlanYears))
                <div class="payment-plan-box">
                    <span class="payment-plan-type">{{ $paymentPlanYears }}</span>
                    <span class="payment-plan-label">Payment Plan</span>
                </div>
            @endif
            @if(!empty($projectROI))
                <div class="payment-plan-detail">{{ $projectROI }}</div>
            @endif
            <table class="unit-summary-table">
                @forelse($unitTypes as $ut)
                    @php
                        // Bedroom label: a plain number becomes "Studio"/"N Bedroom";
                        // anything the agent typed as text (e.g. "Studio", "2 Bedroom",
                        // "1 & 2 Bed") is shown exactly as entered.
                        $bedRaw = trim((string) ($ut['bedroom'] ?? ''));
                        if ($bedRaw === '') {
                            $label = 'Unit';
                        } elseif (is_numeric($bedRaw)) {
                            $label = ((int) $bedRaw) === 0 ? 'Studio' : ((int) $bedRaw).' Bedroom';
                        } else {
                            $label = $bedRaw;
                        }
                    @endphp
                    <tr>
                        <td class="unit-type-col">{{ $label }}</td>
                        <td class="unit-price-col">
                            @if(!empty($ut['price']) && is_numeric($ut['price']))
                                <span class="unit-value-prefix">from AED </span>
                                <span class="unit-value-main">{{ number_format((float) $ut['price']) }}</span>
                            @else
                                &mdash;
                            @endif
                        </td>
                        <td class="unit-area-col">
                            @if(!empty($ut['area']) && is_numeric($ut['area']))
                                <span class="unit-value-prefix">from {{ number_format((float) $ut['area']) }} </span>
                                <span class="unit-value-main">sqft</span>
                            @else
                                &mdash;
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">No unit configurations available</td>
                    </tr>
                @endforelse
            </table>
        </td>
        <td class="unit-description-cell">
            @if($shortDescription)
                <div style="font-size:14px; color:#150442; line-height:1.6; text-align:justify;">
                    {{ strip_tags($shortDescription) }}
                </div>
            @endif
        </td>
    </tr>
</table>

{{-- ============ PAGE 2: GALLERY + DETAILS + AGENT ============ --}}
<div class="page-break"></div>
<div class="page2-content">
    {{-- Gallery Grid: 2x2 images --}}
    <table class="gallery-grid">
        <tr>
            <td>
                @if(isset($galleryImagesSrc[0]))
                    <img src="{{ $galleryImagesSrc[0] }}" alt="Gallery 1">
                @else
                    <div style="width:100%; height:200px; background:#e8e8e8;"></div>
                @endif
            </td>
            <td>
                <div class="section-label" style="padding-top:20px;">Features</div>
                @if(count($amenities) > 0)
                    <div style="padding:0 15px;">
                        <ul style="font-size:13px; color:#fff; line-height:1.6; padding-left:15px;">
                            <!-- @foreach(array_slice($amenities, 0, 10) as $amenity)
                                <li>{{ $amenity }}</li>
                            @endforeach -->
                            @php
                                $limited = array_slice($amenities, 0, 18);
                                $colA = array_slice($limited, 0, 7);
                                $colB = array_slice($limited, 7, 7);
                            @endphp
                            <table style="width:100%; font-size:13px; color:#fff; border-collapse:collapse;">
                                <tr>
                                    <td style="width:50%; vertical-align:top; padding:0;">
                                        <ul style="margin:0; padding-left:18px; line-height:1.7;">
                                            @foreach($colA as $a)
                                                <li>{{ $a }}</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                    <td style="width:50%; vertical-align:top; padding:0;">
                                        <ul style="margin:0; padding-left:18px; line-height:1.7;">
                                            @foreach($colB as $a)
                                                <li>{{ $a }}</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                </tr>
                            </table>
                        </ul>
                    </div>
                @endif
            </td>
        </tr>
        <tr>
            <td colspan="2" style="height:2px;"></td>
        </tr>
        <tr>
            <td>
                @if(isset($galleryImagesSrc[1]))
                    <img src="{{ $galleryImagesSrc[1] }}" alt="Gallery 2">
                @else
                    <div style="width:100%; height:200px; background:#eeeeee;"></div>
                @endif
            </td>
            <td>
                @if(isset($galleryImagesSrc[2]))
                    <img src="{{ $galleryImagesSrc[2] }}" alt="Gallery 3">
                @else
                    <div style="width:100%; height:200px; background:#e8e8e8;"></div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Facilities + Building Image --}}
    <table style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="width:50%; vertical-align:top; background-color: #150442; padding:15px 18px;">
                <div class="section-label" style="margin-bottom:8px; padding:0;">Facilities</div>
                @if(count($amenityLabels) > 0)
                    @php
                        $limited = array_slice($amenityLabels, 0, 8);
                        $colA = array_slice($limited, 0, 3);
                        $colB = array_slice($limited, 3, 3);
                    @endphp
                    <table style="width:100%; font-size:13px; color:#fff; border-collapse:collapse;">
                        <tr>
                            <td style="width:50%; vertical-align:top; padding:0;">
                                <ul style="margin:0; padding-left:18px; line-height:1.7;">
                                    @foreach($colA as $a)
                                        <li>{{ $a }}</li>
                                    @endforeach
                                </ul>
                            </td>
                            <td style="width:50%; vertical-align:top; padding:0;">
                                <ul style="margin:0; padding-left:18px; line-height:1.7;">
                                    @foreach($colB as $a)
                                        <li>{{ $a }}</li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    </table>
                @endif

                <div class="section-label" style="margin-top:10px; margin-bottom:6px; padding:0;">Location</div>
                @if(!empty($locationProximityText))
                    @php
                        $limited = array_slice($locationProximityText, 0, 4);
                    @endphp
                    <div style="font-size:13px; color:#fff; line-height:1.6;">
                        @foreach($limited as $location)
                            <div>{{ $location }}</div>
                        @endforeach
                    </div>
                @else
                    <div style="font-size:13px; color:#fff; line-height:1.6;">
                        {{ joinNonEmpty([$property->community?->name, $property->subCommunity?->name, $property->city?->name]) }}
                    </div>
                @endif
            </td>
            <td style="width:50%; vertical-align:top; padding:0;">
                @if(!empty($buildingImageSrc))
                    <img src="{{ $buildingImageSrc }}" style="width:100%; height:280px;" alt="">
                @else
                    <div style="width:100%; height:280px; background:#150442;"></div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Agent Section --}}
    <div class="agent-footer">
       <table style="width:100%; border-bottom:1px solid #000;">
            <tr>
                <td style="width:95px; vertical-align:top; padding-top:14px; padding-left:10px;">

                    @if($agentProfile)
                        <img src="{{ $agentProfile }}"
                            style="width:85px; height:85px; border-radius:50%; display:block; margin-bottom:8px;">
                    @endif

                    @if($govtQRSrc)
                        <img src="{{ $govtQRSrc }}"
                            style="width:85px; height:85px; display:block; margin-top:40px;">
                    @endif

                </td>


                <td style="vertical-align:top; padding-left:12px;  margin-bottom:10px; margin-top:10px;">
                    <div style="margin-top:8px;">
                        <div class="agent-name">{{ $agent->name ?? 'Agent' }}</div>
                        @if($agent->phone ?? null)
                            <div class="agent-phone">{{ $agent->phone }}</div>
                        @endif
                        <div class="agent-detail">{{ $agent->email ?? '' }}</div>
                        @if($agent->agentCode ?? null)
                            <div class="agent-detail">BRN {{ $agentBrn }}</div>
                        @endif
                    </div>
                    <div style="margin-top:12px;">
                        <div class="company-info">{{ $company['phone'] ?? '+971 4 399 4937' }}</div>
                        <div class="company-info">{{ $company['website'] ?? 'www.exclusive-links.com' }}</div>
                        <div style="font-size:14px; color:#150442;">{{ $company['services'] ?? 'sales | leasing | management | off plan' }}</div>
                        @if($property->reraPermitNo)
                            <div class="permit-text" style="margin-top:4px;">PERMIT {{ $property->reraPermitNo }}</div>
                        @endif
                    </div>
                </td>
                <td style="width:50%; vertical-align:top; text-align:right;">
                    @if(!empty($mapImageSrc))
                        <div style="border-radius:4px; overflow:hidden; border:1px solid #e0e0e0;">
                            <img src="{{ $mapImageSrc }}" alt="Location Map" style="width:100%; height:220px; object-fit:cover; display:block;">
                            <div style="background:#150442; color:#fff; font-size:11px; font-weight:bold; padding:6px 10px; text-align:left;">
                                {{ $mapLabel ?? ($property->community?->name ?? '') }}
                            </div>
                        </div>
                    @else
                        <div style="background:#f0f0f0; padding:15px; border-radius:4px; text-align:center;">
                            <div style="font-size:10px; color:#666; margin-bottom:5px;">Location</div>
                            <div style="font-size:11px; color:#333; font-weight:bold;">
                                {{ $mapLabel ?? ($property->community?->name ?? '') }}
                            </div>
                        </div>
                    @endif
                </td>
            </tr>
        </table>
        <table style="width:100%; margin-top:12px; padding: 4px 10px 21px 10px">
            <tr>

                {{-- VISIT WEBSITE --}}
                <td style="width:24%; vertical-align:middle;">
                    <a href="https://www.exclusive-links.com/about-exclusive-links/meet-the-team/{{ $agent->userName }}" class="btn-link social-btn">VIEW MY PROFILE</a>
                </td>

                {{-- SOCIAL ICONS --}}
                <td style="width:52%; text-align:center; vertical-align:middle; white-space:nowrap;">
                    <a href="{{ $company['social']['facebook'] ?? '#' }}" class="icon-link">
                        <img src="{{ public_path('icons/facebook.svg') }}" class="icon-img" style="border:0;">
                    </a>
                    <a href="{{ $company['social']['instagram'] ?? '#' }}" class="icon-link">
                        <img src="{{ public_path('icons/insta.svg') }}" class="icon-img" style="border:0;">
                    </a>
                    <a href="{{ $company['social']['twitter'] ?? '#' }}" class="icon-link">
                        <img src="{{ public_path('icons/twitter.svg') }}" class="icon-img" style="border:0;">
                    </a>
                    <a href="{{ $company['social']['youtube'] ?? '#' }}" class="icon-link">
                        <img src="{{ public_path('icons/youtube.svg') }}" class="icon-img" style="border:0;">
                    </a>
                    <a href="{{ $company['social']['linkedin'] ?? '#' }}" class="icon-link">
                        <img src="{{ public_path('icons/linkedin.svg') }}" class="icon-img" style="border:0;">
                    </a>
                    <a href="{{ $company['social']['podcast'] ?? 'https://propertytalkfromlouiseslounge.buzzsprout.com/' }}" class="icon-link">
                        <img src="{{ public_path('icons/podcast.svg') }}" class="icon-img" style="border:0;">
                    </a>
                </td>

                {{-- WHATSAPP --}}
                <td style="width:24%; text-align:right; vertical-align:middle;">
                    @php
                        $waPhone = preg_replace('/\D+/', '', (string) ($agent->phone ?? ''));
                        $waUrl = $waPhone !== ''
                            ? 'https://api.whatsapp.com/send?phone='.$waPhone
                            : 'https://api.whatsapp.com/';
                    @endphp
                    <a href="{{ $waUrl }}" class="btn-link social-btn">WHATSAPP ME</a>
                </td>

            </tr>
        </table>
    </div>
</div>
@endsection
