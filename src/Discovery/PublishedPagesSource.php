<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Discovery;

use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Contracts\MediaResolver;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Rendering\ContentOccurrences;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\SafeUrl;
use SiteBuilder\Core\Routing\PageUrlResolver;

final class PublishedPagesSource implements ContentSourceProvider
{
    public function label(): string
    {
        return 'Published pages';
    }

    public function items(ContentContext $context): iterable
    {
        foreach (Page::query()->where('status', 'published')->with('slugs')->orderBy('id')->lazyById(100) as $page) {
            $item = $this->project($page, $context);
            if ($item !== null) {
                yield $item;
            }
        }
    }

    public function hydrate(array $ids, ContentContext $context): array
    {
        $result = [];
        foreach (Page::query()->whereIn('id', array_filter($ids, fn ($id) => ctype_digit((string) $id)))->where('status', 'published')->with('slugs')->get() as $page) {
            $item = $this->project($page, $context);
            if ($item !== null) {
                $result[$item->id] = $item;
            }
        }

        return $result;
    }

    private function project(Page $page, ContentContext $context): ?ContentItem
    {
        $url = app(PageUrlResolver::class)->path($page, $context->locale);
        if ($page->published_content === null || $url === null || data_get($page->seo, $context->locale.'.noindex', false)) {
            return null;
        }
        $document = $page->published_content;
        if (app(DiscoveryConstraint::class)->diagnostics($document, new RenderContext($context->locale), false) !== []) {
            return null;
        }
        $meta = DiscoveryMetadata::values($document, $context->locale);
        if (($meta['searchable'] ?? true) === false && ($meta['listable'] ?? true) === false) {
            return null;
        }
        try {
            $text = [];
            $renderContext = new RenderContext($context->locale, pageId: $page->id, layout: $page->layout);
            foreach (app(ContentOccurrences::class)->visible($document, $renderContext) as $entry) {
                $public = app(ContentTextRegistry::class)->text($entry['node'], $entry['props'], $renderContext);
                $text[] = html_entity_decode(strip_tags(preg_replace('~<(?:br|/p|/li|/h[1-6])\b[^>]*>~i', ' ', $public)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        } catch (ValidationException|\InvalidArgumentException) {
            return null;
        }
        $body = mb_substr(trim(preg_replace('/\s+/u', ' ', implode(' ', $text))), 0, 100000);
        $image = isset($meta['image']) && is_string($meta['image']) ? app(MediaResolver::class)->url($meta['image']) : null;
        if ($image !== null && ! app(SafeUrl::class)->valid($image, true)) {
            $image = null;
        }

        return new ContentItem((string) $page->id, $context->locale, $page->title, $url, ($meta['summary'] ?? '') ?: mb_substr($body, 0, 240), $body, $image, $meta['alt'] ?? '', $meta['date'] ?? $page->published_at?->toDateString(), $page->type ?: 'page', $meta['tags'] ?? [], listable: $meta['listable'] ?? true, searchable: $meta['searchable'] ?? true);
    }
}
