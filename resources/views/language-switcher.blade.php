@if (config('site-builder.language_switcher', true))
    @php
        $privatePreview = $preview ?? $seo['preview'] ?? false;
        $languages = $seo['alternates'] ?? [];
        if ($privatePreview && isset($page)) {
            $languages = app(\SiteBuilder\Core\Routing\PageUrlResolver::class)->alternates($page);
        }
    @endphp
    @if (count($languages) > 1)
        <nav class="sb-language-switcher" aria-label="{{ __('Languages') }}">
            @foreach ($languages as $code => $url)
                @php
                    $languageTag = str_replace('_', '-', $code);
                    $label = config('site-builder.locales.'.$code.'.label') ?: $code;
                @endphp
                @if ($privatePreview)
                    <span lang="{{ $languageTag }}" dir="auto" @if ($code === $locale) aria-current="page" @endif>{{ $label }}</span>
                @else
                    <a href="{{ $url }}" lang="{{ $languageTag }}" hreflang="{{ $languageTag }}" dir="auto" @if ($code === $locale) aria-current="page" @endif>{{ $label }}</a>
                @endif
            @endforeach
        </nav>
    @endif
@endif
