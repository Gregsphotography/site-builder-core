<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Fixtures;

use SiteBuilder\Core\Components\ChildPolicy;
use SiteBuilder\Core\Components\PlacementPolicy;
use SiteBuilder\Core\Content\PropertyValues;
use SiteBuilder\Core\Contracts\ComponentDefinition;
use SiteBuilder\Core\Contracts\HasDefaultChildren;
use SiteBuilder\Core\Contracts\HasPlacementPolicy;

final class TreeLayout implements ComponentDefinition, HasDefaultChildren, HasPlacementPolicy
{
    public static int $defaultCalls = 0;

    public function __construct(private string $key, private ChildPolicy $children, private PlacementPolicy $placement, private array $initial = []) {}

    public function type(): string
    {
        return $this->key;
    }

    public function version(): int
    {
        return 1;
    }

    public function defaults(): PropertyValues
    {
        self::$defaultCalls++;

        return new PropertyValues;
    }

    public function properties(): array
    {
        return [];
    }

    public function allowedChildren(): ChildPolicy
    {
        return $this->children;
    }

    public function placement(): PlacementPolicy
    {
        return $this->placement;
    }

    public function defaultChildren(): array
    {
        return $this->initial;
    }

    public function view(): string
    {
        return 'test-blocks::block';
    }
}
