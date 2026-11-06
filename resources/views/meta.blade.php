<title>{{ $seo['title'] ?? $title ?? config('app.name') }}</title>
@if (!empty($seo['robots']))
    <meta name="robots" content="{{ $seo['robots'] }}">
@endif
@if (isset($seo) && empty($seo['preview']))
    @if (!empty($seo['description']))
        <meta name="description" content="{{ $seo['description'] }}">
    @endif
    @if (!empty($seo['canonical']))
        <link rel="canonical" href="{{ $seo['canonical'] }}">
    @endif
    @foreach ($seo['alternates'] as $code => $url)
        <link rel="alternate" hreflang="{{ $code }}" href="{{ $url }}">
    @endforeach
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    <meta name="twitter:card" content="{{ $seo['image'] ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $seo['title'] }}">
    @if (!empty($seo['description']))
        <meta property="og:description" content="{{ $seo['description'] }}">
        <meta name="twitter:description" content="{{ $seo['description'] }}">
    @endif
    @if ($seo['image'])
        <meta property="og:image" content="{{ $seo['image'] }}">
    @endif
    <script type="application/ld+json">{!! json_encode($seo['graph'], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endif
