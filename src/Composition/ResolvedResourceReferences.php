<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Rendering\PropertyResolver;
use SiteBuilder\Core\Rendering\RenderContext;

final class ResolvedResourceReferences
{
    /** Only references selected by the actual locale/fallback projection are public attachments. */
    public function overlays(Node $node, RenderContext $context): array
    {
        $definition = app(ComponentRegistry::class)->resolve($node->type);
        if ($definition === null || $definition->version() !== $node->version) {
            return [];
        }
        $result = [];
        $walk = function (array $properties, mixed $values) use (&$walk, &$result, $context): void {
            foreach ($properties as $name => $property) {
                $value = data_get($values, $name);
                if ($property->kind === 'revision' && $property->revisionKind === 'overlay' && $value !== null) {
                    $reference = PublishedPartReference::from($value);
                    $result[OverlaySettings::id($reference, $context)] = $reference;
                } elseif ($property->kind === 'object') {
                    $walk($property->properties, $value);
                } elseif ($property->kind === 'list' && is_array($value)) {
                    foreach ($value as $item) {
                        $walk($property->items->properties, $item);
                    }
                }
            }
        };
        $walk($definition->properties(), app(PropertyResolver::class)->resolve($node, $definition, $context->locale));

        return $result;
    }
}
