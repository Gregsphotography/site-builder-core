<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

interface NavigationProvider
{
    /** @return list<array{label: string, url: string, children?: array}> */
    public function items(string $location, string $locale): array;
}
