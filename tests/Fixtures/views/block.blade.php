<section {{ $attributes }}><h2>{{ $props['heading'] ?? '' }}</h2><div>{!! $props['body'] ?? '' !!}</div>
@if ($props['link'])<a href="{{ $props['link'] }}">Read</a>@endif
@if ($props['image'])<img src="{{ $props['image'] }}" alt="Landscape">@endif
@foreach ($props['items'] ?? [] as $item)<span>{{ $item->label ?? '' }}</span>@endforeach
{{ $children }}</section>
