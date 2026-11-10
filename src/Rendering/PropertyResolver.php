<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use SiteBuilder\Core\Components\PropertyDefinition;
use SiteBuilder\Core\Composition\PublishedPartReference;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Contracts\ComponentDefinition;
use SiteBuilder\Core\Contracts\MediaResolver;
use SiteBuilder\Core\Contracts\TypedMediaResolver;
use stdClass;

final class PropertyResolver
{
    public function __construct(private LocaleCatalog $locales, private RichTextSanitizer $html, private SafeUrl $urls, private MediaResolver $media) {}

    public function raw(Node $node, string $key, PropertyDefinition $property, string $locale): mixed
    {
        $translations = $node->translations->toObject();

        return $property->translated
            ? ($translations->{$locale}->{$key} ?? $translations->{$this->locales->default()}->{$key} ?? $node->props->get($key))
            : $node->props->get($key);
    }

    public function resolve(Node $node, ComponentDefinition $definition, string $locale): array
    {
        $values = [];
        foreach ($definition->properties() as $key => $property) {
            $values[$key] = $this->safe($this->raw($node, $key, $property, $locale), $property);
        }

        return $values;
    }

    private function safe(mixed $value, PropertyDefinition $property): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($property->kind) {
            'text' => is_string($value) ? $value : null,
            'html' => is_string($value) ? $this->html->sanitize($value) : '',
            'url' => is_string($value) && $this->urls->valid($value) ? $value : null,
            'media' => $this->mediaUrl($value, $property),
            'enum' => in_array($value, $property->options, true) ? $value : null,
            'token' => is_string($value) && in_array($value, $property->options, true) ? $this->token($value) : null,
            'boolean' => is_bool($value) ? $value : null,
            'number' => is_int($value) || is_float($value) ? $value : null,
            'page' => is_int($value) && $value > 0 ? $value : null,
            'revision' => $this->revision($value),
            'list' => is_array($value) && array_is_list($value) ? array_map(fn ($item) => $this->safe($item, $property->items), $value) : [],
            'object' => $value instanceof stdClass ? $this->object($value, $property) : new stdClass,
        };
    }

    private function revision(mixed $value): ?stdClass
    {
        try {
            return PublishedPartReference::from($value)->jsonSerialize();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function token(string $key): ?string
    {
        $value = config('site-builder.tokens.'.$key);

        return is_string($value) && preg_match('/^(#[a-fA-F0-9]{3,8}|-?[0-9.]+(?:rem|em|px|%|vh|vw)|[a-zA-Z]+)$/D', $value) ? $value : null;
    }

    private function mediaUrl(mixed $value, PropertyDefinition $property): ?string
    {
        if (! $this->acceptsMedia($value, $property)) {
            return null;
        }
        $url = is_string($value) ? $this->media->url($value) : null;

        return $url !== null && $this->urls->valid($url, true) ? $url : null;
    }

    public function acceptsMedia(mixed $value, PropertyDefinition $property): bool
    {
        if (is_string($value) && $this->media instanceof TypedMediaResolver) {
            $kind = $this->media->kind($value);
            if ($kind !== null && $kind !== $property->mediaKind) {
                return false;
            }
        }

        return true;
    }

    private function object(stdClass $value, PropertyDefinition $property): stdClass
    {
        $result = new stdClass;
        foreach ($property->properties as $key => $definition) {
            $result->{$key} = $this->safe($value->{$key} ?? null, $definition);
        }

        return $result;
    }
}
