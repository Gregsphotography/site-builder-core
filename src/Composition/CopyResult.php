<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use SiteBuilder\Core\Content\Document;

final readonly class CopyResult
{
    public function __construct(public Document $document, public array $nodeIdMap) {}
}
