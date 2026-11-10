<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Content;

use Illuminate\Contracts\Validation\Factory;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\PropertyDefinition;
use SiteBuilder\Core\Composition\CompositionData;
use SiteBuilder\Core\Composition\PublishedPartReference;
use SiteBuilder\Core\Contracts\DocumentConstraint;
use SiteBuilder\Core\Contracts\ValidatesComponentValues;
use SiteBuilder\Core\Design\DesignData;
use SiteBuilder\Core\Design\DesignResolver;
use SiteBuilder\Core\Exceptions\InvalidDocument;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Rendering\PropertyResolver;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;
use SiteBuilder\Core\Rendering\SafeUrl;
use stdClass;

final class DocumentValidator
{
    public function __construct(private ComponentRegistry $registry, private DocumentCodec $codec, private Factory $validation, private SafeUrl $urls, private LocaleCatalog $locales, private PropertyResolver $resolver) {}

    /** @return list<Diagnostic> */
    public function diagnostics(Document $document, bool $publishing = false, array $locales = [], ?RenderContext $context = null): array
    {
        $context ??= new RenderContext($this->locales->default(), $publishing ? 'public' : 'preview');
        $features = app(RenderFeatureRegistry::class);
        $this->codec->encode($document);
        $diagnostics = $this->registry->diagnostics($document);
        if (CompositionData::hasReferences($document->nodes) && CompositionData::payload($document) === null) {
            $diagnostics[] = new Diagnostic('missing_composition_capability', '/extensions/site-builder.render', 'Global references require the owner composition capability.');
        }
        $walk = function (array $nodes, string $path) use (&$walk, &$diagnostics, $publishing, $locales, $context, $features, $document): void {
            foreach ($nodes as $index => $node) {
                $location = $path.'/'.$index;
                $nodeDiagnostics = $features->diagnostics($document, $node, $context);
                if ($node->extensions->get('site-builder.render') === null && DesignData::payload($document->extensions) !== null) {
                    $nodeDiagnostics = [...$nodeDiagnostics, ...app(DesignResolver::class)->resolve($document, $node, $context)->diagnostics];
                }
                foreach ($nodeDiagnostics as $diagnostic) {
                    $diagnostics[] = new Diagnostic($diagnostic->code, $location.$diagnostic->path, $diagnostic->message);
                }
                $definition = $this->registry->resolve($node->type);
                if ($definition !== null && $definition->version() === $node->version) {
                    if ($publishing && ! view()->exists($definition->view())) {
                        $diagnostics[] = new Diagnostic('missing_component_view', $location, 'The registered component view is unavailable.');
                    }
                    $properties = $definition->properties();
                    $maps = ['props' => $node->props->toObject()];
                    foreach ($node->translations->toObject() as $locale => $values) {
                        if (! $this->locales->has($locale)) {
                            $diagnostics[] = new Diagnostic('unconfigured_locale', $location.'/translations/'.$locale, 'This locale is retained without generating a route.');
                        }
                        $maps['translations/'.$locale] = $values;
                    }
                    foreach ($maps as $map => $values) {
                        foreach ($values as $key => $value) {
                            $property = $properties[$key] ?? null;
                            $propertyPath = $location.'/'.$map.'/'.$key;
                            if ($property === null) {
                                $diagnostics[] = new Diagnostic('unknown_property', $propertyPath, 'Undeclared data is retained but never passed to a view.');
                            } elseif ($map !== 'props' && ! $property->translated) {
                                $diagnostics[] = new Diagnostic('shared_property_translation', $propertyPath, 'Shared properties cannot be overridden by a translation.');
                            } else {
                                $this->value($value, $property, $propertyPath, $diagnostics);
                            }
                        }
                        if ($definition instanceof ValidatesComponentValues) {
                            $declared = array_intersect_key((array) $values, $properties);
                            foreach ($definition->diagnostics(new JsonObject($declared), new RenderContext($map === 'props' ? $context->locale : substr($map, 13), $context->mode, $context->pageId, $context->layout), false) as $diagnostic) {
                                $diagnostics[] = new Diagnostic($diagnostic->code, $location.'/'.$map.$diagnostic->path, $diagnostic->message);
                            }
                        }
                    }
                    if ($publishing) {
                        foreach ($locales ?: [$this->locales->default()] as $locale) {
                            $resolved = [];
                            foreach ($properties as $key => $property) {
                                $value = $this->resolver->raw($node, $key, $property, $locale);
                                $resolved[$key] = $value;
                                $this->required($value, $property, $location.'/'.$locale.'/'.$key, $diagnostics);
                            }
                            if ($definition instanceof ValidatesComponentValues) {
                                foreach ($definition->diagnostics(new JsonObject($resolved), new RenderContext($locale, $context->mode, $context->pageId, $context->layout), true) as $diagnostic) {
                                    $diagnostics[] = new Diagnostic($diagnostic->code, $location.'/'.$locale.$diagnostic->path, $diagnostic->message);
                                }
                            }
                        }
                    }
                }
                $walk($node->children, $location.'/children');
            }
        };
        $diagnostics = [...$diagnostics, ...$features->diagnostics($document, null, $context)];
        $walk($document->nodes, '/nodes');
        foreach (app()->tagged(DocumentConstraint::class) as $constraint) {
            $diagnostics = [...$diagnostics, ...$constraint->diagnostics($document, $context, $publishing)];
        }

        return $diagnostics;
    }

    public function validate(Document $document, bool $publishing = false, array $locales = [], ?RenderContext $context = null): array
    {
        $diagnostics = $this->diagnostics($document, $publishing, $locales, $context);
        $informational = ['unknown_property', 'unconfigured_locale'];
        if (! $publishing) {
            $informational = [...$informational, 'unknown_component', 'unsupported_component_version', 'unsupported_extension', 'unavailable_design_theme', 'incompatible_design_value', 'incompatible_design_component', 'incompatible_design_breakpoint', 'incompatible_design_combination'];
        }
        $errors = array_values(array_filter($diagnostics, fn (Diagnostic $diagnostic): bool => ! in_array($diagnostic->code, $informational, true)));
        if ($errors !== []) {
            throw new InvalidDocument($errors);
        }

        return $diagnostics;
    }

    private function value(mixed $value, PropertyDefinition $property, string $path, array &$diagnostics): void
    {
        if ($value === null && ($property->nullable || $property->translated)) {
            return;
        }
        $valid = match ($property->kind) {
            'text', 'html' => is_string($value),
            'url', 'media' => is_string($value) && $this->urls->valid($value, $property->kind === 'media') && ($property->kind !== 'media' || $this->resolver->acceptsMedia($value, $property)),
            'enum' => in_array($value, $property->options, true),
            'token' => is_string($value) && in_array($value, $property->options, true) && is_string(config('site-builder.tokens.'.$value)),
            'boolean' => is_bool($value),
            'number' => is_int($value) || is_float($value),
            'page' => is_int($value) && $value > 0,
            'revision' => $this->revision($value),
            'object' => $value instanceof stdClass,
            'list' => is_array($value) && array_is_list($value),
        };
        if (! $valid) {
            $diagnostics[] = new Diagnostic('invalid_property', $path, 'Invalid '.$property->kind.' property.');

            return;
        }
        if ($property->rules !== [] && $this->validation->make(['value' => $value], ['value' => $property->rules])->fails()) {
            $diagnostics[] = new Diagnostic('invalid_property', $path, 'The component validation rules failed.');
        }
        if ($property->kind === 'list') {
            foreach ($value as $index => $child) {
                $this->value($child, $property->items, $path.'/'.$index, $diagnostics);
            }
        } elseif ($property->kind === 'object') {
            foreach ($value as $key => $child) {
                if (isset($property->properties[$key])) {
                    $this->value($child, $property->properties[$key], $path.'/'.$key, $diagnostics);
                }
            }
        }
    }

    private function revision(mixed $value): bool
    {
        try {
            PublishedPartReference::from($value);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private function required(mixed $value, PropertyDefinition $property, string $path, array &$diagnostics): void
    {
        if ($property->requiredForPublish && ($value === null || $value === '' || $value === [])) {
            $diagnostics[] = new Diagnostic('required_for_publish', $path, 'A publication value is required.');
        }
        if ($property->kind === 'object' && $value instanceof stdClass) {
            foreach ($property->properties as $key => $child) {
                $this->required($value->{$key} ?? null, $child, $path.'/'.$key, $diagnostics);
            }
        } elseif ($property->kind === 'list' && is_array($value)) {
            foreach ($value as $key => $item) {
                $this->required($item, $property->items, $path.'/'.$key, $diagnostics);
            }
        }
    }
}
