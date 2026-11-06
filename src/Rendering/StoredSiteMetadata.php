<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Contracts\SiteMetadataProvider;
use SiteBuilder\Core\Models\Setting;
use stdClass;

final class StoredSiteMetadata implements SiteMetadataProvider
{
    public function metadata(string $locale): JsonObject
    {
        $data = Setting::query()->where('namespace', 'site')->first()?->data;

        return new JsonObject($data instanceof stdClass ? $data : []);
    }
}
