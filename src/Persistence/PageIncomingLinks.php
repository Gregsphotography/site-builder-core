<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Persistence;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use SiteBuilder\Core\Components\ReferenceScanner;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Models\Menu;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Routing\RedirectPath;
use SiteBuilder\Core\Routing\SiteRoutes;
use SiteBuilder\Core\SiteParts\SitePart;

final class PageIncomingLinks
{
    public function __construct(private ReferenceScanner $scanner, private LocaleCatalog $locales) {}

    /** Summarize referring owners the actor can inspect, one row per owner and scope. */
    public function report(Authenticatable $actor, Page $target): array
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('view', $target);
        $gate->authorize('delete', $target);
        $paths = $this->paths($target);
        $rows = [];
        foreach (Page::query()->whereKeyNot($target->id)->lazyById(50) as $source) {
            if (! $gate->allows('view', $source)) {
                continue;
            }
            $owner = ['type' => 'Page', 'name' => $source->title, 'id' => $source->id];
            $this->inspect($rows, $owner, 'Draft', fn () => $source->draft_content, $target->id, $paths);
            if ($source->status === 'published') {
                $this->inspect($rows, $owner, 'Published', fn () => $source->published_content, $target->id, $paths);
            }
        }
        foreach (Menu::query()->lazyById(50) as $menu) {
            foreach ((array) $menu->items as $locale => $entries) {
                if ($this->menuReferences($entries, $target->id, $paths)) {
                    // Hosts can expose private menu names through an explicit model policy.
                    $visibleName = in_array($menu->location, ['primary', 'footer'], true) || ($gate->getPolicyFor($menu) && $gate->allows('view', $menu));
                    $rows[] = ['type' => 'Menu', 'name' => $visibleName ? $menu->location : 'Stored menu', 'scope' => (string) $locale];
                }
            }
        }
        if (! Schema::hasTable('site_builder_site_parts')) {
            return $rows;
        }
        foreach (SitePart::query()->lazyById(50) as $part) {
            if (! $gate->allows('view', $part)) {
                continue;
            }
            $owner = ['type' => 'Site part', 'name' => $part->name, 'id' => $part->id];
            $this->inspect($rows, $owner, 'Draft', fn () => $part->draft_payload?->document, $target->id, $paths);
            // Older available revisions can still be pinned to published pages.
            foreach ($part->revisions()->reorder()->whereNull('withdrawn_at')->lazyById(50) as $revision) {
                $this->inspect($rows, $owner, 'Revision '.$revision->sequence, fn () => $revision->payload?->document, $target->id, $paths);
            }
        }

        return $rows;
    }

    private function inspect(array &$rows, array $owner, string $scope, Closure $read, int $id, array $paths): void
    {
        try {
            if ($this->references($read(), $id, $paths)) {
                $rows[] = [...$owner, 'scope' => $scope];
            }
        } catch (\InvalidArgumentException|\JsonException) {
            $rows[] = [...$owner, 'scope' => $scope.' could not be inspected'];
        }
    }

    private function paths(Page $target): array
    {
        $paths = [];
        $add = function (string $locale, string $slug) use (&$paths): void {
            if (! $this->locales->has($locale) || ! RedirectPath::valid($slug)) {
                return;
            }
            $path = RedirectPath::forDisplay($locale, $slug);
            $localized = SiteRoutes::publicPrefix().'/'.$locale;
            $aliases = [$path, $localized.'/'.$slug];
            if ($slug === 'home') {
                $aliases[] = $localized;
                $aliases[] = rtrim($path, '/').'/home';
            }
            foreach ($aliases as $alias) {
                $paths[rawurldecode($alias)] = true;
                $paths[rawurldecode((string) parse_url(url($alias), PHP_URL_PATH)) ?: '/'] = true;
            }
        };
        foreach ([$target, $target->draftView()] as $page) {
            foreach ($page->slugs as $slug) {
                $add($slug->locale, $slug->slug);
            }
        }
        foreach ($target->redirects as $redirect) {
            $add($redirect->locale, $redirect->from_slug);
        }

        return $paths;
    }

    private function references(?Document $document, int $id, array $paths): bool
    {
        if ($document === null) {
            return false;
        }
        foreach ($this->scanner->scan($document, includeLinks: true) as $reference) {
            if (($reference->kind === 'page' && (string) $reference->value === (string) $id)
                || ($reference->kind === 'url' && $this->matchesUrl($reference->value, $paths))) {
                return true;
            }
        }

        return false;
    }

    private function menuReferences(mixed $entries, int $id, array $paths): bool
    {
        foreach (is_array($entries) ? $entries : [] as $entry) {
            $entry = (array) $entry;
            if ((string) ($entry['page'] ?? '') === (string) $id || $this->matchesUrl($entry['url'] ?? null, $paths)
                || $this->menuReferences($entry['children'] ?? [], $id, $paths)) {
                return true;
            }
        }

        return false;
    }

    private function matchesUrl(mixed $url, array $paths): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || str_starts_with($url, '//')) {
            return false;
        }
        if (isset($parts['scheme'])) {
            $origin = parse_url(url('/'));
            if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
                || strcasecmp($parts['host'] ?? '', $origin['host'] ?? '') !== 0
                || $this->customPort($parts) !== $this->customPort($origin)) {
                return false;
            }
        } elseif (! str_starts_with($url, '/')) {
            return false;
        }
        $path = rtrim(rawurldecode($parts['path'] ?? '/'), '/') ?: '/';

        return isset($paths[$path]);
    }

    private function customPort(array $parts): ?int
    {
        $port = $parts['port'] ?? null;
        $default = strtolower($parts['scheme']) === 'https' ? 443 : 80;

        return $port === $default ? null : $port;
    }
}
