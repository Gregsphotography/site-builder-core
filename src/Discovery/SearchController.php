<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Discovery;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SiteBuilder\Core\Rendering\LocaleCatalog;

final class SearchController
{
    public function __invoke(Request $request, ContentIndex $index, LocaleCatalog $locales): JsonResponse
    {
        $values = $request->validate(['q' => 'required|string|min:2|max:200', 'locale' => 'required|string|max:32', 'source' => 'sometimes|string|max:80', 'type' => 'sometimes|string|max:255', 'tag' => 'sometimes|string|max:80']);
        abort_unless($locales->has($values['locale']), 404);
        $results = $index->query(new ContentQuery(source: $values['source'] ?? '*', text: $values['q'], type: $values['type'] ?? '', tag: $values['tag'] ?? '', sort: 'relevance', perPage: 5, search: true), $values['locale']);

        return response()->json(['items' => array_map(fn (ContentItem $item) => ['title' => $item->title, 'url' => $item->url, 'excerpt' => $item->excerpt], $results->items), 'available' => $results->available], $results->available ? 200 : 503, ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
