<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Editing;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Contracts\HasDefaultChildren;
use SiteBuilder\Core\Design\DesignOperations;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;

/** Pure transformations. Persistence still requires an actor, lease, version and whole-document admission. */
final class TreeOperations
{
    public function __construct(private TreeResolver $tree, private ComponentRegistry $components, private DocumentValidator $validator) {}

    public function canInsertType(Document $document, string $type, TreeSlot $slot, ?RenderContext $context = null, ?string $variant = null): bool
    {
        try {
            $definition = $this->components->resolve($type) ?? throw new InvalidArgumentException('The component is not registered.');
            $defaults = $definition->defaults();
            $props = $defaults->props->toObject();
            if ($variant !== null) {
                $props->variant = $variant;
            }

            return $this->canInsertNodes($document, [new Node((string) Str::uuid(), $type, $definition->version(), new JsonObject($props), $defaults->translations, children: $definition instanceof HasDefaultChildren ? $definition->defaultChildren() : [])], $slot, $context);
        } catch (InvalidArgumentException|ValidationException) {
            return false;
        }
    }

    /** A picker filter only. This neither authorizes a source nor grants trusted-copy admission. */
    public function canInsertNodes(Document $document, array $nodes, TreeSlot $slot, ?RenderContext $context = null): bool
    {
        try {
            $this->editableSlot($document, $slot);
            if (! array_is_list($nodes) || array_any($nodes, fn ($node): bool => ! $node instanceof Node)) {
                throw new InvalidArgumentException('A candidate needs an ordered node list.');
            }
            $count = 0;
            $fresh = function (Node $node, int $depth = 1) use (&$fresh, &$count): Node {
                if ($depth > 32 || ++$count > 1000) {
                    throw new InvalidArgumentException('The candidate subtree exceeds physical limits.');
                }

                return new Node((string) Str::uuid(), $node->type, $node->version, $node->props, $node->translations, $node->visibility, array_map(fn (Node $child): Node => $fresh($child, $depth + 1), $node->children), $node->extensions);
            };
            $siblings = [...$this->tree->children($document, $slot), ...array_map($fresh, $nodes)];
            $assembled = $this->tree->withChildren($document, $slot, $siblings);
            $candidate = app(DesignOperations::class)->admitComposition($document, $assembled->nodes, $context ?? new RenderContext((string) config('app.locale', 'en'), 'preview'));
            $this->admit($candidate, $context);

            return true;
        } catch (InvalidArgumentException|ValidationException) {
            return false;
        }
    }

    public function add(Document $document, string $type, TreeSlot $slot, TreePosition $position, ?RenderContext $context = null): Document
    {
        $definition = $this->components->resolve($type) ?? throw new InvalidArgumentException('The component is not registered.');
        $defaults = $definition->defaults();
        $node = new Node((string) Str::uuid(), $type, $definition->version(), $defaults->props, $defaults->translations, children: $definition instanceof HasDefaultChildren ? $definition->defaultChildren() : []);
        $count = 0;

        return $this->insert($document, $slot, $position, [$this->fresh($node, $document, $context, 1, $count)], $context);
    }

    /** Trusted callers still need copy/reference admission before passing externally sourced nodes. */
    public function insert(Document $document, TreeSlot $slot, TreePosition $position, array $nodes, ?RenderContext $context = null): Document
    {
        $this->editableSlot($document, $slot);
        if (! array_is_list($nodes) || array_any($nodes, fn ($node): bool => ! $node instanceof Node)) {
            throw new InvalidArgumentException('An insertion needs an ordered node list.');
        }
        $siblings = $this->tree->children($document, $slot);
        array_splice($siblings, $position->index($siblings), 0, $nodes);

        return $this->admit($this->replaceSlot($document, $slot, $siblings), $context);
    }

    public function move(Document $document, string $id, TreeSlot $source, TreeSlot $destination, TreePosition $position, ?RenderContext $context = null): Document
    {
        $this->editableSlot($document, $source);
        $this->editableSlot($document, $destination);
        $location = $this->tree->assertSlot($document, $id, $source);
        if ($destination->parentId !== null) {
            $parent = $this->tree->locate($document, $destination->parentId);
            if ($destination->parentId === $id || array_any($parent->ancestors, fn (Node $node): bool => $node->id === $id)) {
                throw new InvalidArgumentException('A subtree cannot move into itself or a descendant.');
            }
        }
        $siblings = $this->tree->children($document, $destination);
        $position->index($siblings);
        if ($source->parentId === $destination->parentId && $position->anchor === $id) {
            return $this->admit($document, $context);
        }
        $remaining = $this->tree->children($document, $source);
        array_splice($remaining, $location->index, 1);
        $without = $this->replaceSlot($document, $source, $remaining);
        $target = $this->tree->children($without, $destination);
        array_splice($target, $position->index($target), 0, [$location->node]);

        return $this->admit($this->replaceSlot($without, $destination, $target), $context);
    }

    public function duplicate(Document $document, string $id, TreeSlot $source, ?RenderContext $context = null): Document
    {
        $this->editableSlot($document, $source);
        $location = $this->tree->assertSlot($document, $id, $source);
        $count = 0;

        return $this->insert($document, $source, TreePosition::after($id), [$this->fresh($location->node, $document, $context, 1, $count)], $context);
    }

    public function remove(Document $document, string $id, TreeSlot $source, ?RenderContext $context = null): Document
    {
        $this->editableSlot($document, $source);
        $location = $this->tree->assertSlot($document, $id, $source);
        $siblings = $this->tree->children($document, $source);
        array_splice($siblings, $location->index, 1);

        return $this->admit($this->replaceSlot($document, $source, $siblings), $context);
    }

    public function visibility(Document $document, string $id, TreeSlot $source, bool $visible, ?RenderContext $context = null): Document
    {
        $this->editableSlot($document, $source);
        $location = $this->tree->assertSlot($document, $id, $source);
        $this->supported($location->node, $document);
        $node = $location->node;
        $siblings = $this->tree->children($document, $source);
        $siblings[$location->index] = new Node($node->id, $node->type, $node->version, $node->props, $node->translations, $visible ? 'visible' : 'hidden', $node->children, $node->extensions);

        return $this->admit($this->replaceSlot($document, $source, $siblings), $context);
    }

    public function reorder(Document $document, TreeSlot $slot, array $ids, ?RenderContext $context = null): Document
    {
        $this->editableSlot($document, $slot);
        $siblings = $this->tree->children($document, $slot);
        $existing = array_map(fn (Node $node): string => $node->id, $siblings);
        $sorted = $existing;
        $proposed = $ids;
        sort($sorted);
        sort($proposed);
        if (! array_is_list($ids) || $sorted !== $proposed) {
            throw new InvalidArgumentException('Reorder must name every current sibling exactly once in this exact slot.');
        }
        $byId = array_combine($existing, $siblings);

        return $this->admit($this->replaceSlot($document, $slot, array_map(fn (string $id): Node => $byId[$id], $ids)), $context);
    }

    /** Replaces a physical list, preserving all unrelated nodes and opaque fields. */
    private function replaceSlot(Document $document, TreeSlot $slot, array $children): Document
    {
        return $this->tree->withChildren($document, $slot, $children);
    }

    private function editableSlot(Document $document, TreeSlot $slot): void
    {
        if (app(RenderFeatureRegistry::class)->readOnly($document)) {
            throw new InvalidArgumentException('Unavailable document render requirements make its composition read-only.');
        }
        if ($slot->parentId !== null) {
            $location = $this->tree->locate($document, $slot->parentId);
            foreach ([...$location->ancestors, $location->node] as $parent) {
                $this->supported($parent, $document);
            }
        }
    }

    public function assertWritableSlot(Document $document, TreeSlot $slot): void
    {
        $this->editableSlot($document, $slot);
    }

    private function supported(Node $node, Document $document): void
    {
        $definition = $this->components->resolve($node->type);
        if ($definition === null || $definition->version() !== $node->version || app(RenderFeatureRegistry::class)->readOnly($document, $node)) {
            throw new InvalidArgumentException('Unsupported subtrees are read-only; move or remove their whole physical root.');
        }
    }

    private function fresh(Node $node, Document $document, ?RenderContext $context, int $depth, int &$count): Node
    {
        if ($depth > 32 || ++$count > 1000) {
            throw new InvalidArgumentException('The new subtree exceeds the physical depth/node ceiling.');
        }
        $this->supported($node, $document);
        $children = [];
        foreach ($node->children as $child) {
            $children[] = $this->fresh($child, $document, $context, $depth + 1, $count);
        }

        return new Node((string) Str::uuid(), $node->type, $node->version, $node->props, $node->translations, $node->visibility, $children, $node->extensions);
    }

    private function admit(Document $document, ?RenderContext $context): Document
    {
        $this->validator->validate($document, context: $context);

        return $document;
    }
}
