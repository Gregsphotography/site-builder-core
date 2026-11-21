<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Review {{ $review->settings->title }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f7f8fa; color: #182433; font: 1rem/1.5 system-ui, sans-serif; }
        main { max-width: 72rem; margin: auto; padding: 2rem 1.25rem; overflow-wrap: anywhere; }
        h1 { margin: .25em 0; line-height: 1.15; }
        h2 { margin-top: 0; font-size: 1.3rem; }
        section { margin: 1rem 0; padding: 1.25rem; background: white; border: 1px solid #d2d7df; border-radius: .5rem; }
        a { color: #194f8a; text-underline-offset: .15em; }
        :focus-visible { outline: 3px solid #245b88; outline-offset: 3px; }
        iframe { display: block; width: 100%; height: min(70vh, 36rem); border: 1px solid #bbc3cd; }
        label, dt { display: block; font-weight: 600; }
        label { margin-top: 1rem; }
        dd { margin: 0 0 .75rem; }
        input, textarea, button { padding: .6rem .85rem; border: 1px solid #737f8d; border-radius: .35rem; font: inherit; }
        input, textarea { width: 100%; max-width: 42rem; }
        textarea { min-height: 6rem; resize: vertical; }
        button { margin: .5rem .5rem .5rem 0; background: white; color: inherit; cursor: pointer; }
        button[value="approved"] { background: #194f61; color: white; border-color: #194f61; }
    </style>
</head>
<body>
<main>
    <header>
        <p>Client review · Draft {{ $review->draft_version }}</p>
        <h1>{{ $review->settings->title }}</h1>
        @if ($review->status === 'changes_requested')
            <p>Your feedback has been recorded for the editor.</p>
        @elseif (! $hasLivePage)
            <p>This page is awaiting publication.</p>
        @elseif ($changes)
            <p>This version changes {{ implode(', ', $changes) }}.</p>
        @else
            <p>This version matches the live page.</p>
        @endif
        @if ($review->status !== 'changes_requested')<a href="#decision">Go to your decision</a>@endif
    </header>

    @if (!empty($differences))
        <section>
            <h2>Publication changes</h2>
            <dl>
                @foreach ($differences as [$field, $before, $after])
                    <dt>{{ $field }}</dt>
                    <dd>Live: {{ filled($before) ? $before : 'None' }}<br>Proposed: {{ filled($after) ? $after : 'None' }}</dd>
                @endforeach
            </dl>
        </section>
    @endif

    @foreach ($languages ?? [] as $language => $details)
        <section>
            <h2>{{ config('site-builder.locales.'.$language.'.label') ?: strtoupper($language) }} version</h2>
            <dl>
                <dt>Page URL</dt>
                <dd>{{ $details['url'] ?? 'No public URL' }}</dd>
                <dt>Search title</dt>
                <dd>{{ $details['seo']['title'] }}</dd>
                @foreach (['description' => 'Search description', 'canonical' => 'Canonical URL', 'robots' => 'Search indexing instructions', 'image' => 'Social image'] as $key => $label)
                    @if (filled($details['seo'][$key]))
                        <dt>{{ $label }}</dt>
                        <dd>{{ $details['seo'][$key] }}</dd>
                    @endif
                @endforeach
            </dl>
            @if ($details['live'])
                <p><a href="{{ $details['live'] }}" target="_blank" rel="noreferrer">Compare the live {{ strtoupper($language) }} page</a></p>
            @endif
            <p><a href="{{ $details['preview'] }}" target="_blank" rel="noreferrer">Open {{ strtoupper($language) }} preview in a new tab</a></p>
            <iframe loading="lazy" sandbox="allow-same-origin" title="{{ strtoupper($language) }} page preview" src="{{ $details['preview'] }}"></iframe>
        </section>
    @endforeach

    <section>
        <h2 id="decision" tabindex="-1">Your decision</h2>
        @if ($review->status === 'pending')
            <p>Your decision applies to draft {{ $review->draft_version }} and its localized versions.</p>
            @if ($errors)
                <ul role="alert">
                    @foreach ($errors as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif
            <form method="post" action="{{ $action }}">
                @csrf
                <label for="reviewer">Your name</label>
                <input id="reviewer" name="reviewer" autocomplete="name" required maxlength="120" value="{{ is_string(request('reviewer')) ? request('reviewer') : '' }}">
                <label for="feedback">Feedback (required when requesting changes)</label>
                <textarea id="feedback" name="feedback" maxlength="4000">{{ is_string(request('feedback')) ? request('feedback') : '' }}</textarea>
                <div>
                    <button name="decision" value="approved">Approve this version</button>
                    <button name="decision" value="changes_requested">Request changes</button>
                </div>
            </form>
        @else
            <p>{{ $review->status === 'changes_requested' ? 'Changes requested' : 'Approved' }} by {{ $review->reviewer }}.</p>
            @if ($review->feedback)<p>{{ $review->feedback }}</p>@endif
        @endif
    </section>
</main>
</body>
</html>
