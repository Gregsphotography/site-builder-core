<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use SiteBuilder\Core\Contracts\MediaResolver;

final class PathMediaResolver implements MediaResolver
{
    public function __construct(private SafeUrl $urls) {}

    public function url(string $path): ?string
    {
        if ($path === '' || ! $this->urls->valid($path, true)) {
            return null;
        }

        return preg_match('~^https?://~i', $path) ? $path : '/'.ltrim($path, '/');
    }
}
