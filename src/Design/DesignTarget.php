<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Design;

final readonly class DesignTarget
{
    private function __construct(public string $scope, public ?string $nodeId, public string $breakpoint) {}

    public static function document(string $breakpoint = 'base'): self
    {
        return new self('document', null, $breakpoint);
    }

    public static function node(string $id, string $breakpoint = 'base'): self
    {
        return new self('node', $id, $breakpoint);
    }
}
