<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Components;

/** Trusted registration; document data cannot change placement or depth policy. */
final readonly class PlacementPolicy
{
    private function __construct(public bool $rootAllowed, public bool $childAllowed, public bool $structural) {}

    public static function anywhere(bool $structural = false): self
    {
        return new self(true, true, $structural);
    }

    public static function childOnly(bool $structural = false): self
    {
        return new self(false, true, $structural);
    }

    public static function rootOnly(bool $structural = false): self
    {
        return new self(true, false, $structural);
    }
}
