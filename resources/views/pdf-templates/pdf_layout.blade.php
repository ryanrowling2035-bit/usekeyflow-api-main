<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $property->title ?? 'Property' }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Helvetica, Arial, sans-serif;
            color: #333333;
            font-size: 12px;
            line-height: 1.4;
        }
        .brand-navy { background-color: #0a1628; }
        .text-white { color: #ffffff; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .page-break { page-break-after: always; }
        .avoid-break { page-break-inside: avoid; }

        .footer-bar {
            background-color: #0a1628;
            color: #ffffff;
            padding: 12px 30px;
            font-size: 9px;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
        }
        .footer-bar table {
            width: 100%;
        }
        .footer-bar td {
            vertical-align: middle;
            color: #ffffff;
        }

        @yield('styles')
    </style>
</head>
<body>
    @yield('body')
</body>
</html>
