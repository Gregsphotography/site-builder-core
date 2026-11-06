<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;

final readonly class ResolvedNode
{
    public function __construct(public Node $node, public JsonObject $props) {}
}
