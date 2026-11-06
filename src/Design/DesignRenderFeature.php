<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Design;

use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Contracts\RenderFeature;
use SiteBuilder\Core\Rendering\RenderContext;
use stdClass;

final class DesignRenderFeature implements RenderFeature
{
    public function __construct(private DesignResolver $resolver) {}

    public function key(): string
    {
        return 'design';
    }

    public function version(): int
    {
        return 1;
    }

    public function diagnostics(stdClass $payload, Document $document, ?Node $node, RenderContext $context): array
    {
        return $this->resolver->resolve($document, $node, $context)->diagnostics;
    }

    public function readable(stdClass $payload, bool $node): bool
    {
        return DesignData::shape($payload, $node);
    }

    public function projectSections(stdClass $payload): bool
    {
        return DesignData::shape($payload, false);
    }
}
