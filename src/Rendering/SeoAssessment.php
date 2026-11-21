<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Routing\PageUrlResolver;

final class SeoAssessment
{
    public function __construct(
        private Seo $seo,
        private PageUrlResolver $urls,
        private LocaleCatalog $locales,
        private ContentOccurrences $content,
        private ComponentRegistry $components,
    ) {}

    /**
     * The caller supplies the authorized pages, so duplicate checks share the
     * same visibility boundary as the rows. Published rows use the live snapshot.
     *
     * @param  iterable<Page>  $pages
     */
    public function rows(iterable $pages, ?string $language = null): array
    {
        $rows = [];
        $duplicates = [];
        $languages = $language === null ? $this->locales->all() : [$language];
        $default = $this->locales->default();
        foreach ($pages as $page) {
            $published = $page->status === 'published';
            $snapshot = $published ? $page : $page->draftView();
            foreach ($languages as $locale) {
                $metadata = $this->seo->metadata($snapshot, $locale);
                $url = $this->urls->url($snapshot, $locale);
                $noindex = (bool) data_get($snapshot->seo, $locale.'.noindex');
                $issues = [];
                if ($url === null) {
                    $issues[] = $this->setting('missing_url', 'Missing URL for this language', 'slug');
                }
                if (blank($metadata['title'])) {
                    $issues[] = $this->setting('missing_title', 'Missing search title', 'meta_title');
                }
                if (blank($metadata['description'])) {
                    $issues[] = $this->setting('missing_description', 'Missing search description', 'meta_description');
                }
                if ($metadata['image'] === null) {
                    $issues[] = $this->setting('missing_image', 'Missing social image', 'og_image');
                }
                if ($noindex) {
                    $issues[] = $this->setting('noindex', 'Excluded from indexing', 'noindex');
                }
                if ($published && ! $noindex && $url !== null && $metadata['canonical'] !== $url) {
                    $issues[] = $this->setting('other_canonical', 'Canonical points to a different URL', 'canonical');
                }

                if ($locale !== $default) {
                    $issues = array_merge($issues, $this->translationIssues($snapshot, $locale, $published));
                }

                $rows[] = [
                    'id' => $page->getKey(), 'page' => $snapshot->title, 'locale' => $locale,
                    'published' => $published, 'url' => $url, 'noindex' => $noindex,
                    'title' => $metadata['title'], 'usesPageTitle' => ! data_get($snapshot->seo, $locale.'.meta_title'),
                    'issues' => $issues,
                ];
                if ($published && $url !== null && ! $noindex) {
                    $index = array_key_last($rows);
                    foreach (['title', 'description'] as $field) {
                        $value = mb_strtolower(Str::squish((string) $metadata[$field]));
                        if ($value === '') {
                            continue;
                        }
                        $first = $duplicates[$locale][$field][$value] ?? null;
                        if ($first === null) {
                            $duplicates[$locale][$field][$value] = $index;
                        } else {
                            // Key the issue by field so the first row is marked once
                            // even when three or more pages share the same value.
                            $issue = $this->setting('duplicate_'.$field, 'Duplicate search '.$field, 'meta_'.$field);
                            $rows[$first]['issues'][$field] = $rows[$index]['issues'][$field] = $issue;
                        }
                    }
                }
            }
        }

        return $rows;
    }

    private function setting(string $code, string $label, string $field): array
    {
        return ['code' => $code, 'label' => $label, 'target' => ['panel' => 'settings', 'field' => $field]];
    }

    private function translationIssues(Page $page, string $locale, bool $published): array
    {
        $issues = [];
        try {
            $document = $published ? $page->published_content : $page->draft_content;
            if ($document === null) {
                return [];
            }
            $context = new RenderContext($locale, $published ? 'public' : 'preview', (int) $page->getKey(), $page->layout);
            if (! app(RenderFeatureRegistry::class)->renderable($document, null, $context)) {
                throw new InvalidArgumentException('Unavailable content');
            }
            foreach ($this->content->visible($document, $context, regions: true) as $position => $entry) {
                $node = $entry['node'];
                $translations = $node->translations->get($locale);
                foreach ($this->components->resolve($node->type)->properties() as $key => $property) {
                    // Match the renderer's null-coalescing fallback. An explicitly
                    // empty translation suppresses its fallback and is intentional.
                    if (! $property->translated || isset($translations->{$key}) || blank($entry['props'][$key] ?? null)) {
                        continue;
                    }
                    $target = $entry['mounted']
                        ? array_filter(['panel' => 'site-parts', 'node' => $entry['selectableId']], fn ($value) => $value !== null)
                        : ['panel' => 'sections', 'node' => $node->id, 'field' => $key];
                    $section = Str::headline(Str::afterLast($node->type, '.')).' · Block '.($position + 1);
                    if ($entry['mounted']) {
                        $section = 'Shared '.$section;
                    }

                    $issues[] = ['code' => 'translation_fallback', 'label' => $section.' · '.Str::headline($key).' uses fallback content', 'target' => $target, 'sharedPart' => $entry['sourcePartId'], 'node' => $node->id, 'field' => $key];
                }
            }
        } catch (ValidationException|InvalidArgumentException) {
            $issues[] = ['code' => 'translation_unavailable', 'label' => 'Content unavailable for translation review', 'target' => ['panel' => 'sections']];
        }

        return $issues;
    }
}
