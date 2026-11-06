<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use SiteBuilder\Core\Components\PlacementPolicy;

interface HasPlacementPolicy
{
    public function placement(): PlacementPolicy;
}
