<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Discovery;

use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;

final class DiscoveryMetadata
{
    public const KEY = 'site-builder.discovery';

    public static function values(Document $document, string $locale): array
    {
        $data = $document->extensions->get(self::KEY);
        $values = $data instanceof \stdClass && ($data->version ?? null) === 1 ? ($data->locales->{$locale} ?? null) : null;

        return $values instanceof \stdClass ? get_object_vars($values) : [];
    }

    public static function with(Document $document, string $locale, array $values): Document
    {
        $extensions = $document->extensions->toObject();
        $data = $extensions->{self::KEY} ?? (object) ['version' => 1, 'locales' => new \stdClass];
        if (! $data instanceof \stdClass || ($data->version ?? null) !== 1 || ! ($data->locales ?? null) instanceof \stdClass) {
            throw new \InvalidArgumentException('Unsupported discovery metadata must be retained until an explicit migration.');
        }
        $data->locales->{$locale} = (object) $values;
        $extensions->{self::KEY} = $data;

        return new Document($document->nodes, new JsonObject($extensions));
    }
}
