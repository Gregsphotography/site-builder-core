<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Content;

use stdClass;

final readonly class PropertyValues
{
    public JsonObject $props;

    public JsonObject $translations;

    public function __construct(array|stdClass $props = [], array|stdClass $translations = [])
    {
        $this->props = new JsonObject($props);
        $localeObjects = new stdClass;
        foreach ($translations as $locale => $values) {
            $localeObjects->{$locale} = (object) $values;
        }
        $this->translations = new JsonObject($localeObjects);
    }
}
