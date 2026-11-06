<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Routing;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SiteBuilder\Core\Models\PageRedirect;
use SiteBuilder\Core\Models\PageSlug;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Rendering\PageResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class PageController
{
    public function __invoke(Request $request, LocaleCatalog $locales, PageUrlResolver $urls, PageResponse $response): Response|RedirectResponse
    {
        $rawPath = $request->getPathInfo();
        abort_if(str_starts_with(rawurldecode($rawPath), '//') || str_contains(rawurldecode($rawPath), '\\'), 404);
        if ($rawPath !== '/' && str_ends_with($rawPath, '/')) {
            return redirect(rtrim($rawPath, '/').($request->getQueryString() ? '?'.$request->getQueryString() : ''), 301);
        }
        $prefix = SiteRoutes::publicPrefix();
        abort_unless($prefix === '' || $rawPath === $prefix || str_starts_with($rawPath, $prefix.'/'), 404);
        $path = trim(substr($rawPath, strlen($prefix)), '/');
        if ($path === 'sitemap.xml') {
            return app(Sitemap::class)->response();
        }
        if ($path === 'sitemap_index.xml') {
            return redirect($prefix.'/sitemap.xml', 301);
        }
        if ($path === 'robots.txt') {
            $preview = SiteRoutes::previewPrefix();
            $disallow = str_ends_with($preview, '/preview') ? substr($preview, 0, -strlen('/preview')) : $preview;

            return response("User-agent: *\nDisallow: ".$disallow."/\nSitemap: ".url($prefix.'/sitemap.xml')."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $parts = $path === '' ? [] : explode('/', $path);
        $locale = isset($parts[0]) && $locales->has($parts[0]) ? array_shift($parts) : $locales->default();
        $slug = rawurldecode(implode('/', $parts)) ?: 'home';
        $translation = PageSlug::query()->where('locale', $locale)->where('slug', $slug)->first();
        $page = $translation?->page;
        if ($page === null) {
            $redirect = PageRedirect::query()->where('locale', $locale)->where('from_slug', $slug)->first();
            $target = $redirect?->page;
            $url = $target !== null && $target->status === 'published' ? $urls->path($target, $locale) : null;
            abort_if($url === null, 404);

            return redirect($url, in_array($redirect->status, [301, 302, 307, 308], true) ? $redirect->status : 301);
        }
        abort_unless($page->status === 'published', 404);
        $canonicalPath = $urls->path($page, $locale);
        abort_if($canonicalPath === null, 404);
        if ($slug === 'home' && $rawPath !== $canonicalPath) {
            return redirect($canonicalPath.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 301);
        }

        return $response->render($page, $locale);
    }
}
