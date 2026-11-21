<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Routing;

use SiteBuilder\Core\Rendering\LocaleCatalog;

final class RedirectPath
{
    /** The public slug router also accepts historical HTML/PHP filenames as redirect sources. */
    public static function valid(mixed $slug): bool
    {
        return is_string($slug) && strlen($slug) <= 255
            && preg_match('~^[\pL\pN_-]+(?:/[\pL\pN_-]+)*(?:\.(?:html?|php))?$~iuD', $slug) === 1;
    }

    public static function usable(string $slug): bool
    {
        return self::valid($slug) && ! in_array(explode('/', $slug)[0], app(LocaleCatalog::class)->all(), true);
    }

    public static function forDisplay(string $locale, string $slug): string
    {
        $root = SiteRoutes::publicPrefix().($locale === app(LocaleCatalog::class)->default() ? '' : '/'.$locale);

        return ($root.($slug === 'home' ? '' : '/'.implode('/', array_map('rawurlencode', explode('/', $slug))))) ?: '/';
    }
}
