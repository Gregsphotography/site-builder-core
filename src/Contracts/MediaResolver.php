<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

interface MediaResolver
{
    public function url(string $path): ?string;
}
