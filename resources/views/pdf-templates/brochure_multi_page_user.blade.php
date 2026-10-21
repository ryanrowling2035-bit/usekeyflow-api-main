@extends('pdf-templates.pdf_layout')

@section('styles')
<style>
    @page {
        margin: 0;
        size: A4 portrait;
    }
    body {
        margin: 0;
        padding: 0;
        font-family: Helvetica, Arial, sans-serif;
        color: #000;
    }
    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    /* ======= Cover page ======= */
    /* Cover wrapper: fixed to one A4 page so the footer can sit flush at the
       bottom. Navy background fills the space below the title block, removing
       the white gap between the title and the footer bar. */
    .cover-page {
        position: relative;
        height: 1122px;
        overflow: hidden;
        background: #000032;
    }
    .cover-top {
        width: 100%;
        border-collapse: collapse;
        background: #ffffff;
    }
    .cover-top td {
        vertical-align: middle;
        padding: 20px 10px 15px;
    }
    .hero-image {
        width: 100%;
        height: 460px;
        display: block;
    }
    .cover-bottom-bar {
        background-color: #000032;
        padding: 30px 5px 20px 20px;
        color: #ffffff;
    }
    .cover-title {
        font-size: 36px;
        font-weight: 400;
        color: #ffffff;
        line-height: 1.2;
        /* Cap the title at ~4 lines so a long community/sub-community/tower name
           can't grow the cover and push the footer bar onto the next page. */
        max-height: 176px;
        overflow: hidden;
    }
    .cover-specs {
        margin-top: 28px;
    }
    .cover-specs td {
        vertical-align: middle;
        padding-right: 56px;
        color: #ffffff;
    }
    .spec-icon {
        font-size: 28px;
        color: #ffffff;
        padding-left: 12px;
    }
    .spec-icon-aed {
        font-size: 28px;
        color: #ffffff;
        padding-left: 12px;
        padding-right: 12px;
    }
    .cover-footer-bar {
        background-color: #000032;
        color: #ffffff;
        padding: 20px 30px 40px 20px;
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
    }
    .cover-footer-bar td { color: #ffffff; vertical-align: middle; }

    /* ======= Page 2 - Details ======= */
    .details-banner {
        background-color: #000032;
        padding: 22px 30px;
        color: #ffffff;
    }
    .details-banner .price-banner {
        font-size: 30px;
        color: #ffffff;
    }
    .details-banner .kf-title {
        font-size: 32px;
        color: #ffffff;
        margin-bottom: 10px;
    }
    .details-banner ul.kf-list {
        list-style: disc;
        padding-left: 18px;
        font-size: 16px;
        color: #ffffff;
        line-height: 1.6;
        margin: 0;
        text-transform: capitalize;
    }
    .details-banner .spec-row {
        color: #ffffff;
        margin-top: 14px;
    }
    .details-content {
        padding: 20px 30px 30px 30px;
        background: #ffffff;
    }
    .section-heading {
        font-size: 30px;
        color: #000000;
        margin-bottom: 8px;
        margin-top: 6px;
    }
    .description-text {
        font-size: 14.9px;
        color: #000;
        margin-bottom: 15px;
        line-height: 1.4;
        background: #ffffff;
        max-height: 700px;
        overflow: hidden;
    }

    .page-second,
    .page-bottom,
    .page-map {
        position: relative;
        height: 1122px;
        overflow: hidden;
    }

    .page-bottom-part {
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        background: #d1d1d3;
        padding: 20px 30px 30px 30px;
    }
    .page-map-bottom {
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
    }

    .development-title {
        font-size: 35px;
        font-weight: 400;
        color: #000000;
    }
    .project-title {
        font-size: 35px;
        color: #000000;
        font-weight: 400;
        margin-bottom: 16px;
    }
    .details-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 16px;
    }
    .details-table td {
        padding: 4px 8px;
        vertical-align: top;
    }
    .details-table .label {
        text-align: right;
        color: #000;
        width: 40%;
        padding-right: 12px;
    }
    .details-table .value {
        color: #000000;
        width: 55%;
    }

    /* ======= Gallery pages ======= */
    .gallery-page-img {
        width: 100%;
        height: 490px;
        display: block;
        margin: 10px 0 0;
    }
    .height-498{
        height: 498px;
    }
    .map-full-img {
        width: 100%;
        display: block;
        margin: 0;
    }
    .gallery-footer {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }
    .gallery-footer tr {
        height: 80px;
    }
    .gallery-label-cell {
        background-color: #000032;
        color: #ffffff;
        padding: 12px 25px;
        font-size: 24px;
        line-height: 1.2;
        width: 65%;
        height: 80px;
        vertical-align: middle;
        overflow: hidden;
    }
    /* Keep the footer label a fixed height; clip any overflowing text
       so a long community/project name can't grow the bar onto the next page. */
    .gallery-label-text {
        display: block;
        max-height: 58px; /* ~2 lines at 24px / 1.2 line-height */
        overflow: hidden;
    }
    .gallery-logo-cell {
        background-color: #ffffff;
        padding: 8px 20px;
        text-align: right;
        width: 35%;
        height: 80px;
        vertical-align: middle;
    }
    .gallery-logo-cell img {
        height: 50px;
        width: auto;
        max-height: 50px;
        display: inline-block;
        vertical-align: middle;
    }

   
    /* ======= Last page - Agent ======= */
    .agent-section {
        padding: 60px 30px 20px 30px;
    }
    .agent-photo-large {
        width: 250px;
        height: 250px;
        border-radius: 50%;
        object-fit: cover;
        display: block;
    }
    .agent-name-large {
        font-size: 24px;
        color: #474747;
    }
    .agent-phone-large {
        font-size: 29px;
        color: #474747;
    }
    .agent-email {
        font-size: 20px;
        color: #474747;
        margin-top: 4px;
    }
    .agent-ref {
        font-size: 20px;
        color: #474747;
        margin-top: 4px;
    }
    .agent-bio {
        font-size: 16px;
        color: #474747;
        line-height: 1.6;
        margin-top: 20px;
    }
    .company-footer {
        background-color: #000032;
        color: #ffffff;
        text-align: center;
        margin-top: 80px;
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        padding: 30px;
    }
    .company-footer .company-line-info {
        font-size: 12px;
        color: #ffffff;
        margin-bottom: 20px;
        padding-left: 20px;
    }

    .company-footer .company-line {
        font-size: 17px;
        color: #ffffff;
        margin-top: 6px;
    }
    
    .company-footer .company-url {
        font-size: 13px;
        color: #ffffff;
        margin-top: 5px;
    }

    /* Disable the inherited fixed footer-bar on all pages for this template */
    .footer-bar { position: static; padding: 0; background: transparent; display: none; }
</style>
@endsection

@section('body')
@php
    $showBedrooms = ! $isCommercial;
    $showBathrooms = ! $isCommercial;
@endphp
{{-- ============ PAGE 1: COVER ============ --}}
<div class="cover-page">
<table class="cover-top">
    <tr>
        <td style="width:50%;">
            @if($logoStackedPath)
                <img src="{{ $logoStackedPath }}" style="height:130px;" alt="Logo">
            @elseif($logoDarkPath)
                <img src="{{ $logoDarkPath }}" style="height:130px;" alt="Logo">
            @elseif($logoPath)
                <img src="{{ $logoPath }}" style="height:130px;" alt="Logo">
            @endif
        </td>
        <td style="text-align:right; vertical-align:bottom; padding-bottom:10px;">
            <div style="font-size:40px; color:#4C4C4C; font-weight:400; margin-top: 18px;">{{ $listingBadge }}</div>
            <div style="font-size:38px; color:#4C4C4C;">AED {{ number_format($property->price ?? 0) }}</div>
        </td>
    </tr>
</table>

@if($heroImageSrc)
    <img src="{{ $heroImageSrc }}" class="hero-image" alt="Property">
@else
    <div style="width:100%; height:530px; background:#e0e0e0;"></div>
@endif

<div class="cover-bottom-bar">
    <table style="width:100%;">
        <tr>
            <td style="width:60%; vertical-align:top;">
                <div class="cover-title">
                    {{ $property->community?->name ?? '' }}
                    @if($property->subCommunity)<br>{{ $property->subCommunity->name }}@endif
                    @if($property->buildingTower)<br>{{ $property->buildingTower->name }}@endif
                </div>
                <div class="cover-specs">
                    <table><tr>
                        @if($showBedrooms)
                            <td>
                                @if($bedIconSrc)
                                    <img src="{{ $bedIconSrc }}" style="width:40px; height:28px; vertical-align:middle; margin-right:14px;">
                                @endif
                                <span class="spec-icon">X {{ $property->bedrooms ?? '-' }}</span>
                            </td>
                        @endif
                        @if($showBathrooms)
                            <td>
                                @if($bathIconSrc)
                                    <img src="{{ $bathIconSrc }}" style="width:34px; height:28px; vertical-align:middle; margin-right:14px;">
                                @endif
                                <span class="spec-icon">X {{ $property->bathrooms ? (int) $property->bathrooms : '-' }}</span>
                            </td>
                        @endif
                    </tr></table>
                </div>
            </td>
            <td style="width:40%; text-align:right; vertical-align:top;">
                @if($govtQRSrc)
                    <div style="margin-bottom:6px; margin-top:30px;">
                        <img src="{{ $govtQRSrc }}" style="width:190px; height:190px; object-fit:contain;" alt="QR Code">
                    </div>
                @endif
                @if($property->reraPermitNo)
                    <div style="font-size:12px; color:#cccccc;">PERMIT {{ $property->reraPermitNo }}</div>
                @endif
            </td>
        </tr>
    </table>
</div>

<div class="cover-footer-bar">
    <table style="width:100%;"><tr>
        <td style="width:60%;">
            <div style="font-size:21px; color:#fff;">{{ $company['services'] ?? 'sales | leasing | management | vacation homes' }}</div>
            <div style="font-size:23px; color:#ffffff; margin-top:15px;">{{ $company['tagline'] ?? 'The Complete Property Experience' }}</div>
        </td>
        <td style="text-align:right;">
            <div style="font-size:28px; color:#ffffff;">{{ $company['phone'] ?? '' }}</div>
            <div style="font-size:21px; color:#ffffff; margin-top:15px;">{{ $company['website'] ?? '' }}</div>
        </td>
    </tr></table>
</div>
</div>

{{-- ============ PAGE 2: DETAILS ============ --}}
<div class="page-break"></div>
<div class="page-second">

    <div class="details-banner">
        <table style="width:100%;">
            <tr>
                <td style="width:35%; vertical-align:top;">
                    <div class="price-banner">AED {{ number_format($property->price ?? 0) }}</div>
                    <div class="spec-row" style="margin-top:40px;">
                        @if($showBedrooms)
                            @if($bedIconSrc)
                                <img src="{{ $bedIconSrc }}" style="width:40px; height:28px; vertical-align:middle;">
                            @endif
                            <span class="spec-icon-aed">X {{ $property->bedrooms ?? '-' }}</span>
                        @endif
                        @if($showBedrooms && $showBathrooms)&nbsp;&nbsp;@endif
                        @if($showBathrooms)
                            @if($bathIconSrc)
                                <img src="{{ $bathIconSrc }}" style="width:34px; height:28px; vertical-align:middle;">
                            @endif
                            <span class="spec-icon-aed">X {{ $property->bathrooms ? (int) $property->bathrooms : '-' }}</span>
                        @endif
                    </div>
                </td>
                <td style="width:65%; vertical-align:top;">
                    <div class="kf-title">Key Features</div>
                    @php $flyerBullets = ! empty($flyerHighlights) ? $flyerHighlights : $amenities; @endphp
                    @if(count($flyerBullets) > 0)
                        <ul class="kf-list">
                            @foreach(array_slice($flyerBullets, 0, 8) as $amenity)
                                <li>{{ $amenity }}</li>
                            @endforeach
                        </ul>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="details-content">
        @php
            $brochureDescription = !empty($shortDescription)
                ? strip_tags($shortDescription)
                : \Illuminate\Support\Str::limit(strip_tags($property->description ?? ''), 300, '...');
        @endphp
        @if(!empty(trim($brochureDescription)))
            <div class="section-heading">Description</div>
            <div class="description-text">{{ $brochureDescription }}</div>
        @endif

        <div class="page-bottom-part">

            <div class="development-title">{{ $property->community?->name ?? '' }}</div>
            <div class="project-title">{{ joinNonEmpty([$property->subCommunity?->name, $property->buildingTower?->name], ' ') }}</div>

            <table class="details-table">
                <tr><td class="label">Total Area:</td><td class="value">{{ $property->sizeArea ? (int) $property->sizeArea . 'Sq.Ft' : 'N/A' }}</td></tr>
                <tr><td class="label">Plot Size:</td><td class="value">{{ $property->plotSize ? (int) $property->plotSize . 'Sq.Ft' : 'Sq.Ft' }}</td></tr>
                <tr><td class="label">Ref No:</td><td class="value">{{ $property->referenceNumber ?? 'N/A' }}</td></tr>
                @if($property->reraPermitNo)
                <tr><td class="label">RERA Permit No:</td><td class="value">{{ $property->reraPermitNo }}</td></tr>
                @endif
                <tr><td class="label">Development:</td><td class="value">{{ $property->community?->name ?? 'N/A' }}</td></tr>
                <tr><td class="label">Project:</td><td class="value">{{ joinNonEmpty([$property->subCommunity?->name, $property->buildingTower?->name], ' ') }}</td></tr>
                <tr><td class="label">City:</td><td class="value">{{ $property->city?->name ?? 'N/A' }}</td></tr>
            </table>
        </div>
    </div>

</div>

{{-- ============ PAGES 3+: GALLERY ============ --}}
@php
    $gallerySrc = array_slice($allImagesSrc, 1);
    // Cap the gallery so the brochure stays a sensible length (2 images per page).
    // 6 images = 3 gallery pages → ~7-page brochure. Adjust this number to taste.
    $galleryImageLimit = 6;
    $gallerySrc = array_slice($gallerySrc, 0, $galleryImageLimit);
    $chunkedGallery = array_chunk($gallerySrc, 2);
    $galleryLabel = joinNonEmpty([
        $property->community?->name,
        joinNonEmpty([$property->subCommunity?->name, $property->buildingTower?->name], ' '),
    ], ' - ');
@endphp

@foreach($chunkedGallery as $chunk)
<div class="page-break"></div>
@foreach($chunk as $imgSrc)
    <img src="{{ $imgSrc }}" class="gallery-page-img height-498" alt="Gallery">
@endforeach
<table class="gallery-footer">
    <tr>
        <td class="gallery-label-cell">
            @php
                $proj = joinNonEmpty([$property->subCommunity?->name, $property->buildingTower?->name], ' ');
                // dompdf treats cell height as a minimum and ignores overflow:hidden,
                // so truncate each line server-side to keep the footer a fixed height.
                $footerCommunity = \Illuminate\Support\Str::limit($property->community?->name ?? '', 34, '…');
                $footerProj = \Illuminate\Support\Str::limit($proj, 32, '…');
            @endphp
            <span class="gallery-label-text">{{ $footerCommunity }}@if($footerProj)<br>- {{ $footerProj }}@endif</span>
        </td>
        <td class="gallery-logo-cell">
            @if($logoDarkPath)
                <img src="{{ $logoDarkPath }}" height="50" style="height:50px;" alt="Logo">
            @elseif($logoPath)
                <img src="{{ $logoPath }}" height="50" style="height:50px;" alt="Logo">
            @endif
        </td>
    </tr>
</table>
@endforeach

{{-- ============ MAP PAGE ============ --}}
@if(!empty($mapImageSrc))
<div class="page-break"></div>
<table style="width:100%; border-collapse:collapse; margin:0; padding:0;">
    <tr>
        <td colspan="2" style="padding:0; margin:0; line-height:0; font-size:0;">
            <img src="{{ $mapImageSrc }}" class="map-full-img" alt="Location Map">
        </td>
    </tr>
    <tr>
        <td class="gallery-label-cell">
            @php
                $proj = joinNonEmpty([$property->subCommunity?->name, $property->buildingTower?->name], ' ');
                $footerCommunity = \Illuminate\Support\Str::limit($property->community?->name ?? '', 34, '…');
                $footerProj = \Illuminate\Support\Str::limit($proj, 32, '…');
            @endphp
            <span class="gallery-label-text">{{ $footerCommunity }}@if($footerProj)<br>- {{ $footerProj }}@endif</span>
        </td>
        <td class="gallery-logo-cell">
            @if($logoDarkPath)
                <img src="{{ $logoDarkPath }}" height="50" style="height:50px;" alt="Logo">
            @elseif($logoPath)
                <img src="{{ $logoPath }}" height="50" style="height:50px;" alt="Logo">
            @endif
        </td>
    </tr>
</table>
@endif

{{-- ============ FLOOR PLANS ============ --}}
@if(count($floorPlansSrc) > 0)
<div class="page-break"></div>
@foreach($floorPlansSrc as $fpSrc)
    <img src="{{ $fpSrc }}" class="gallery-page-img" alt="Floor Plan">
@endforeach
<table class="gallery-footer">
    <tr>
        <td class="gallery-label-cell">Floor Plans</td>
        <td class="gallery-logo-cell">
            @if($logoDarkPath)
                <img src="{{ $logoDarkPath }}" height="50" style="height:50px;" alt="Logo">
            @elseif($logoPath)
                <img src="{{ $logoPath }}" height="50" style="height:50px;" alt="Logo">
            @endif
        </td>
    </tr>
</table>
@endif

{{-- ============ LAST PAGE: AGENT ============ --}}
<div class="page-break"></div>

    <div class="page-bottom">
        <div class="agent-section">
            <table style="width:100%;">
                <tr>
                    <td style="width:35%; vertical-align:top;">
                        @if($agentProfile)
                            <img src="{{ $agentProfile }}" class="agent-photo-large" alt="Agent">
                        @endif
                    </td>
                    <td style="width:65%; vertical-align:top; padding-left:15px;">
                        <div class="agent-name-large">{{ $agent->name ?? 'Agent' }}</div>

                        @if($agentPhone)
                            <div class="agent-phone-large">{{ $agentPhone }}</div>
                        @endif

                        <div class="agent-email">{{ $agent->email ?? '' }}</div>
                        <div class="agent-ref">Reference # {{ $property->referenceNumber ?? 'N/A' }}</div>

                        @if($agent->agentCode ?? null)
                            <div class="agent-ref">BRN {{ $agentBrn }} | PERMIT {{ $property->reraPermitNo ?? '' }}</div>
                        @elseif($property->reraPermitNo)
                            <div class="agent-ref">PERMIT {{ $property->reraPermitNo }}</div>
                        @endif
                    </td>
                </tr>
            </table>

            @if($agentDescription)
                <div class="agent-bio">
                    {{ strip_tags($agentDescription) }}
                </div>
            @endif
        </div>

        <div class="company-footer">
            @if($logoWhitePath)
                <img src="{{ $logoWhitePath }}" style="height:75px; margin-bottom:5px;" alt="Logo">
            @elseif($logoPath)
                <img src="{{ $logoPath }}" style="height:75px; margin-bottom:5px;" alt="Logo">
            @endif
            <div class="company-line-info">Sales | Leasing | Management</div>
            <div class="company-line">{{ $company['website'] ?? 'www.exclusive-links.com' }}</div>
            <div class="company-line">email: info@exclusive-links.com</div>
            <div class="company-line">Land Department Registration Nos. 708/16892</div>
            <div class="company-line">TRN # 100003976600003</div>
            <div class="company-line" style="margin-top:10px;">
                Follow us on: &nbsp;
                @if($instaIconSrc)<a href="https://www.instagram.com/exclusivelinks/" target="_blank" style="text-decoration:none;"><img src="{{ $instaIconSrc }}" style="width:18px; height:18px; vertical-align:middle;" alt="Instagram"></a>@endif
                @if($facebookIconSrc)<a href="https://www.facebook.com/ELREDubai" target="_blank" style="text-decoration:none;"><img src="{{ $facebookIconSrc }}" style="width:18px; height:18px; vertical-align:middle; margin-left:4px;" alt="Facebook"></a>@endif
                @if($youtubeIconSrc)<a href="https://www.youtube.com/@ELREDubai" target="_blank" style="text-decoration:none;"><img src="{{ $youtubeIconSrc }}" style="width:18px; height:18px; vertical-align:middle; margin-left:4px;" alt="YouTube"></a>@endif
                &nbsp; ELREDubai
            </div>
            <div class="company-url">
                @if($instaIconSrc)<a href="https://www.instagram.com/exclusivelinks/" target="_blank" style="text-decoration:none;"><img src="{{ $instaIconSrc }}" style="width:14px; height:14px; vertical-align:middle; margin-right:6px;" alt="Instagram"></a>@endif
                <a href="https://www.instagram.com/exclusivelinks/" target="_blank" style="color:#ffffff; text-decoration:none;">https://www.instagram.com/exclusivelinks/</a>
            </div>
            <div class="company-url">
                @if($linkedinIconSrc)<a href="https://www.linkedin.com/company/exclusive-links-real-estate-brokers/" target="_blank" style="text-decoration:none;"><img src="{{ $linkedinIconSrc }}" style="width:14px; height:14px; vertical-align:middle; margin-right:6px;" alt="LinkedIn"></a>@endif
                <a href="https://www.linkedin.com/company/exclusive-links-real-estate-brokers/" target="_blank" style="color:#ffffff; text-decoration:none;">https://www.linkedin.com/company/exclusive-links-real-estate-brokers/</a>
            </div>
        </div>
    </div>
@endsection
