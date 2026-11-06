<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Fixtures;

use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Contracts\DesignTheme;
use SiteBuilder\Core\Design\DesignControl;
use SiteBuilder\Core\Design\ThemeIdentity;
use SiteBuilder\Core\Rendering\RenderContext;

final class TestDesignTheme implements DesignTheme
{
    public bool $isAvailable = true;

    public int $availabilityChecks = 0;

    public function id(): string
    {
        return 'test.theme';
    }

    public function version(): int
    {
        return 1;
    }

    public function label(): string
    {
        return 'Persistence test theme';
    }

    public function presets(): array
    {
        return ['test' => 'Test', 'alternate' => 'Alternate'];
    }

    public function breakpoints(): array
    {
        return ['compact' => '48rem+', 'wide' => '72rem+'];
    }

    public function controls(ThemeIdentity $theme, string $scope, ?string $componentType = null): array
    {
        return [
            'align' => new DesignControl('align', 'Alignment', ['start' => 'Start', 'center' => 'Centre', 'end' => 'End']),
            'space' => new DesignControl('space', 'Spacing', ['none' => 'None', 'md' => 'Medium', 'lg' => 'Large']),
            'width' => new DesignControl('width', 'Width', ['narrow' => 'Narrow', 'content' => 'Content', 'wide' => 'Wide']),
            'surface' => new DesignControl('surface', 'Surface', ['default' => 'Default', 'accent' => 'Accent']),
            'text' => new DesignControl('text', 'Text', ['default' => 'Default', 'on-accent' => 'On accent']),
            'border' => new DesignControl('border', 'Border', ['none' => 'None', 'subtle' => 'Subtle']),
            'radius' => new DesignControl('radius', 'Radius', ['none' => 'None', 'small' => 'Small']),
            'role' => new DesignControl('role', 'Typography', ['body' => 'Body', 'heading' => 'Heading'], responsive: false),
            'scale' => new DesignControl('scale', 'Scale', ['normal' => 'Normal', 'large' => 'Large']),
        ];
    }

    public function supports(string $type, int $version, ?string $variant): bool
    {
        return $type === 'test.block' && $version === 1;
    }

    public function defaults(ThemeIdentity $theme, ?Node $node, RenderContext $context): array
    {
        return ['align' => 'start', 'space' => 'md', 'width' => $theme->preset === 'alternate' ? 'wide' : 'content', 'surface' => 'default', 'text' => 'default', 'border' => 'none', 'radius' => 'none', 'role' => 'body', 'scale' => 'normal'];
    }

    public function classes(string $key, string $option, string $breakpoint): array|string
    {
        return ['test-design-'.$breakpoint.'-'.$key.'-'.$option];
    }

    public function canvasAttributes(ThemeIdentity $theme): array
    {
        return ['data-test-theme' => $theme->preset];
    }

    public function available(RenderContext $context): bool
    {
        $this->availabilityChecks++;

        return $this->isAvailable;
    }

    public function combinationErrors(ThemeIdentity $theme, array $values, ?Node $node): array
    {
        return ($values['text'] ?? 'default') === 'on-accent' && ($values['surface'] ?? 'default') !== 'accent'
            ? ['On-accent text requires the accent surface.']
            : [];
    }
}
