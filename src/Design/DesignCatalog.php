<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Design;

use Closure;
use InvalidArgumentException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Contracts\DesignTheme;
use SiteBuilder\Core\Rendering\RenderContext;

final class DesignCatalog
{
    private array $themes = [];

    private ?Closure $default = null;

    public function register(DesignTheme $theme): void
    {
        $key = $theme->id().'@'.$theme->version();
        if (isset($this->themes[$key]) || $theme->version() < 1) {
            throw new InvalidArgumentException('Design theme/version registrations must be unique.');
        }
        foreach ($theme->presets() as $preset => $label) {
            new ThemeIdentity($theme->id(), $theme->version(), $preset);
        }
        foreach ($theme->breakpoints() as $keyName => $label) {
            if (! is_string($keyName) || $keyName === 'base' || ! preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $keyName) || ! is_string($label)) {
                throw new InvalidArgumentException('Trusted design breakpoints require stable IDs/labels.');
            }
        }
        $this->themes[$key] = $theme;
    }

    public function bindDefault(callable $resolver): void
    {
        $this->default = Closure::fromCallable($resolver);
    }

    public function defaultTheme(RenderContext $context): ?ThemeIdentity
    {
        return $this->default === null ? null : ($this->default)($context);
    }

    public function definition(ThemeIdentity $identity): ?DesignTheme
    {
        $theme = $this->themes[$identity->id.'@'.$identity->version] ?? null;

        return $theme !== null && array_key_exists($identity->preset, $theme->presets()) ? $theme : null;
    }

    public function controls(ThemeIdentity $theme, string $scope, ?string $componentType = null): array
    {
        $controls = $this->definition($theme)?->controls($theme, $scope, $componentType) ?? [];
        foreach ($controls as $key => $control) {
            if (! $control instanceof DesignControl || $key !== $control->key) {
                throw new InvalidArgumentException('Theme controls must be typed and keyed by their stable ID.');
            }
        }

        return $controls;
    }

    public function themes(RenderContext $context): array
    {
        $entries = [];
        foreach ($this->themes as $definition) {
            foreach ($definition->presets() as $preset => $label) {
                $entries[] = ['key' => $definition->id().'@'.$definition->version().':'.$preset, 'id' => $definition->id(), 'version' => $definition->version(), 'preset' => $preset, 'label' => $definition->label().' — '.$label, 'available' => $definition->available($context)];
            }
        }

        return $entries;
    }

    /** null preserves legacy insertion where no trusted destination adapter exists. */
    public function permittedVariants(Document $destination, RenderContext $context, string $type): ?array
    {
        $theme = app(DesignResolver::class)->theme($destination, $context);
        $definition = $theme === null ? null : $this->definition($theme);
        if ($definition === null || ! $definition->available($context)) {
            return DesignData::payload($destination->extensions) === null ? null : [];
        }
        $component = app(ComponentRegistry::class)->resolve($type);
        if ($component === null) {
            return [];
        }
        $property = $component->properties()['variant'] ?? null;
        $options = $property?->kind === 'enum' ? $property->options : [];

        return array_values(array_filter($options, fn ($variant): bool => $definition->supports($type, $component->version(), $variant)));
    }
}
