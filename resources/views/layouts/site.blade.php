<!doctype html>
<html lang="{{ $locale ?? config('app.locale') }}" dir="{{ config('site-builder.locales.'.($locale ?? config('app.locale')).'.dir', 'ltr') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('site-builder::meta')
    @if(config('site-builder.assets.public_css', true))
        <link rel="stylesheet" href="{{ asset('vendor/site-builder/site-builder.css') }}">
    @endif
</head>
<body>
    @if(($headerSlot ?? null) !== null)<header data-site-builder-canvas>{{ $headerSlot }}</header>@endif
    <main data-site-builder-canvas>{{ $slot }}</main>
    @if(($footerSlot ?? null) !== null)<footer data-site-builder-canvas>{{ $footerSlot }}</footer>@endif
    @siteBuilderAssets
</body>
</html>
