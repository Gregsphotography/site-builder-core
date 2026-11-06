<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Editing;

use InvalidArgumentException;
use SiteBuilder\Core\Content\Node;

final readonly class TreePosition
{
    private function __construct(public string $placement, public ?string $anchor = null) {}

    public static function start(): self
    {
        return new self('start');
    }

    public static function end(): self
    {
        return new self('end');
    }

    public static function before(string $anchor): self
    {
        return new self('before', $anchor);
    }

    public static function after(string $anchor): self
    {
        return new self('after', $anchor);
    }

    /** @param list<Node> $siblings */
    public function index(array $siblings): int
    {
        if ($this->placement === 'start') {
            return 0;
        }
        if ($this->placement === 'end') {
            return count($siblings);
        }
        $index = array_find_key($siblings, fn (Node $node): bool => $node->id === $this->anchor);
        if ($index === null) {
            throw new InvalidArgumentException('The insertion anchor is not a current sibling in this exact slot.');
        }

        return $index + (int) ($this->placement === 'after');
    }
}
