<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Design;

use InvalidArgumentException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Composition\CompositionData;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;
use stdClass;

final class DesignOperations
{
    public function __construct(private DesignCatalog $catalog, private DesignResolver $resolver, private ComponentRegistry $components, private RenderFeatureRegistry $features) {}

    private function writable(Document $document, DesignTarget $target): void
    {
        if ($this->features->readOnly($document)) {
            throw new InvalidArgumentException('Unavailable document render features are read-only.');
        }
        if ($target->nodeId !== null) {
            $node = DesignData::find($document, $target->nodeId);
            $definition = $this->components->resolve($node->type);
            if ($definition === null || $definition->version() !== $node->version || $this->features->readOnly($document, $node)) {
                throw new InvalidArgumentException('Unsupported nodes/render features are read-only.');
            }
        }
    }

    public function set(Document $document, DesignTarget $target, string $key, string $option, ?RenderContext $context = null): Document
    {
        return $this->patch($document, $target, [$key => $option], $context);
    }

    public function reset(Document $document, DesignTarget $target, ?string $key = null, ?RenderContext $context = null): Document
    {
        $this->writable($document, $target);
        $payload = DesignData::payload($target->nodeId === null ? $document->extensions : DesignData::find($document, $target->nodeId)->extensions);
        if ($payload === null) {
            return $document;
        }
        $map = $target->breakpoint === 'base' ? ($payload->base ?? new stdClass) : ($payload->responsive->{$target->breakpoint} ?? new stdClass);

        return $this->patch($document, $target, $key === null ? array_fill_keys(array_keys((array) $map), null) : [$key => null], $context);
    }

    public function resetScope(Document $document, DesignTarget $target, ?RenderContext $context = null): Document
    {
        $this->writable($document, $target);
        $payload = DesignData::payload($target->nodeId === null ? $document->extensions : DesignData::find($document, $target->nodeId)->extensions);
        if ($payload === null) {
            return $document;
        }
        unset($payload->base, $payload->responsive);

        return DesignData::replace($document, $target->nodeId, $target->nodeId !== null ? null : $payload);
    }

    public function patch(Document $document, DesignTarget $target, array $changes, ?RenderContext $context = null): Document
    {
        $this->writable($document, $target);
        $context ??= new RenderContext(config('app.locale', 'en'));
        $theme = $this->resolver->theme($document, $context) ?? throw new InvalidArgumentException('This host has no compatible design adapter.');
        $definition = $this->catalog->definition($theme);
        if ($definition === null || ! $definition->available($context)) {
            throw new InvalidArgumentException('The selected design theme/assets are unavailable.');
        }
        if ($target->breakpoint !== 'base' && ! array_key_exists($target->breakpoint, $definition->breakpoints())) {
            throw new InvalidArgumentException('Unknown design breakpoint.');
        }
        $node = $target->nodeId === null ? null : DesignData::find($document, $target->nodeId);
        if ($node !== null && ! $definition->supports($node->type, $node->version, is_string($node->props->get('variant')) ? $node->props->get('variant') : null)) {
            throw new InvalidArgumentException('This component has no compatible design controls.');
        }
        $controls = $this->catalog->controls($theme, $target->scope, $node?->type);
        foreach ($changes as $key => $option) {
            $control = $controls[$key] ?? null;
            if ($control === null || ($option !== null && (! is_string($option) || ! array_key_exists($option, $control->options))) || ($target->breakpoint !== 'base' && ! $control->responsive)) {
                throw new InvalidArgumentException('Design changes must use declared scope/control/breakpoint option IDs.');
            }
        }
        if ($changes === []) {
            return $document;
        }
        $root = DesignData::payload($document->extensions) ?? (object) ['version' => 1, 'theme' => (object) $theme->toArray()];
        $document = DesignData::replace($document, null, $root);
        $payload = $node === null ? $root : (DesignData::payload($node->extensions) ?? (object) ['version' => 1]);
        $map = $target->breakpoint === 'base' ? ($payload->base ?? new stdClass) : ($payload->responsive->{$target->breakpoint} ?? new stdClass);
        foreach ($changes as $key => $option) {
            if ($option === null) {
                unset($map->{$key});
            } else {
                $map->{$key} = $option;
            }
        }
        if ($target->breakpoint === 'base') {
            $payload->base = $map;
        } else {
            $payload->responsive ??= new stdClass;
            $payload->responsive->{$target->breakpoint} = $map;
        }
        $payload = $this->prune($payload, $node !== null);
        $next = DesignData::replace($document, $target->nodeId, $payload);
        $this->assertCompatible($next, $context);

        return $next;
    }

    public function setTheme(Document $document, ?ThemeIdentity $theme, RenderContext $context): Document
    {
        $this->writable($document, DesignTarget::document());
        if ($theme === null) {
            $hasNodeDesign = array_any(DesignData::nodes($document), fn ($node): bool => DesignData::payload($node->extensions) !== null);
            $payload = DesignData::payload($document->extensions);
            if (! $hasNodeDesign && ($payload === null || ((array) ($payload->base ?? new stdClass) === [] && (array) ($payload->responsive ?? new stdClass) === []))) {
                return DesignData::replace($document, null, null);
            }
            $theme = $this->catalog->defaultTheme($context) ?? throw new InvalidArgumentException('No inherited host design adapter is available.');
        }
        $definition = $this->catalog->definition($theme);
        if ($definition === null || ! $definition->available($context)) {
            throw new InvalidArgumentException('The requested theme/version/preset is unavailable.');
        }
        $payload = DesignData::payload($document->extensions) ?? (object) ['version' => 1];
        $payload->theme = (object) $theme->toArray();

        return DesignData::replace($document, null, $payload);
    }

    public function report(Document $document, ?ThemeIdentity $theme, RenderContext $context): DesignCompatibilityReport
    {
        try {
            $candidate = $this->setTheme($document, $theme, $context);
        } catch (InvalidArgumentException $exception) {
            return new DesignCompatibilityReport(false, [['code' => 'unavailable_design_theme', 'message' => $exception->getMessage()]]);
        }
        $diagnostics = $this->compatibility($candidate, $context);
        $before = $this->resolver->resolve($document, null, $context)->states['base']['values'] ?? [];
        $after = $this->resolver->resolve($candidate, null, $context)->states['base']['values'] ?? [];
        $changed = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changed[$key] = ['before' => $before[$key] ?? null, 'after' => $after[$key] ?? null];
            }
        }
        $beforeTheme = $this->resolver->theme($document, $context)?->toArray();
        $afterTheme = $this->resolver->theme($candidate, $context)?->toArray();
        if ($beforeTheme !== $afterTheme) {
            $changed['theme'] = ['before' => $beforeTheme, 'after' => $afterTheme, 'message' => 'Changing the theme/preset changes its trusted palette, typography and other appearance defaults, even when semantic option IDs are unchanged.'];
        }

        return new DesignCompatibilityReport($diagnostics === [], $diagnostics, $changed);
    }

    private function prune(stdClass $payload, bool $node): ?stdClass
    {
        if ((array) ($payload->base ?? new stdClass) === []) {
            unset($payload->base);
        }
        foreach ((array) ($payload->responsive ?? new stdClass) as $breakpoint => $map) {
            if ((array) $map === []) {
                unset($payload->responsive->{$breakpoint});
            }
        }
        if ((array) ($payload->responsive ?? new stdClass) === []) {
            unset($payload->responsive);
        }

        return $node && ! isset($payload->base) && ! isset($payload->responsive) ? null : $payload;
    }

    public function compatibility(Document $document, RenderContext $context): array
    {
        $diagnostics = $this->resolver->resolve($document, null, $context)->diagnostics;
        foreach (DesignData::nodes($document) as $node) {
            $diagnostics = [...$diagnostics, ...$this->resolver->resolve($document, $node, $context)->diagnostics];
        }

        return array_map(fn ($diagnostic): array => ['code' => $diagnostic->code, 'path' => $diagnostic->path, 'message' => $diagnostic->message], $diagnostics);
    }

    public function assertCompatible(Document $document, RenderContext $context): void
    {
        if (($errors = $this->compatibility($document, $context)) !== []) {
            throw new InvalidArgumentException($errors[0]['message']);
        }
    }

    /** Apply section-owned design under the destination's own pinned/default theme. */
    public function admitComposition(Document $destination, array $nodes, RenderContext $context): Document
    {
        if ($this->features->readOnly($destination)) {
            throw new InvalidArgumentException('Unavailable destination render capabilities are read-only.');
        }
        $candidate = CompositionData::promote(new Document($nodes, $destination->extensions));
        $hasDesign = array_any(DesignData::nodes($candidate), fn ($node): bool => DesignData::payload($node->extensions) !== null);
        if ($hasDesign && DesignData::payload($candidate->extensions) === null) {
            $theme = $this->resolver->theme($destination, $context) ?? throw new InvalidArgumentException('Node design needs an available destination theme adapter.');
            $definition = $this->catalog->definition($theme);
            if ($definition === null || ! $definition->available($context)) {
                throw new InvalidArgumentException('The destination design theme/assets are unavailable.');
            }
            $candidate = DesignData::replace($candidate, null, (object) ['version' => 1, 'theme' => (object) $theme->toArray()]);
        }
        $theme = $this->resolver->theme($candidate, $context);
        $definition = $theme === null ? null : $this->catalog->definition($theme);
        $beforeIds = DesignData::nodes($destination);
        $designed = DesignData::payload($candidate->extensions) !== null || $hasDesign;
        if ($definition !== null && $definition->available($context)) {
            foreach (DesignData::nodes($candidate) as $node) {
                if (! $designed && isset($beforeIds[$node->id])) {
                    continue;
                }
                if ($node->type === 'core.global-reference') {
                    continue;
                }
                $registered = $this->components->resolve($node->type);
                if ($registered !== null && $registered->version() === $node->version && ! $definition->supports($node->type, $node->version, is_string($node->props->get('variant')) ? $node->props->get('variant') : null)) {
                    throw new InvalidArgumentException('The destination theme does not support an inserted component/version/variant.');
                }
            }
        }
        if ($designed) {
            $this->assertCompatible($candidate, $context);
        }

        return $candidate;
    }
}
