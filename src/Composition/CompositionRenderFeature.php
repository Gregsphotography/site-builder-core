<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Contracts\RenderFeature;
use SiteBuilder\Core\Rendering\RenderContext;
use stdClass;

final class CompositionRenderFeature implements RenderFeature
{
    public function __construct(private CompositionResolver $resolver) {}

    public function key(): string
    {
        return 'composition';
    }

    public function version(): int
    {
        return 1;
    }

    public function readable(stdClass $payload, bool $node): bool
    {
        return ! $node && CompositionData::shape($payload);
    }

    public function projectSections(stdClass $payload): bool
    {
        return CompositionData::shape($payload);
    }

    public function diagnostics(stdClass $payload, Document $document, ?Node $node, RenderContext $context): array
    {
        return $this->resolver->diagnostics($document, $context);
    }
}
