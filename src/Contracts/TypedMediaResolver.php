<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

interface TypedMediaResolver extends MediaResolver
{
    /** image, video or track; null when this adapter cannot determine the kind. */
    public function kind(string $path): ?string;
}
