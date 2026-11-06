<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Editing;

use InvalidArgumentException;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\Node;

/** Resolves stored physical nodes only; mounted global content is never an edit address. */
final class TreeResolver
{
    public function locate(Document $document, string $id): TreeLocation
    {
        return $this->nodes($document)[$id] ?? throw new InvalidArgumentException('The addressed node is no longer present in this document.');
    }

    public function children(Document $document, TreeSlot $slot): array
    {
        return $slot->parentId === null ? $document->nodes : $this->locate($document, $slot->parentId)->node->children;
    }

    public function slotFor(Document $document, string $id): TreeSlot
    {
        return $this->locate($document, $id)->slot();
    }

    /** @return array<string, TreeLocation> */
    public function nodes(Document $document): array
    {
        $result = [];
        $walk = function (array $nodes, array $path, array $ancestors) use (&$walk, &$result): void {
            if (count($path) >= 32 && $nodes !== []) {
                throw new InvalidArgumentException('The physical tree depth limit is exceeded.');
            }
            foreach ($nodes as $index => $node) {
                if (! $node instanceof Node || isset($result[$node->id]) || count($result) >= 1000) {
                    throw new InvalidArgumentException('The physical tree needs unique identities within its bounded node count.');
                }
                $nodePath = [...$path, $index];
                $result[$node->id] = new TreeLocation($node, $ancestors === [] ? null : $ancestors[count($ancestors) - 1]->id, $index, $nodePath, $ancestors);
                if ($node->children !== []) {
                    $walk($node->children, $nodePath, [...$ancestors, $node]);
                }
            }
        };
        $walk($document->nodes, [], []);

        return $result;
    }

    public function assertSlot(Document $document, string $id, TreeSlot $expected): TreeLocation
    {
        $location = $this->locate($document, $id);
        if ($location->parentId !== $expected->parentId) {
            throw new InvalidArgumentException('The node is no longer in the expected parent/sibling slot.');
        }

        return $location;
    }

    /** Pure list replacement for guarded composition assemblers; validate the full result before persistence. */
    public function withChildren(Document $document, TreeSlot $slot, array $children): Document
    {
        if ($slot->parentId === null) {
            return new Document($children, $document->extensions);
        }
        $this->locate($document, $slot->parentId);
        $walk = function (array $nodes) use (&$walk, $slot, $children): array {
            return array_map(fn (Node $node): Node => new Node($node->id, $node->type, $node->version, $node->props, $node->translations, $node->visibility, $node->id === $slot->parentId ? $children : $walk($node->children), $node->extensions), $nodes);
        };

        return new Document($walk($document->nodes), $document->extensions);
    }
}
