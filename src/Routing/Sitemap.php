<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Routing;

use Illuminate\Http\Response;
use SiteBuilder\Core\Models\Page;

final class Sitemap
{
    public function __construct(private PageUrlResolver $urls) {}

    public function response(): Response
    {
        $entries = [];
        foreach (Page::query()->with('slugs')->where('status', 'published')->lazyById(100) as $page) {
            $alternates = $this->urls->alternates($page, true);
            foreach ($alternates as $locale => $url) {
                $entries[] = ['url' => $url, 'lastmod' => $page->published_at?->toAtomString(), 'alternates' => $alternates];
            }
        }

        return response(view('site-builder::sitemap', ['entries' => $entries])->render(), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=0, must-revalidate']);
    }
}
