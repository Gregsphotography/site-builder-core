<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Components;

use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Discovery\DiscoveryMetadata;
use stdClass;

final class ReferenceScanner
{
    public function __construct(private ComponentRegistry $registry) {}

    /** @return list<Reference> */
    public function scan(Document $document): array
    {
        $references = [];
        $escape = fn (string $key): string => str_replace(['~', '/'], ['~0', '~1'], $key);
        $scanProperty = function (mixed $value, PropertyDefinition $definition, string $nodeId, ?string $locale, string $path) use (&$scanProperty, &$references, $escape): void {
            if ($value === null) {
                return;
            }
            if (in_array($definition->kind, ['page', 'media', 'revision'], true)) {
                $references[] = new Reference($nodeId, $locale, $path, $definition->kind, $value, $definition->revisionKind);
            } elseif ($definition->kind === 'list' && is_array($value)) {
                foreach ($value as $index => $item) {
                    $scanProperty($item, $definition->items, $nodeId, $locale, $path.'/'.$index);
                }
            } elseif ($definition->kind === 'object' && $value instanceof stdClass) {
                foreach ($definition->properties as $key => $property) {
                    if (property_exists($value, $key)) {
                        $scanProperty($value->{$key}, $property, $nodeId, $locale, $path.'/'.$escape($key));
                    }
                }
            }
        };
        $walk = function (array $nodes) use (&$walk, $scanProperty, $escape): void {
            foreach ($nodes as $node) {
                $definition = $this->registry->resolve($node->type);
                if ($definition !== null && $definition->version() === $node->version) {
                    foreach ($definition->properties() as $key => $property) {
                        $values = $node->props->toObject();
                        if (property_exists($values, $key)) {
                            $scanProperty($values->{$key}, $property, $node->id, null, '/props/'.$escape($key));
                        }
                        if ($property->translated) {
                            foreach ($node->translations->toObject() as $locale => $values) {
                                if (property_exists($values, $key)) {
                                    $scanProperty($values->{$key}, $property, $node->id, (string) $locale, '/translations/'.$escape((string) $locale).'/'.$escape($key));
                                }
                            }
                        }
                    }
                }
                $walk($node->children);
            }
        };
        $walk($document->nodes);

        $discovery = $document->extensions->get(DiscoveryMetadata::KEY);
        if ($discovery instanceof stdClass && ($discovery->version ?? null) === 1 && ($discovery->locales ?? null) instanceof stdClass) {
            foreach ($discovery->locales as $locale => $values) {
                if ($values instanceof stdClass && is_string($values->image ?? null) && $values->image !== '') {
                    $references[] = new Reference('@document', (string) $locale, '/extensions/site-builder.discovery/locales/'.$escape((string) $locale).'/image', 'media', $values->image);
                }
            }
        }

        return $references;
    }
}
