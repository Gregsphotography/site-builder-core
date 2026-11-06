<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Editing;

use InvalidArgumentException;

final readonly class TreeSlot
{
    private function __construct(public string $kind, public ?string $parentId) {}

    public static function root(): self
    {
        return new self('root', null);
    }

    public static function children(string $parentId): self
    {
        if ($parentId === '') {
            throw new InvalidArgumentException('A child slot needs a stable parent identity.');
        }

        return new self('children', $parentId);
    }
}
