<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Rendering\RenderContext;

final class OverlaySettings
{
    public const KEY = 'site-builder.overlay';

    public static function id(PublishedPartReference $reference, RenderContext $context): string
    {
        return 'sb-overlay-'.substr(hash('sha256', json_encode([$context->pageId, $context->locale, $reference], JSON_THROW_ON_ERROR)), 0, 24);
    }

    public static function values(Document $document, string $locale): array
    {
        $data = $document->extensions->get(self::KEY);
        $default = app(LocaleCatalog::class)->default();

        return ['presentation' => $data->presentation ?? 'dialog', 'size' => $data->size ?? 'medium', 'title' => $data->labels->{$locale} ?? $data->labels->{$default} ?? __('More information')];
    }

    public static function with(Document $document, string $locale, string $title, string $presentation = 'dialog', string $size = 'medium'): Document
    {
        $extensions = $document->extensions->toObject();
        $data = $extensions->{self::KEY} ?? (object) ['version' => 1, 'labels' => new \stdClass];
        if (! $data instanceof \stdClass || ($data->version ?? null) !== 1 || ! ($data->labels ?? null) instanceof \stdClass) {
            throw new \InvalidArgumentException('Unsupported popup settings must be retained until an explicit migration.');
        }
        $data->presentation = $presentation;
        $data->size = $size;
        $data->labels->{$locale} = $title;
        $extensions->{self::KEY} = $data;

        return new Document($document->nodes, new JsonObject($extensions));
    }
}
