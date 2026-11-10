<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use SiteBuilder\Core\Contracts\BreadcrumbProvider;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageSlug;
use SiteBuilder\Core\Routing\PageUrlResolver;

final class PublishedBreadcrumbs implements BreadcrumbProvider
{
    public function __construct(private PageUrlResolver $urls) {}

    public function items(RenderContext $context): array
    {
        $page = $context->pageId === null ? null : Page::query()->find($context->pageId);
        if ($page === null) {
            return [];
        }
        $slug = $page->slugs()->where('locale', $context->locale)->value('slug');
        $segments = is_string($slug) ? explode('/', $slug) : [];
        $paths = $segments === ['home'] ? [] : ['home'];
        for ($i = 1; $i < count($segments); $i++) {
            $paths[] = implode('/', array_slice($segments, 0, $i));
        }
        $items = [];
        foreach ($paths as $path) {
            $ancestor = PageSlug::query()->where('locale', $context->locale)->where('slug', $path)->first()?->page;
            $url = $ancestor?->status === 'published' ? $this->urls->url($ancestor, $context->locale) : null;
            if ($ancestor !== null && $url !== null && $ancestor->id !== $page->id) {
                $items[] = ['label' => $ancestor->title, 'url' => $url];
            }
        }
        $items[] = ['label' => $page->title, 'url' => null];

        return $items;
    }
}
