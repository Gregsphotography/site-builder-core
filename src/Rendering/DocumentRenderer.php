<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use Illuminate\Support\HtmlString;
use Illuminate\View\ComponentAttributeBag;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Composition\CompositionData;
use SiteBuilder\Core\Composition\CompositionResolver;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Contracts\ContributesStructuredData;
use SiteBuilder\Core\Contracts\RenderedDocumentProcessor;
use SiteBuilder\Core\Design\DesignData;
use SiteBuilder\Core\Design\DesignResolver;

final class DocumentRenderer
{
    public function __construct(private ComponentRegistry $registry, private DocumentValidator $validator, private PropertyResolver $properties) {}

    public function render(Document $document, RenderContext $context, ?array $rootIds = null): RenderResult
    {
        $diagnostics = $this->validator->diagnostics($document, context: $context);
        if (array_any($diagnostics, fn ($diagnostic) => $diagnostic->code === 'missing_composition_capability')) {
            return new RenderResult(new HtmlString(''), $diagnostics);
        }
        if (CompositionData::payload($document) !== null) {
            return $this->composition($document, $context, $diagnostics, $rootIds);
        }
        $features = app(RenderFeatureRegistry::class);
        $design = app(DesignResolver::class);
        $graph = [];
        $walk = function (array $nodes) use (&$walk, &$graph, $context, $features, $design, $document): string {
            $html = '';
            foreach ($nodes as $node) {
                $definition = $this->registry->resolve($node->type);
                if ($node->visibility === 'hidden' || $definition === null || $definition->version() !== $node->version || ! $features->renderable($document, $node, $context)) {
                    continue;
                }
                $children = array_values(array_filter($node->children, fn ($child): bool => $definition->allowedChildren()->allows($child->type)));
                $props = $this->properties->resolve($node, $definition, $context->locale);
                $resolved = new ResolvedNode($node, new JsonObject($props));
                $presentation = DesignData::payload($document->extensions) !== null || DesignData::payload($node->extensions) !== null ? $design->resolve($document, $node, $context) : null;
                if ($presentation !== null && $presentation->diagnostics !== []) {
                    continue;
                }
                $attributes = new ComponentAttributeBag(['data-site-builder-node' => $node->id, ...($presentation?->attributes ?? [])]);
                $html .= view($definition->view(), ['node' => $resolved, 'props' => $props, 'attributes' => $attributes, 'children' => new HtmlString($walk($children)), 'context' => $context])->render();
                if ($context->mode === 'public' && $definition instanceof ContributesStructuredData) {
                    $graph = [...$graph, ...$definition->structuredData($resolved, $context)];
                }
            }

            return $html;
        };
        $roots = $rootIds === null ? $document->nodes : $this->physicalRoots($document, $rootIds);
        $html = $features->renderable($document, null, $context) ? $walk($roots) : '';
        if ($html !== '' && DesignData::payload($document->extensions) !== null) {
            $canvas = $design->resolve($document, null, $context);
            $attributes = new ComponentAttributeBag(['data-site-builder-design' => '1', ...$canvas->attributes]);
            $html = '<div '.$attributes.'>'.$html.'</div>';
        }

        return $this->processed(new RenderResult(new HtmlString($html), $diagnostics, $graph), $context);
    }

    private function physicalRoots(Document $document, array $ids): array
    {
        $indexed = DesignData::nodes($document);
        if (! array_is_list($ids) || array_any($ids, fn ($id) => ! is_string($id) || ! isset($indexed[$id])) || count($ids) !== count(array_unique($ids))) {
            throw new \InvalidArgumentException('Preview roots must identify current physical document nodes exactly.');
        }

        return array_map(fn ($id) => $indexed[$id], $ids);
    }

    private function composition(Document $document, RenderContext $context, array $diagnostics, ?array $rootIds): RenderResult
    {
        if (app(RenderFeatureRegistry::class)->readOnly($document) || array_any($diagnostics, fn ($diagnostic) => $diagnostic->code === 'unavailable_composition')) {
            return new RenderResult(new HtmlString(''), $diagnostics);
        }
        $resolution = app(CompositionResolver::class)->resolve($document, $context);
        $roots = $resolution->body;
        if ($rootIds !== null) {
            $this->physicalRoots($document, $rootIds);
            $select = function (array $entries) use (&$select, $rootIds): array {
                $selected = [];
                foreach ($entries as $entry) {
                    if (in_array($entry->selectableId, $rootIds, true)) {
                        $selected[] = $entry;
                    } else {
                        $selected = [...$selected, ...$select($entry->children)];
                    }
                }

                return $selected;
            };
            $roots = $select($roots);
        }
        $features = app(RenderFeatureRegistry::class);
        $design = app(DesignResolver::class);
        $graph = [];
        $walk = function (array $entries, ?Document $parentDocument = null) use (&$walk, &$graph, $context, $features, $design): string {
            $html = '';
            foreach ($entries as $entry) {
                $node = $entry->node;
                $definition = $this->registry->resolve($node->type);
                if ($entry->suppressed || $node->visibility === 'hidden' || $definition === null || $definition->version() !== $node->version || ! $features->renderable($entry->document, $node, $context)) {
                    continue;
                }
                $props = $this->properties->resolve($node, $definition, $context->locale);
                $resolved = new ResolvedNode($node, new JsonObject($props));
                $presentation = $design->resolve($entry->document, $node, $context, $entry->presentationOwner, $entry->ancestors);
                if ($presentation->diagnostics !== []) {
                    continue;
                }
                $values = ['data-site-builder-render-address' => $entry->address, ...$presentation->attributes];
                if ($entry->selectableId !== null) {
                    $values['data-site-builder-node'] = $entry->selectableId;
                }
                if ($entry->mounted) {
                    $values['data-site-builder-source-node'] = $node->id;
                }
                if ($entry->binding !== null) {
                    $values['data-site-builder-binding'] = $entry->binding;
                }
                $attributes = new ComponentAttributeBag($values);
                $chunk = view($definition->view(), ['node' => $resolved, 'props' => $props, 'attributes' => $attributes, 'children' => new HtmlString($walk($entry->children, $entry->document)), 'context' => $context])->render();
                if ($entry->mounted && $entry->document !== $parentDocument) {
                    $canvas = $design->resolve($entry->presentationOwner ?? $entry->document, null, $context);
                    if ($canvas->attributes !== []) {
                        $chunk = '<div '.new ComponentAttributeBag(['data-site-builder-global-mount' => $entry->address, ...$canvas->attributes]).'>'.$chunk.'</div>';
                    }
                }
                $html .= $chunk;
                if ($context->mode === 'public' && $definition instanceof ContributesStructuredData) {
                    $graph = [...$graph, ...$definition->structuredData($resolved, $context)];
                }
            }

            return $html;
        };
        $body = $walk($roots, $document);
        $canvas = $design->resolve($document, null, $context);
        if ($body !== '' && $canvas->attributes !== []) {
            $body = '<div '.new ComponentAttributeBag(['data-site-builder-design' => '1', ...$canvas->attributes]).'>'.$body.'</div>';
        }

        $header = $resolution->header === null ? null : new HtmlString($walk($resolution->header));
        $footer = $resolution->footer === null ? null : new HtmlString($walk($resolution->footer));

        return $this->processed(new RenderResult(new HtmlString($body), $diagnostics, $graph, $header, $footer), $context);
    }

    private function processed(RenderResult $result, RenderContext $context): RenderResult
    {
        foreach (app()->tagged(RenderedDocumentProcessor::class) as $processor) {
            $result = $processor->process($result, $context);
        }

        return $result;
    }
}
