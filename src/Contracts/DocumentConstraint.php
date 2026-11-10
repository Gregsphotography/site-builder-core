<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use SiteBuilder\Core\Content\Diagnostic;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Rendering\RenderContext;

interface DocumentConstraint
{
    /** @return list<Diagnostic> */
    public function diagnostics(Document $document, RenderContext $context, bool $publishing): array;
}
