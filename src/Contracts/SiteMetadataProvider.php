<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use SiteBuilder\Core\Content\JsonObject;

interface SiteMetadataProvider
{
    public function metadata(string $locale): JsonObject;
}
