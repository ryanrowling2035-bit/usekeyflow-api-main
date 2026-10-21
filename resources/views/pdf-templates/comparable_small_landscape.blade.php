@extends('pdf-templates.pdf_layout')

@section('styles')
    /* Custom landscape: 650 × 450 pt (~9.0" × 6.25") — narrower and taller
       than the previous 700×400 so the table content fits without truncation.
       Service uses matching setPaper([0,0,650,450]) — keep both in sync. */
    @page { size: 650pt 450pt; margin: 0; }

    body {
        margin: 0;
        padding: 0;
        background: #ffffff;
        color: #000000 !important;
    }

    /* Force pure black everywhere inside the comparable table — overrides
       any inherited color (parent layout sets body color: #333) and any
       DomPDF rendering quirks that produce a warm tint on normal-weight text */
    .comp-table,
    .comp-table *,
    .comp-table th,
    .comp-table td,
    .comp-table tr {
        color: #000000 !important;
    }

    .content {
        padding: 30px 24px 0 24px;
    }

    .comp-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        border: 1px solid #bdbdbd;
        font-size: 9px;
        line-height: 14px;
        color: #000000;
    }

    .comp-table th {
        background-color: #ffffff;
        padding: 6px 8px;
        text-align: center;
        font-size: 9px;
        line-height: 14px;
        font-weight: 700;
        color: #000000;
        border-bottom: 1px solid #c7c7c7;
    }

    .comp-table td {
        padding: 8px 8px;
        border-bottom: 1px solid #c7c7c7;
        vertical-align: middle;
        color: #000000;
        font-size: 9px;
    }

    .comp-table tbody tr.property-row td {
        height: 100px;
    }

    .comp-table td.image-cell {
        padding: 6px 8px;
        text-align: left;
    }

    .comp-table td.title-cell,
    .comp-table td.address-cell,
    .comp-table td.type-cell {
        text-align: center;
    }

    .comp-table td.number-cell,
    .comp-table td.price-cell {
        text-align: center;
    }

    .comp-table tr.averages td {
        height: 22px;
        font-weight: 700;
        color: #000000;
        background-color: #ffffff;
        border-bottom: 0;
    }

    .thumb-img {
        width: 130px;
        height: 85px;
        object-fit: cover;
    }

    .thumb-placeholder {
        width: 130px;
        height: 85px;
        background: #e5e5e5;
    }
@endsection

@section('body')
@php
    $price = $property->price ? '$'.number_format($property->price, 2) : '$0.00';
    $averagePrice = $property->price ? '$'.number_format($property->price) : '$0';
    $builtYear = $property->yearBuilt ?? null;
@endphp

<div class="content">
    <table class="comp-table">
        <thead>
            <tr>
                <th style="width:18%;"></th>
                <th style="width:15%;">Title</th>
                <th style="width:13%;">Address</th>
                <th style="width:10%;">Type</th>
                <th style="width:10%;">Size (sqft)</th>
                <th style="width:7%;">Bed-<br>rooms</th>
                <th style="width:8%;">Bath-<br>rooms</th>
                <th style="width:8%;">Built</th>
                <th style="width:11%;">Price</th>
            </tr>
        </thead>
        <tbody>
            <tr class="property-row">
                <td class="image-cell">
                    @if($heroImageSrc)
                        <img src="{{ $heroImageSrc }}" class="thumb-img" alt="Property">
                    @else
                        <div class="thumb-placeholder"></div>
                    @endif
                </td>
                <td class="title-cell">{{ $property->title ?? 'N/A' }}</td>
                <td class="address-cell">{{ joinNonEmpty([$property->community?->name, $property->city?->name]) }}</td>
                <td class="type-cell">{{ $property->propertyCategories?->name ?? 'N/A' }}</td>
                <td class="number-cell">{{ $property->sizeArea ? (int) $property->sizeArea : 'N/A' }}</td>
                <td class="number-cell">{{ $property->bedrooms ?? 'N/A' }}</td>
                <td class="number-cell">{{ $property->bathrooms ? (int) $property->bathrooms : 'N/A' }}</td>
                <td class="number-cell">{{ $builtYear ?? 'N/A' }}</td>
                <td class="price-cell">{{ $price }}</td>
            </tr>
            <tr class="averages">
                <td colspan="4">Averages</td>
                <td class="number-cell">{{ $property->sizeArea ? (float) $property->sizeArea : '0.0' }}</td>
                <td class="number-cell">{{ $property->bedrooms ? number_format($property->bedrooms, 1) : '0.0' }}</td>
                <td class="number-cell">{{ $property->bathrooms ? (int) $property->bathrooms : '0' }}</td>
                <td class="number-cell">{{ $builtYear ? number_format($builtYear, 1) : '0.0' }}</td>
                <td class="price-cell">{{ $averagePrice }}</td>
            </tr>
        </tbody>
    </table>
</div>
@endsection
