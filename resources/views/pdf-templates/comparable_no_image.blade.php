@extends('pdf-templates.pdf_layout')

@section('styles')
    /* Page size is set via setPaper('a4', 'portrait') in PropertyPdfService;
       only margin is declared here so canvas coords match HTML coords. */
    @page { margin: 0; }

    body {
        margin: 0;
        padding: 0;
        background: #ffffff;
    }

    .content {
        padding: 53px 22px 0 22px;
    }

    .comp-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        border: 1px solid #bdbdbd;
        font-size: 10px;
        line-height: 12px;
        color: #222222;
    }

    .comp-table th {
        background-color: #ffffff;
        padding: 9px 10px;
        text-align: left;
        font-size: 10px;
        line-height: 12px;
        font-weight: 700;
        color: #222222;
        border-bottom: 1px solid #c7c7c7;
    }

    .comp-table td {
        padding: 10px 10px;
        border-bottom: 1px solid #c7c7c7;
        vertical-align: middle;
        color: #222222;
    }

    .comp-table tbody tr.property-row td {
        height: 28px;
    }

    .comp-table tr.averages td {
        height: 13px;
        font-weight: 700;
        color: #222222;
        border-bottom: 0;
    }

    /* Footer (date | user | Page X of Y) is drawn by PropertyPdfService
       via DomPDF canvas page_text() — single coordinate system, perfect
       alignment, and {PAGE_NUM}/{PAGE_COUNT} are substituted there. */
@endsection

@section('body')
<div class="content">
    <table class="comp-table">
        <thead>
            <tr>
                <th style="width:28%;">Title</th>
                <th style="width:18%;">Address</th>
                <th style="width:14%;">Size (sq ft)</th>
                <th style="width:12%;">Bedrooms</th>
                <th style="width:12%;">Bathrooms</th>
                <th style="width:16%;">Price</th>
            </tr>
        </thead>
        <tbody>
            <tr class="property-row">
                <td>{{ $property->title ?? 'N/A' }}</td>
                <td>{{ joinNonEmpty([$property->community?->name, $property->city?->name]) }}</td>
                <td>{{ $property->sizeArea ? (int) $property->sizeArea : 'N/A' }}</td>
                <td>{{ $property->bedrooms ?? 'N/A' }}</td>
                <td>{{ $property->bathrooms ? (int) $property->bathrooms : 'N/A' }}</td>
                <td>AED{{ $property->price ? number_format($property->price, 2) : '0.00' }}</td>
            </tr>
            <tr class="averages">
                <td colspan="2">Averages</td>
                <td>{{ $property->sizeArea ? (float) $property->sizeArea : '0.0' }}</td>
                <td>{{ $property->bedrooms ? number_format($property->bedrooms, 1) : '0.0' }}</td>
                <td>{{ $property->bathrooms ? (int) $property->bathrooms : '0' }}</td>
                <td>AED{{ $property->price ? number_format($property->price, 2) : '0.00' }}</td>
            </tr>
        </tbody>
    </table>
</div>

{{-- All 3 footer items (date | user | Page X of Y) drawn by service via
     canvas page_text(). See PropertyPdfService::injectPageNumbers(). --}}
@endsection
