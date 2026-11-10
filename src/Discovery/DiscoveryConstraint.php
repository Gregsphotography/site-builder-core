<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Discovery;

use SiteBuilder\Core\Components\PropertyDefinition;
use SiteBuilder\Core\Content\Diagnostic;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Contracts\DocumentConstraint;
use SiteBuilder\Core\Rendering\PropertyResolver;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\SafeUrl;

final class DiscoveryConstraint implements DocumentConstraint
{
    public function diagnostics(Document $document, RenderContext $context, bool $publishing): array
    {
        $data = $document->extensions->get(DiscoveryMetadata::KEY);
        if ($data === null) {
            return [];
        }
        $error = fn () => [new Diagnostic('invalid_discovery_metadata', '/extensions/'.DiscoveryMetadata::KEY, 'Discovery metadata must use version 1, bounded locale fields and typed image references.')];
        if (! $data instanceof \stdClass || ($data->version ?? null) !== 1 || ! ($data->locales ?? null) instanceof \stdClass || array_diff(array_keys(get_object_vars($data)), ['version', 'locales']) !== []) {
            return $error();
        }
        foreach ($data->locales as $locale => $values) {
            if (! is_string($locale) || ! $values instanceof \stdClass || array_diff(array_keys(get_object_vars($values)), ['summary', 'image', 'alt', 'tags', 'date', 'listable', 'searchable']) !== []) {
                return $error();
            }
            $rules = ['summary' => 'nullable|string|max:2000', 'image' => 'nullable|string|max:2048', 'alt' => 'nullable|string|max:2000', 'tags' => 'array|max:32', 'tags.*' => 'string|max:80', 'date' => 'nullable|date_format:Y-m-d', 'listable' => 'boolean', 'searchable' => 'boolean'];
            if (validator(get_object_vars($values), $rules)->fails()) {
                return $error();
            }
            if (is_string($values->image ?? null) && $values->image !== '' && (! app(SafeUrl::class)->valid($values->image, true) || ! app(PropertyResolver::class)->acceptsMedia($values->image, PropertyDefinition::media()))) {
                return $error();
            }
        }

        return [];
    }
}
