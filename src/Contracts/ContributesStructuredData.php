<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\ResolvedNode;

interface ContributesStructuredData
{
    /** @return list<array<string, mixed>> */
    public function structuredData(ResolvedNode $node, RenderContext $context): array;
}
