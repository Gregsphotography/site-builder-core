<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Design\ThemeIdentity;
use SiteBuilder\Core\Rendering\RenderContext;

interface DesignTheme
{
    public function id(): string;

    public function version(): int;

    public function label(): string;

    public function presets(): array;

    public function breakpoints(): array;

    public function controls(ThemeIdentity $theme, string $scope, ?string $componentType = null): array;

    public function supports(string $type, int $version, ?string $variant): bool;

    public function defaults(ThemeIdentity $theme, ?Node $node, RenderContext $context): array;

    public function classes(string $key, string $option, string $breakpoint): array|string;

    public function canvasAttributes(ThemeIdentity $theme): array;

    public function available(RenderContext $context): bool;

    public function combinationErrors(ThemeIdentity $theme, array $values, ?Node $node): array;
}
