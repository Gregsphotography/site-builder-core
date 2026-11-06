<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use InvalidArgumentException;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Editing\TreePosition;
use SiteBuilder\Core\Editing\TreeResolver;
use SiteBuilder\Core\Editing\TreeSlot;

final readonly class InsertPosition
{
    private function __construct(public string $placement, public ?string $anchor = null, private ?TreeSlot $treeSlot = null) {}

    public static function in(TreeSlot $slot, TreePosition $position): self
    {
        return new self($position->placement, $position->anchor, $slot);
    }

    public function slot(): TreeSlot
    {
        return $this->treeSlot ?? TreeSlot::root();
    }

    public static function start(): self
    {
        return new self('start');
    }

    public static function end(): self
    {
        return new self('end');
    }

    public static function before(string $id): self
    {
        return new self('before', $id);
    }

    public static function after(string $id): self
    {
        return new self('after', $id);
    }

    public function index(Document $document): int
    {
        $siblings = app(TreeResolver::class)->children($document, $this->slot());
        if ($this->placement === 'start') {
            return 0;
        }
        if ($this->placement === 'end') {
            return count($siblings);
        }
        $index = array_find_key($siblings, fn (Node $node): bool => $node->id === $this->anchor);
        if ($index === null) {
            throw new InvalidArgumentException('The selected top-level insertion anchor is unavailable.');
        }

        return $index + ($this->placement === 'after' ? 1 : 0);
    }
}
