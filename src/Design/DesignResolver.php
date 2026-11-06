<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Design;

use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Composition\CompositionResolver;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Diagnostic;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;
use stdClass;

final class DesignResolver
{
    public function __construct(private DesignCatalog $catalog, private ComponentRegistry $components) {}

    public function theme(Document $document, RenderContext $context): ?ThemeIdentity
    {
        $payload = DesignData::payload($document->extensions);
        if ($payload !== null) {
            return DesignData::shape($payload, false) ? new ThemeIdentity($payload->theme->id, $payload->theme->version, $payload->theme->preset) : null;
        }

        try {
            $site = app(CompositionResolver::class)->siteDesign($document, $context);
        } catch (ValidationException|\InvalidArgumentException) {
            return null;
        }
        if ($site !== null) {
            return new ThemeIdentity($site->theme->id, $site->theme->version, $site->theme->preset);
        }

        return $this->catalog->defaultTheme($context);
    }

    public function resolve(Document $document, ?Node $node, RenderContext $context, ?Document $presentationOwner = null, array $ancestorLayers = []): ResolvedPresentation
    {
        $owner = $presentationOwner ?? $document;
        $root = DesignData::payload($owner->extensions);
        try {
            $site = app(CompositionResolver::class)->siteDesign($owner, $context);
        } catch (ValidationException|\InvalidArgumentException $exception) {
            return new ResolvedPresentation(diagnostics: [new Diagnostic('unavailable_composition', '/extensions/site-builder.render/composition/siteDesign', $exception->getMessage())]);
        }
        $own = $node === null ? $root : DesignData::payload($node->extensions);
        $theme = $this->theme($owner, $context);
        $definition = $theme === null ? null : $this->catalog->definition($theme);
        $active = $root !== null || $own !== null || $site !== null || $ancestorLayers !== [];
        $diagnostics = [];
        if (($root !== null && ! DesignData::shape($root, false)) || ($node !== null && $own !== null && ! DesignData::shape($own, true))) {
            $diagnostics[] = new Diagnostic('invalid_design_structure', '/extensions/site-builder.render/design', 'Unsupported design shape/version; retain the original payload.');
        }
        if ($node !== null && $own !== null && $root === null && $site === null && $ancestorLayers === []) {
            $diagnostics[] = new Diagnostic('invalid_design_structure', '/extensions/site-builder.render', 'Node design requires a pinned document design capability.');
        }
        if ($active && ($definition === null || ! $definition->available($context))) {
            $diagnostics[] = new Diagnostic('unavailable_design_theme', '/extensions/site-builder.render/design/theme', 'The pinned theme/version/preset or its compatible published assets are unavailable.');
        }
        if (array_any($diagnostics, fn ($diagnostic): bool => $diagnostic->code === 'invalid_design_structure')) {
            return new ResolvedPresentation($theme, diagnostics: $diagnostics);
        }
        if ($definition === null || $theme === null) {
            return new ResolvedPresentation($theme, diagnostics: $diagnostics);
        }
        if ($node?->type === 'core.global-reference') {
            return new ResolvedPresentation($theme, diagnostics: $diagnostics);
        }
        $controls = $this->catalog->controls($theme, $node === null ? 'document' : 'node', $node?->type);
        $breakpoints = ['base' => 'All sizes', ...$definition->breakpoints()];
        $ancestors = $node === null || $presentationOwner !== null ? [] : $this->ancestors($document, $node->id);
        $layers = [['payload' => $site, 'source' => 'Site design', 'scope' => 'document'], ['payload' => $root, 'source' => 'Page', 'scope' => 'document']];
        $layers = [...$layers, ...$ancestorLayers];
        foreach ($ancestors as $parent) {
            $layers[] = ['payload' => DesignData::payload($parent->extensions), 'source' => 'Parent '.$parent->id, 'scope' => 'ancestor'];
        }
        if ($node !== null) {
            $layers[] = ['payload' => $own, 'source' => 'Section', 'scope' => 'node'];
        }
        if ($active && $node !== null && ($registered = $this->components->resolve($node->type)) !== null && $registered->version() === $node->version && ! $definition->supports($node->type, $node->version, is_string($node->props->get('variant')) ? $node->props->get('variant') : null)) {
            $diagnostics[] = new Diagnostic('incompatible_design_component', '/nodes/'.$node->id, 'The selected theme does not support this component/version/variant.');
        }
        $states = [];
        $classes = [];
        $enabled = [];
        foreach ($breakpoints as $breakpoint => $label) {
            $values = $definition->defaults($theme, $node, $context);
            $sources = array_fill_keys(array_keys($values), 'Theme');
            $enabled = [];
            foreach ($layers as $layer) {
                $payload = $layer['payload'];
                if (! $payload instanceof stdClass) {
                    continue;
                }
                $maps = ['base' => $payload->base ?? new stdClass];
                foreach ($definition->breakpoints() as $candidate => $candidateLabel) {
                    $maps[$candidate] = $payload->responsive->{$candidate} ?? new stdClass;
                    if ($candidate === $breakpoint) {
                        break;
                    }
                }
                if ($breakpoint === 'base') {
                    $maps = ['base' => $payload->base ?? new stdClass];
                }
                foreach ($maps as $sourceBreakpoint => $map) {
                    if (! $map instanceof stdClass) {
                        continue;
                    }
                    foreach ($map as $key => $value) {
                        $control = $controls[$key] ?? null;
                        if ($control === null && $node !== null && $layer['scope'] !== 'node') {
                            continue;
                        }
                        if ($control === null || ! is_string($value) || ! array_key_exists($value, $control->options) || ($sourceBreakpoint !== 'base' && ! $control->responsive)) {
                            $diagnostics[] = new Diagnostic('incompatible_design_value', '/extensions/site-builder.render/design/'.$sourceBreakpoint.'/'.$key, 'An option/control is incompatible with the selected theme/scope.');

                            continue;
                        }
                        $values[$key] = $value;
                        $sources[$key] = $layer['source'].' at '.($breakpoints[$sourceBreakpoint] ?? $sourceBreakpoint);
                        $enabled[$key] = true;
                    }
                }
            }
            foreach ($layers as $layer) {
                foreach (array_keys((array) ($layer['payload']?->responsive ?? new stdClass)) as $candidate) {
                    if (! isset($breakpoints[$candidate])) {
                        $diagnostics[] = new Diagnostic('incompatible_design_breakpoint', '/extensions/site-builder.render/design/responsive/'.$candidate, 'This breakpoint is not supplied by the pinned theme.');
                    }
                }
            }
            foreach ($definition->combinationErrors($theme, $values, $node) as $message) {
                $diagnostics[] = new Diagnostic('incompatible_design_combination', '/extensions/site-builder.render/design/'.$breakpoint, $message);
            }
            $states[$breakpoint] = ['values' => $values, 'sources' => $sources, 'active' => array_keys($enabled)];
            if ($node !== null) {
                foreach (array_keys($enabled) as $key) {
                    if ($breakpoint !== 'base' && ! $controls[$key]->responsive) {
                        continue;
                    }
                    $mapped = $definition->classes($key, $values[$key], $breakpoint);
                    foreach (is_array($mapped) ? $mapped : preg_split('/\s+/', trim($mapped)) as $class) {
                        if (! is_string($class) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_:-]*$/D', $class)) {
                            $diagnostics[] = new Diagnostic('invalid_theme_mapping', '/', 'Trusted theme mappings must contain fixed class tokens.');
                        } else {
                            $classes[] = $class;
                        }
                    }
                }
            }
        }
        $attributes = $node === null ? ($root === null && $site === null ? [] : $definition->canvasAttributes($theme)) : ($classes === [] ? [] : ['class' => implode(' ', array_unique($classes))]);
        foreach ($attributes as $key => $value) {
            if (($key !== 'class' && ! preg_match('/^data-[a-z0-9-]+$/D', $key)) || ! is_string($value)) {
                $diagnostics[] = new Diagnostic('invalid_theme_mapping', '/', 'Canvas mappings may contain only fixed class/data attributes.');
                unset($attributes[$key]);
            }
        }
        $unique = [];
        foreach ($diagnostics as $diagnostic) {
            $unique[$diagnostic->code.'|'.$diagnostic->path.'|'.$diagnostic->message] = $diagnostic;
        }

        return new ResolvedPresentation($theme, $states, $attributes, array_values($unique));
    }

    public function describe(Document $document, DesignTarget $target, RenderContext $context): array
    {
        $node = $target->nodeId === null ? null : DesignData::find($document, $target->nodeId);
        $resolved = $this->resolve($document, $node, $context);
        $definition = $resolved->theme === null ? null : $this->catalog->definition($resolved->theme);
        $controls = $resolved->theme === null ? [] : $this->catalog->controls($resolved->theme, $target->scope, $node?->type);
        $payload = DesignData::payload($node?->extensions ?? $document->extensions);
        $explicit = $target->breakpoint === 'base' ? ($payload?->base ?? new stdClass) : ($payload?->responsive->{$target->breakpoint} ?? new stdClass);
        $supported = $node === null || ($node->type !== 'core.global-reference' && $definition?->supports($node->type, $node->version, is_string($node->props->get('variant')) ? $node->props->get('variant') : null));
        $registered = $node === null ? null : $this->components->resolve($node->type);
        $known = $node === null || ($registered !== null && $registered->version() === $node->version);
        $readOnly = app(RenderFeatureRegistry::class)->readOnly($document) || ($node !== null && app(RenderFeatureRegistry::class)->readOnly($document, $node));

        $diagnostics = $resolved->diagnostics;
        if ($readOnly) {
            $diagnostics = [...$diagnostics, ...app(RenderFeatureRegistry::class)->diagnostics($document, null, $context), ...($node === null ? [] : app(RenderFeatureRegistry::class)->diagnostics($document, $node, $context))];
        }
        if (! $known) {
            $diagnostics[] = new Diagnostic('unsupported_component_version', '/nodes/'.$node->id, 'The selected node definition/version is unavailable and read-only.');
        }

        return ['available' => ! $readOnly && $known && $definition !== null && $definition->available($context) && $supported, 'theme' => $resolved->theme, 'controls' => $readOnly || ! $known ? [] : $controls, 'explicit' => (array) $explicit, 'resolved' => $resolved->states[$target->breakpoint]['values'] ?? [], 'sources' => $resolved->states[$target->breakpoint]['sources'] ?? [], 'diagnostics' => $diagnostics, 'breakpoints' => ['base' => 'All sizes', ...($definition?->breakpoints() ?? [])]];
    }

    /** Content saves can retain unavailable styling but cannot silently strip it. */
    public function assertUnavailablePreserved(?Document $old, Document $new, RenderContext $context): void
    {
        if ($old === null || DesignData::payload($old->extensions) === null) {
            return;
        }
        $theme = $this->theme($old, $context);
        $definition = $theme === null ? null : $this->catalog->definition($theme);
        if ($definition !== null && $definition->available($context)) {
            return;
        }
        $json = app(CanonicalJson::class);
        if (! $json->equals(DesignData::payload($old->extensions), DesignData::payload($new->extensions))) {
            throw ValidationException::withMessages(['design' => 'Unavailable pinned design must remain unchanged during a content save. Use an explicit reviewed theme reset/change.']);
        }
        $nextNodes = DesignData::nodes($new);
        foreach (DesignData::nodes($old) as $id => $node) {
            if (isset($nextNodes[$id]) && ! $json->equals(DesignData::payload($node->extensions), DesignData::payload($nextNodes[$id]->extensions))) {
                throw ValidationException::withMessages(['design' => 'Unavailable node design cannot be stripped or patched by a content save.']);
            }
        }
    }

    private function ancestors(Document $document, string $id): array
    {
        $find = function (array $nodes, array $path) use (&$find, $id): ?array {
            foreach ($nodes as $node) {
                if ($node->id === $id) {
                    return $path;
                } $found = $find($node->children, [...$path, $node]);
                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        };

        return $find($document->nodes, []) ?? [];
    }
}
