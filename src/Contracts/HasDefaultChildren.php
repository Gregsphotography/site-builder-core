<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use SiteBuilder\Core\Content\Node;

interface HasDefaultChildren
{
    /** @return list<Node> Trusted starter content; insertion allocates fresh IDs. */
    public function defaultChildren(): array;
}
