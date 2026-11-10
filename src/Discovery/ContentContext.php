<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Discovery;

final readonly class ContentContext
{
    public function __construct(public string $locale, public string $scope = 'default') {}
}
