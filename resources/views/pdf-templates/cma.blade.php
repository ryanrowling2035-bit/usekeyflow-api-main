@extends('pdf-templates.pdf_layout')

@section('styles')
    @page { margin: 0; }

    body {
        margin: 0;
        padding: 0;
        background: #ffffff;
        color: #2b2b2b;
    }

    .content {
        padding: 98px 34px 0 34px;
    }

    .page-title {
        font-size: 20px;
        line-height: 24px;
        font-weight: 700;
        color: #2b2b2b;
        margin-bottom: 58px;
    }

    .section-title {
        font-size: 14px;
        line-height: 18px;
        font-weight: 700;
        color: #2b2b2b;
        margin-bottom: 18px;
    }

    .listing-card {
        border: 1px solid #dddddd;
        margin-bottom: 20px;
    }

    .listing-card table {
        width: 100%;
        border-collapse: collapse;
    }

    .listing-card td {
        padding: 0;
        vertical-align: top;
    }

    .listing-card .listing-content {
        width: 100%;
        border-collapse: collapse;
    }

    .listing-card .listing-content td {
        padding: 0 !important;
        line-height: 17px;
    }

    .thumb-cell {
        width: 176px;
        padding: 10px 10px 10px 10px !important;
    }

    .listing-thumb {
        width: 165px;
        height: 112px;
        object-fit: cover;
    }

    .listing-placeholder {
        width: 165px;
        height: 112px;
        background: #e5e5e5;
    }

    .listing-label {
        width: 110px;
        font-size: 10px;
        line-height: 17px;
        color: #777777;
        font-weight: 400;
        vertical-align: top;
    }

    .listing-value {
        font-size: 10px;
        line-height: 17px;
        color: #222222;
        font-weight: 400;
        vertical-align: top;
    }

    .listing-main {
        width: 280px;
        padding: 12px 8px 12px 0 !important;
    }

    .listing-details {
        width: auto;
        padding: 12px 12px 12px 8px !important;
    }

    .cumulative-title {
        font-size: 14px;
        line-height: 18px;
        font-weight: 700;
        color: #2b2b2b;
        margin-top: 0;
        margin-bottom: 18px;
    }

    .cma-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        border: 1px solid #dddddd;
        font-size: 10px;
        line-height: 12px;
    }

    .cma-table th {
        background-color: #ffffff;
        padding: 12px 4px 10px 4px;
        text-align: center;
        font-size: 10px;
        line-height: 12px;
        font-weight: 400;
        color: #777777;
        border-bottom: 1px solid #dddddd;
    }

    .cma-table td {
        padding: 12px 4px;
        border-bottom: 0;
        color: #222222;
        vertical-align: middle;
        text-align: center;
    }

    .cma-table td.row-label {
        color: #777777;
        text-align: left;
        font-weight: 400;
    }
@endsection

@section('body')
@php
    $price = $property->price ? 'AED'.number_format($property->price, 2) : 'AED0.00';
    $propertyType = $property->propertyCategories?->name ?? 'N/A';
    $builtYear = $property->yearBuilt ?? null;
@endphp

<div class="content">
    {{-- Page Title --}}
    <div class="page-title">Comparable Market Analysis</div>

    {{-- Published Listings --}}
    <div class="section-title">Published listings (1)</div>

    <div class="listing-card">
        <table>
            <tr>
                {{-- Thumbnail --}}
                <td class="thumb-cell">
                    @if($heroImageSrc)
                        <img src="{{ $heroImageSrc }}" class="listing-thumb" alt="Property">
                    @else
                        <div class="listing-placeholder"></div>
                    @endif
                </td>

                {{-- Property name & location --}}
                <td class="listing-main">
                    <table class="listing-content">
                        <tr>
                            <td class="listing-label">Name:</td>
                            <td class="listing-value">{{ $property->title ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <td class="listing-label">Address:</td>
                            <td class="listing-value">{{ $property->community?->name ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <td class="listing-label">City:</td>
                            <td class="listing-value">{{ $property->city?->name ?? 'N/A' }}</td>
                        </tr>
                    </table>
                </td>

                {{-- Property details --}}
                <td class="listing-details">
                    <table class="listing-content">
                        <tr>
                            <td class="listing-label">Listing price:</td>
                            <td class="listing-value">{{ $price }}</td>
                        </tr>
                        <tr>
                            <td class="listing-label">Property type:</td>
                            <td class="listing-value">{{ $propertyType }}</td>
                        </tr>
                        <tr>
                            <td class="listing-label">Total area:</td>
                            <td class="listing-value">{{ $property->sizeArea ? (int) $property->sizeArea . ' sq ft' : 'N/A' }}</td>
                        </tr>
                        <tr>
                            <td class="listing-label">Bedrooms</td>
                            <td class="listing-value">{{ $property->bedrooms ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <td class="listing-label">Bathrooms</td>
                            <td class="listing-value">{{ $property->bathrooms ? (int) $property->bathrooms : 'N/A' }}</td>
                        </tr>
                        <tr>
                            <td class="listing-label">Size</td>
                            <td class="listing-value">{{ $property->sizeArea ? (int) $property->sizeArea : 'N/A' }}</td>
                        </tr>
                        <tr>
                            <td class="listing-label">Year Built</td>
                            <td class="listing-value">{{ $builtYear ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <td class="listing-label">Type</td>
                            <td class="listing-value">{{ $propertyType }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    {{-- Cumulative Analysis --}}
    <div class="cumulative-title">Cumulative Analysis</div>

    <table class="cma-table">
        <thead>
            <tr>
                <th style="width:8%;"></th>
                <th style="width:7%;">Number of<br>listings</th>
                <th style="width:8%;">Average<br>bedrooms</th>
                <th style="width:8%;">Average<br>bathrooms</th>
                <th style="width:13%;">Average listing<br>price</th>
                <th style="width:11%;">Average<br>sale price</th>
                <th style="width:11%;">Average<br>price ROC</th>
                <th style="width:17%;">Lowest price<br>listing</th>
                <th style="width:17%;">Highest price<br>listing</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="row-label">Active<br>listings</td>
                <td>1</td>
                <td>{{ $property->bedrooms ? number_format($property->bedrooms, 1) : '0.0' }}</td>
                <td>{{ $property->bathrooms ? (int) $property->bathrooms : '0' }}</td>
                <td>{{ $price }}</td>
                <td></td>
                <td></td>
                <td>{{ $price }}</td>
                <td>{{ $price }}</td>
            </tr>
        </tbody>
    </table>
</div>
@endsection
