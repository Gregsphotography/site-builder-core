<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use SiteBuilder\Core\Content\Document;

final readonly class CompositionResolution
{
    public function __construct(public Document $document, public array $body, public ?array $header, public ?array $footer, public array $references, public array $documents, public array $usage) {}
}
