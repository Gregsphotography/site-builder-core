<section id="{{ $identity }}" class="sb-overlay sb-overlay--{{ $settings['presentation'] }} sb-overlay--{{ $settings['size'] }}" data-sb-overlay aria-labelledby="{{ $identity }}-title">
    <header class="sb-overlay-header"><h2 id="{{ $identity }}-title">{{ $settings['title'] }}</h2><button type="button" data-overlay-close hidden aria-label="{{ __('Close') }}">×</button></header>
    <div class="sb-overlay-content">{!! $content !!}</div>
</section>
