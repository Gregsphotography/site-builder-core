<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Routing;

use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Rendering\LocaleCatalog;

final class PageUrlResolver
{
    public function __construct(private LocaleCatalog $locales) {}

    public function path(Page $page, string $locale): ?string
    {
        if (! $this->locales->has($locale)) {
            return null;
        }
        $slug = $page->slugs->firstWhere('locale', $locale)?->slug;
        if ($slug === null || ! preg_match('~^[\pL\pN_-]+(?:/[\pL\pN_-]+)*$~uD', $slug)) {
            return null;
        }
        $prefix = $locale === $this->locales->default() ? '' : '/'.$locale;

        return SiteRoutes::publicPrefix().$prefix.($slug === 'home' ? '' : '/'.implode('/', array_map('rawurlencode', explode('/', $slug)))) ?: '/';
    }

    public function url(Page $page, string $locale): ?string
    {
        $path = $this->path($page, $locale);

        return $path === null ? null : url($path);
    }

    public function alternates(Page $page, bool $indexableOnly = false): array
    {
        $urls = [];
        foreach ($this->locales->all() as $locale) {
            $url = $this->url($page, $locale);
            if ($url !== null && (! $indexableOnly || ! data_get($page->seo, $locale.'.noindex', false))) {
                $urls[$locale] = $url;
            }
        }

        return $urls;
    }
}
