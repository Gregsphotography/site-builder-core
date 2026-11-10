<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Composition\CompositionData;
use SiteBuilder\Core\Composition\CompositionResolver;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Design\DesignData;
use SiteBuilder\Core\Design\DesignResolver;

/** Trusted resolved occurrences keep repeated immutable mounts distinct. */
final class ContentOccurrences
{
    public function __construct(private ComponentRegistry $registry, private PropertyResolver $properties) {}

    /** @return list<array{node:Node,address:string,region:string,props:array}> */
    public function visible(Document $document, RenderContext $context, bool $regions = false): array
    {
        $result = [];
        $features = app(RenderFeatureRegistry::class);
        if (! $features->renderable($document, null, $context)) {
            return [];
        }
        $design = app(DesignResolver::class);
        $walk = function (array $entries, string $region, bool $composed) use (&$walk, &$result, $context, $features, $document, $design): void {
            foreach ($entries as $entry) {
                $node = $composed ? $entry->node : $entry;
                $owner = $composed ? $entry->document : $document;
                $definition = $this->registry->resolve($node->type);
                if (($composed && $entry->suppressed) || $node->visibility === 'hidden' || $definition === null || $definition->version() !== $node->version || ! $features->renderable($owner, $node, $context)) {
                    continue;
                }
                $presentation = $composed
                    ? $design->resolve($owner, $node, $context, $entry->presentationOwner, $entry->ancestors)
                    : ((DesignData::payload($owner->extensions) !== null || DesignData::payload($node->extensions) !== null) ? $design->resolve($owner, $node, $context) : null);
                if ($presentation !== null && $presentation->diagnostics !== []) {
                    continue;
                }
                $result[] = ['node' => $node, 'address' => $composed ? $entry->address : $region.'/'.$node->id, 'region' => $region, 'props' => $this->properties->resolve($node, $definition, $context->locale)];
                $children = $composed ? $entry->children : array_values(array_filter($node->children, fn ($child): bool => $definition->allowedChildren()->allows($child->type)));
                $walk($children, $region, $composed);
            }
        };
        if (CompositionData::payload($document) !== null) {
            $resolution = app(CompositionResolver::class)->resolve($document, $context);
            $walk($resolution->body, 'body', true);
            if ($regions) {
                $walk($resolution->header ?? [], 'header', true);
                $walk($resolution->footer ?? [], 'footer', true);
                foreach ($resolution->overlays as $id => $overlay) {
                    $walk($overlay['nodes'], 'overlay:'.$id, true);
                }
            }
        } else {
            $walk($document->nodes, 'body', false);
        }

        return $result;
    }
}
