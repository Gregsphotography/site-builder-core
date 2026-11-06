<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use Closure;
use InvalidArgumentException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Rendering\LocaleCatalog;

final class StartingPointRegistry
{
    private array $entries = [];

    public function __construct(private ComponentRegistry $components, private SourceDocumentValidator $validator, private LocaleCatalog $locales) {}

    public function register(StartingPointDescriptor $descriptor, callable $factory): void
    {
        if (isset($this->entries[$descriptor->id])) {
            throw new InvalidArgumentException('Starting point IDs must be unique.');
        }
        $this->entries[$descriptor->id] = [$descriptor, Closure::fromCallable($factory)];
    }

    public function all(): array
    {
        return array_map(fn (array $entry): StartingPointDescriptor => $entry[0], array_values($this->entries));
    }

    public function find(string $id): ?StartingPointDescriptor
    {
        return $this->entries[$id][0] ?? null;
    }

    public function snapshot(string $id, int|string $version, string $locale, ?string $variant = null): SourceSnapshot
    {
        [$descriptor, $factory] = $this->entries[$id] ?? throw new InvalidArgumentException('Starting point is unavailable.');
        if ((string) $descriptor->version !== (string) $version || ! $this->locales->has($locale) || ($variant !== null && ! in_array($variant, $descriptor->variantIds(), true))) {
            throw new InvalidArgumentException('The selected source version, locale or variant is unavailable.');
        }
        $document = $factory($locale, $variant);
        if (! $document instanceof Document) {
            throw new InvalidArgumentException('Trusted starting point factories return a Document.');
        }
        if ($document->nodes === []) {
            throw new InvalidArgumentException('A starting point must contain at least one section.');
        }
        SectionSourceScope::assertSupported($document);
        $this->validator->validate($document);
        foreach ($this->components->diagnostics($document) as $diagnostic) {
            if (in_array($diagnostic->code, ['unknown_component', 'unsupported_component_version'], true)) {
                throw new InvalidArgumentException('Install the starting point required component definitions first.');
            }
        }

        return SourceSnapshot::fromDocument($descriptor->sourceKind, $id, $version, $document, ['locale' => $locale, 'variant' => $variant]);
    }
}
