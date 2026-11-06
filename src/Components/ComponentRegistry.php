<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Components;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use SiteBuilder\Core\Content\Diagnostic;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Contracts\ComponentDefinition;
use SiteBuilder\Core\Contracts\HasPlacementPolicy;

final class ComponentRegistry
{
    /** @var array<string, ComponentDefinition> */
    private array $definitions = [];

    public function __construct(private Container $container) {}

    /** @param class-string<ComponentDefinition>|ComponentDefinition $definition */
    public function register(string|ComponentDefinition $definition): self
    {
        if (is_string($definition)) {
            if (! is_subclass_of($definition, ComponentDefinition::class)) {
                throw new InvalidArgumentException('A component must implement ComponentDefinition.');
            }
            $definition = $this->container->make($definition);
        }
        $type = $definition->type();
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D', $type) || $definition->version() < 1 || $definition->view() === '') {
            throw new InvalidArgumentException('The component key, version and trusted view must be valid.');
        }
        if (isset($this->definitions[$type])) {
            throw new InvalidArgumentException("Component [{$type}] is already registered.");
        }
        foreach ($definition->properties() as $key => $property) {
            if (! is_string($key) || $key === '' || ! $property instanceof PropertyDefinition) {
                throw new InvalidArgumentException('Component properties must be named property definitions.');
            }
        }
        $this->definitions[$type] = $definition;

        return $this;
    }

    public function resolve(string $type): ?ComponentDefinition
    {
        return $this->definitions[$type] ?? null;
    }

    /** @return array<string, ComponentDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }

    /** Reports renderability without deleting or upgrading stored nodes. @return list<Diagnostic> */
    public function diagnostics(Document $document): array
    {
        $diagnostics = [];
        $walk = function (array $nodes, string $path, bool $root = true, int $structuralDepth = 0) use (&$walk, &$diagnostics): void {
            foreach ($nodes as $index => $node) {
                $location = $path.'/'.$index;
                $definition = $this->resolve($node->type);
                if ($definition === null) {
                    $diagnostics[] = new Diagnostic('unknown_component', $location, "Component [{$node->type}] is not registered.");
                } elseif ($node->version !== $definition->version()) {
                    $diagnostics[] = new Diagnostic('unsupported_component_version', $location, 'This component requires an explicit data migration.');
                }
                $depth = $structuralDepth;
                if ($definition !== null && $definition->version() === $node->version) {
                    $placement = $definition instanceof HasPlacementPolicy ? $definition->placement() : PlacementPolicy::anywhere();
                    if (($root && ! $placement->rootAllowed) || (! $root && ! $placement->childAllowed)) {
                        $diagnostics[] = new Diagnostic('disallowed_placement', $location, 'This component cannot be placed in this root/child slot.');
                    }
                    if (! $definition->allowedChildren()->allowsCount(count($node->children))) {
                        $diagnostics[] = new Diagnostic('invalid_child_count', $location.'/children', 'The component child count is outside its registered bounds.');
                    }
                    $depth += (int) $placement->structural;
                    if ($depth > max(1, min(8, (int) config('site-builder.composition.max_structural_depth', 4)))) {
                        $diagnostics[] = new Diagnostic('structural_depth_limit', $location, 'The structural container nesting limit is exceeded.');
                    }
                }
                foreach ($node->children as $child) {
                    if ($definition !== null && $definition->version() === $node->version && ! $definition->allowedChildren()->allows($child->type)) {
                        $diagnostics[] = new Diagnostic('disallowed_child', $location.'/children', 'The parent does not allow this child component.');
                    }
                }
                $walk($node->children, $location.'/children', false, $depth);
            }
        };
        $walk($document->nodes, '/nodes');

        return $diagnostics;
    }
}
