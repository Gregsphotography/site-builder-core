<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Editing;

use SiteBuilder\Core\Content\Node;

final readonly class TreeLocation
{
    /** @param list<int> $path @param list<Node> $ancestors */
    public function __construct(public Node $node, public ?string $parentId, public int $index, public array $path, public array $ancestors) {}

    public function slot(): TreeSlot
    {
        return $this->parentId === null ? TreeSlot::root() : TreeSlot::children($this->parentId);
    }
}
