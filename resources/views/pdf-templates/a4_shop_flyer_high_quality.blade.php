@extends('pdf-templates.pdf_layout')

@section('styles')
<style>
    body {
        margin: 0;
        padding: 0;
        font-family: Arial, Helvetica, sans-serif;
        color: #000;
    }
    .top-bar {
        width: 100%;
        border-collapse: collapse;
        background-color: #000033;
        margin-bottom: 8px;
    }
    .top-bar td {
        vertical-align: middle;
        padding: 18px 25px;
    }
    .for-sale-badge {
        background-color: #000033;
        color: #ffffff;
        font-size: 52px;
        font-weight: 800;
        padding: 12px 30px;
        text-align: right;
        letter-spacing: 2px;
    }
    .for-logo-badge img {
        height: 80px;
        width: auto;
        display: block;
    }
    .image-grid {
        width: 100%;
        border-collapse: collapse;
    }
    .image-grid td {
        vertical-align: top;
        padding: 0;
    }
    .hero-cell img {
        width: 100%;
        height: 464px;
        object-fit: cover;
        display: block;
    }
    .sidebar-cell table {
        width: 100%;
        border-collapse: collapse;
    }
    .sidebar-cell img {
        width: 100%;
        height: 150px;
        object-fit: cover;
        display: block;
    }
    .gap {
        height: 7px;
    }

    .content-area {
        padding: 20px 10px 0px 20px;
    }
    .property-title {
        font-size: 38px;
        font-weight: bold;
        color: #000000;
        line-height: 1.15;
        margin-bottom: 18px;
    }
    .property-specs {
        font-size: 18px;
        font-weight: bold;
        color: #000000;
        margin-bottom: 22px;
    }
    .property-price {
        font-size: 34px;
        font-weight: bold;
        color: #000000;
        line-height: 1;
        margin-bottom: 18px;
    }
    .features-list {
        list-style: disc;
        padding-left: 16px;
        font-size: 17px;
        color: #000;
        font-weight: 500;
        line-height: 1.6;
        text-transform: capitalize;
    }

    /* Agent column (right) */
    .agent-photo-circle {
        width: 130px;
        height: 130px;
        border-radius: 50%;
        object-fit: cover;
        display: inline-block;
    }
    .agent-name {
        font-size: 20px;
        font-weight: 500;
        color: #000000;
        margin-top: 14px;
        margin-bottom: 4px;
    }
    .agent-phone {
        font-size: 36px;
        font-weight: bold;
        color: #000000;
        margin-bottom: 6px;
    }
    .agent-email {
        font-size: 15px;
        color: #000;
        margin-bottom: 14px;
    }
    .agent-detail {
        font-size: 18px;
        color: #000;
        font-weight: bold;
        line-height: 1.4;
    }

    .footer-bar {
        background-color: #000033;
        color: #ffffff;
        padding: 6px 30px 3px 20px;
        font-size: 9px;
        position: fixed;
        bottom: 0px;
        left: 0;
        right: 0;
    }
</style>
@endsection

@section('body')
{{-- Top Bar: FOR SALE only (no logo) --}}
<table class="top-bar">
    <tr>
        <td style="width: 50%;">&nbsp;</td>
        <td class="for-sale-badge">
            {{ $listingBadge }}
        </td>
    </tr>
</table>

{{-- Image Grid: Hero + 3 Sidebar Images --}}
<table class="image-grid">
    <tr>
        <td class="hero-cell" style="width: 68%; padding-right:7px;">
            @if($heroImageSrc)
                <img src="{{ $heroImageSrc }}" alt="Property">
            @else
                <div style="width:100%; height:464px; background:#e0e0e0;"></div>
            @endif
        </td>

        <td class="sidebar-cell" style="width: 30%;">
            <table>
                <tr>
                    <td>
                        @if(isset($galleryImagesSrc[0]))
                            <img src="{{ $galleryImagesSrc[0] }}">
                        @else
                            <div style="height:150px; background:#eee;"></div>
                        @endif
                    </td>
                </tr>
                <tr><td class="gap"></td></tr>
                <tr>
                    <td>
                        @if(isset($galleryImagesSrc[1]))
                            <img src="{{ $galleryImagesSrc[1] }}">
                        @else
                            <div style="height:150px; background:#eee;"></div>
                        @endif
                    </td>
                </tr>
                <tr><td class="gap"></td></tr>
                <tr>
                    <td>
                        @if(isset($galleryImagesSrc[2]))
                            <img src="{{ $galleryImagesSrc[2] }}">
                        @else
                            <div style="height:150px; background:#eee;"></div>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

{{-- Content Area --}}
<div class="content-area">
    {{-- Title row: the agent photo takes a fixed width, the title gets the rest of the row --}}
    <table style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="width:100%; vertical-align:top; padding-right:15px;">
                <div class="property-title">
                    {{ $flyerLocation }}
                </div>
            </td>
            <td style="width:130px; vertical-align:top; text-align:right;">
                @if($agentProfile)
                    <img src="{{ $agentProfile }}" class="agent-photo-circle" alt="Agent">
                @endif
            </td>
        </tr>
    </table>

    <table style="width:100%;">
        <tr>
            {{-- Left: Property Info --}}
            <td style="width:60%; vertical-align:top; padding-right:15px;">
                @php
                    $specParts = [];
                    if (! $isCommercial) {
                        $specParts[] = $bedroomsSpec;
                        $specParts[] = ($property->bathrooms ? (int) $property->bathrooms : '-').' bath';
                    }
                    $specParts[] = ($property->sizeArea ? (int) $property->sizeArea : '-').' sqft';
                @endphp
                <div class="property-specs">
                    {{ implode(' | ', $specParts) }}
                </div>

                <div class="property-price">
                    AED {{ number_format($property->price ?? 0) }}
                </div>

                @php($flyerBullets = ! empty($flyerHighlights) ? $flyerHighlights : $amenities)
                @if(count($flyerBullets) > 0)
                    <ul class="features-list">
                        @foreach(array_slice($flyerBullets, 0, 6) as $bullet)
                            <li>{{ $bullet }}</li>
                        @endforeach
                    </ul>
                @endif
            </td>

            {{-- Right: Agent Info (photo lives in the title row above) --}}
            <td style="width:40%; vertical-align:top; text-align:right;">
                <div class="agent-name">{{ $agent->name ?? 'Agent' }}</div>

                @if($agentPhone)
                    <div class="agent-phone">{{ $agentPhone }}</div>
                @endif

                <div class="agent-email">{{ $agent->email ?? '' }}</div>

                <div class="agent-detail">
                    Reference # {{ $property->referenceNumber ?? 'N/A' }}
                </div>

                @if(!empty($agent->agentCode))
                    <div class="agent-detail" style="margin-top:3px;">
                        BRN {{ $agentBrn }}@if($property->reraPermitNo) | PERMIT @endif
                    </div>
                    @if($property->reraPermitNo)
                        <div class="agent-detail">{{ $property->reraPermitNo }}</div>
                    @endif
                @elseif($property->reraPermitNo)
                    <div class="agent-detail" style="margin-top:3px;">
                        PERMIT {{ $property->reraPermitNo }}
                    </div>
                @endif
            </td>
        </tr>
    </table>
</div>

{{-- Footer Bar --}}
<div class="footer-bar">
    <table style="width:100%;">
        <tr>
            <td style="width:60%;">
                <div style="font-size:14px; color:#fff; margin-bottom:5px">{{ $company['services'] ?? 'sales | leasing | management | vacation homes' }}</div>
                <div style="font-size:16px; font-weight:400; color:#ffffff;">{{ $company['tagline'] ?? 'The Complete Property Experience' }}</div>
            </td>
            <td style="text-align:right;">
                <div style="font-size:20px; font-weight:600; color:#ffffff; margin-bottom:8px">{{ $company['phone'] ?? '' }}</div>
                <div style="font-size:14px; color:#ffffff;">{{ $company['website'] ?? '' }}</div>
            </td>
        </tr>
    </table>
</div>
@endsection
