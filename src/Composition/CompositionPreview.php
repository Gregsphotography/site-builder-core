<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use SiteBuilder\Core\Content\Document;

final readonly class CompositionPreview
{
    /** @param list<string> $rootIds Physical roots projected from this full, validated candidate. */
    public function __construct(public Document $document, public array $rootIds) {}
}
