<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\Node;

final readonly class CompositionRenderNode
{
    public function __construct(public Node $node, public Document $document, public array $children, public string $address, public ?string $selectableId, public ?string $binding, public bool $mounted = false, public bool $suppressed = false, public ?Document $presentationOwner = null, public array $ancestors = []) {}
}
