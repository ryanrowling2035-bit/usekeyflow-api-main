@extends('pdf-templates.pdf_layout')

@section('styles')
<style>
    body {
        margin: 0;
        padding: 0;
        font-family: Helvetica, Arial, sans-serif;
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
        padding: 15px 20px;
    }

    .for-sale-badge {
        background-color: #000033;
        color: #ffffff;
        font-size: 50px;
        font-weight: 800;
        padding: 12px 30px;
        text-align: right;
        letter-spacing: 2px;
    }

    .for-logo-badge img {
        height: auto;
        width: auto;
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
        line-height: 1;
        margin-bottom: 18px;
    }

    .property-specs {
        font-size: 21px;
        font-weight: bold;
        color: #000000;
        margin-bottom: 12px;
    }

    .description-text {
        font-size: 13px;
        color: #000;
        font-weight: 400;
        line-height: 1.6;
        margin-bottom: 30px;
    }

    .details-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
        font-weight: 400;
    }

    .details-table td {
        padding: 4px 0;
        vertical-align: top;
    }

    .details-table .label {
        color: #000;
        width: 15%;
    }

    .details-table .value {
        font-size: 15px !important;
        font-weight: bold;
        color: #000000;
    }
</style>
@endsection

@section('body')
{{-- Top Bar: FOR SALE --}}
<table class="top-bar">
    <tr>
        <td class="for-logo-badge" style="width: 50%;">
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
            <div style="width:100%; height:340px; background:#e0e0e0;"></div>
            @endif
        </td>
        <td class="sidebar-cell" style="width: 30%;">
            <table>
                <tr>
                    <td>
                        @if(isset($galleryImagesSrc[0]))
                        <img src="{{ $galleryImagesSrc[0] }}">
                        @else
                        <div style="height:116px; background:#eee;"></div>
                        @endif
                    </td>
                </tr>

                <tr>
                    <td class="gap"></td>
                </tr>

                <tr>
                    <td>
                        @if(isset($galleryImagesSrc[1]))
                        <img src="{{ $galleryImagesSrc[1] }}">
                        @else
                        <div style="height:116px; background:#eee;"></div>
                        @endif
                    </td>
                </tr>

                <tr>
                    <td class="gap"></td>
                </tr>

                <tr>
                    <td>
                        @if(isset($galleryImagesSrc[2]))
                        <img src="{{ $galleryImagesSrc[2] }}">
                        @else
                        <div style="height:116px; background:#eee;"></div>
                        @endif
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

{{-- Content Area --}}
<div class="content-area">
    <div class="property-title">
        {{ joinNonEmpty([$property->community?->name, $property->subCommunity?->name, $property->buildingTower?->name]) }}
    </div>

    <div class="property-specs">
        {{ $bedroomsSpec }} | {{ $property->bathrooms ? (int) $property->bathrooms : '-' }} baths | {{ $property->sizeArea ? (int) $property->sizeArea : '-' }} sqft
    </div>

    {{-- Description --}}
    @if($property->description)
    <div class="description-text">
        {{ \Illuminate\Support\Str::limit(strip_tags($property->description), 300) }}
    </div>
    @endif

    {{-- Property Details Table --}}
    <table class="details-table">
        <tr>
            <td class="label">Ref. No:</td>
            <td class="value">{{ $property->referenceNumber ?? 'N/A' }}</td>
        </tr>
        <tr>
            <td class="label">Price:</td>
            <td class="value">AED {{ number_format($property->price ?? 0) }}</td>
        </tr>
        <tr>
            <td class="label">Bedrooms:</td>
            <td class="value">{{ $property->bedrooms === null ? 'N/A' : ((int) $property->bedrooms === 0 ? 'Studio' : (int) $property->bedrooms) }}</td>
        </tr>
        <tr>
            <td class="label">Bathrooms:</td>
            <td class="value">{{ $property->bathrooms ? (int) $property->bathrooms : 'N/A' }}</td>
        </tr>
        <tr>
            <td class="label">BUA:</td>
            <td class="value">{{ $property->sizeArea ? (int) $property->sizeArea : 'N/A' }}</td>
        </tr>
        <tr>
            <td class="label">Type:</td>
            <td class="value">{{ $propertyTypeLabel ?? 'N/A' }}</td>
        </tr>
        <tr>
            <td class="label">Furnished:</td>
            <td class="value">{{ $furnishingLabel ?? 'N/A' }}</td>
        </tr>
        <tr>
            <td class="label">Security Deposit:</td>
            <td class="value">{{ $securityDeposit ?? 'N/A' }}</td>
        </tr>
        <tr>
            <td class="label">Agency Fee:</td>
            <td class="value">{{ $agencyFee ?? 'N/A' }}</td>
        </tr>
        <tr>
            <td class="label">Primary View:</td>
            <td class="value">{{ $primaryView ?? 'N/A' }}</td>
        </tr>
    </table>
</div>
@endsection