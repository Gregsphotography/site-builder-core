<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use SiteBuilder\Core\Contracts\MediaResolver;
use SiteBuilder\Core\Contracts\SiteMetadataProvider;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageSlug;
use SiteBuilder\Core\Routing\PageUrlResolver;

final class Seo
{
    public function __construct(private PageUrlResolver $urls, private SafeUrl $safeUrls, private MediaResolver $media, private SiteMetadataProvider $site) {}

    public function forPage(Page $page, RenderContext $context, RenderResult $render): array
    {
        $locale = $context->locale;
        $title = data_get($page->seo, $locale.'.meta_title') ?: $page->title;
        if ($context->mode === 'preview') {
            return ['title' => $title, 'robots' => 'noindex, nofollow', 'preview' => true];
        }
        $canonical = data_get($page->seo, $locale.'.canonical');
        $canonical = is_string($canonical) && $canonical !== '' && $this->safeUrls->canonical($canonical) ? url($canonical) : $this->urls->url($page, $locale);
        $image = data_get($page->seo, $locale.'.og_image');
        $image = is_string($image) ? $this->media->url($image) : null;
        $image = $image !== null && $this->safeUrls->valid($image, true) ? url($image) : null;
        $robots = array_filter([data_get($page->seo, $locale.'.noindex') ? 'noindex' : null, data_get($page->seo, $locale.'.nofollow') ? 'nofollow' : null]);

        return ['title' => $title, 'description' => data_get($page->seo, $locale.'.meta_description'), 'canonical' => $canonical, 'alternates' => $this->urls->alternates($page), 'robots' => implode(', ', $robots), 'image' => $image, 'preview' => false, 'graph' => $this->graph($page, $locale, $canonical, $title, $render->structuredData)];
    }

    private function graph(Page $page, string $locale, ?string $canonical, string $title, array $contributions): array
    {
        $site = $this->site->metadata($locale);
        $root = url('/');
        $organization = ['@type' => 'Organization', '@id' => $root.'#organization', 'name' => $site->get('name', config('app.name')), 'url' => $root];
        foreach (['description', 'email', 'telephone'] as $key) {
            if (is_string($site->get($key)) && $site->get($key) !== '') {
                $organization[$key] = $site->get($key);
            }
        }
        $logo = $site->get('logo');
        if (is_string($logo) && ($logo = $this->media->url($logo)) !== null && $this->safeUrls->valid($logo, true)) {
            $organization['logo'] = url($logo);
        }
        $type = data_get($page->schema, 'type', 'WebPage');
        $type = in_array($type, ['WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'ItemPage', 'FAQPage'], true) ? $type : 'WebPage';
        $webPage = ['@type' => $type, '@id' => $canonical.'#webpage', 'url' => $canonical, 'name' => $title, 'inLanguage' => $locale, 'isPartOf' => ['@id' => $root.'#website'], 'about' => ['@id' => $root.'#organization']];
        $description = data_get($page->seo, $locale.'.meta_description');
        if (is_string($description) && $description !== '') {
            $webPage['description'] = $description;
        }
        if ($page->published_at !== null) {
            $webPage['datePublished'] = $page->published_at->toAtomString();
        }
        if ($page->updated_at !== null) {
            $webPage['dateModified'] = $page->updated_at->toAtomString();
        }
        $graph = [$organization, ['@type' => 'WebSite', '@id' => $root.'#website', 'url' => $root, 'name' => $site->get('name', config('app.name')), 'inLanguage' => $locale, 'publisher' => ['@id' => $root.'#organization']], $webPage];
        $faq = [];
        foreach (data_get($page->schema, 'faq.'.$locale, []) ?: [] as $item) {
            $question = data_get($item, 'question');
            $answer = data_get($item, 'answer');
            if (is_string($question) && is_string($answer) && $question !== '' && $answer !== '') {
                $faq[] = ['@type' => 'Question', 'name' => strip_tags($question), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags($answer)]];
            }
        }
        if ($faq !== []) {
            $graph[] = ['@type' => 'FAQPage', '@id' => $canonical.'#faq', 'inLanguage' => $locale, 'mainEntity' => $faq];
        }
        $breadcrumbs = [];
        $mode = data_get($page->schema, 'breadcrumbs.mode', 'auto');
        $items = data_get($page->schema, 'breadcrumbs.items.'.$locale, data_get($page->schema, 'breadcrumbs.'.$locale, []));
        foreach ($mode === 'none' ? [] : ($items ?: []) as $item) {
            $name = data_get($item, 'name', data_get($item, 'label'));
            $url = data_get($item, 'url') ?: $canonical;
            if (is_string($name) && is_string($url) && $this->safeUrls->valid($url)) {
                $breadcrumbs[] = ['@type' => 'ListItem', 'position' => count($breadcrumbs) + 1, 'name' => $name, 'item' => url($url)];
            }
        }
        $slug = $page->slugs->firstWhere('locale', $locale)?->slug;
        if ($mode === 'auto' && $breadcrumbs === [] && $slug !== null && str_contains($slug, '/')) {
            $parts = explode('/', $slug);
            $path = '';
            foreach ($parts as $segment) {
                $path = ltrim($path.'/'.$segment, '/');
                $ancestor = $path === $slug ? $page : PageSlug::query()->where('locale', $locale)->where('slug', $path)->first()?->page;
                $url = $ancestor !== null && $ancestor->status === 'published' ? $this->urls->url($ancestor, $locale) : null;
                $breadcrumbs[] = array_filter(['@type' => 'ListItem', 'position' => count($breadcrumbs) + 1, 'name' => $ancestor?->title ?? ucwords(str_replace(['-', '_'], ' ', $segment)), 'item' => $url], fn ($value): bool => $value !== null);
            }
        }
        if ($breadcrumbs !== []) {
            $graph[] = ['@type' => 'BreadcrumbList', '@id' => $canonical.'#breadcrumbs', 'itemListElement' => $breadcrumbs];
        }

        return ['@context' => 'https://schema.org', '@graph' => [...$graph, ...$contributions]];
    }
}
