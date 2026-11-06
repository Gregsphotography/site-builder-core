<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Editing;

use InvalidArgumentException;
use SiteBuilder\Core\Content\Document;

/** Backward-compatible root-only adapters; nested callers use explicit TreeSlot coordinates. */
final class DocumentOperations
{
    public function __construct(private TreeOperations $operations, private TreeResolver $tree) {}

    public function add(Document $document, string $type, ?int $index = null): Document
    {
        $index ??= count($document->nodes);
        if ($index < 0 || $index > count($document->nodes)) {
            throw new InvalidArgumentException('Invalid insertion position.');
        }
        $position = $index === count($document->nodes) ? TreePosition::end() : TreePosition::before($document->nodes[$index]->id);

        return $this->operations->add($document, $type, TreeSlot::root(), $position);
    }

    public function duplicate(Document $document, string $id): Document
    {
        return $this->operations->duplicate($document, $id, TreeSlot::root());
    }

    public function visibility(Document $document, string $id, bool $visible): Document
    {
        return $this->operations->visibility($document, $id, TreeSlot::root(), $visible);
    }

    public function remove(Document $document, string $id): Document
    {
        return $this->operations->remove($document, $id, TreeSlot::root());
    }

    /** @param list<string> $ids */
    public function reorder(Document $document, array $ids): Document
    {
        return $this->operations->reorder($document, TreeSlot::root(), $ids);
    }

    public function topLevelId(Document $document, string $id): ?string
    {
        $location = $this->tree->nodes($document)[$id] ?? null;

        return $location === null ? null : ($location->ancestors[0]->id ?? $location->node->id);
    }
}
