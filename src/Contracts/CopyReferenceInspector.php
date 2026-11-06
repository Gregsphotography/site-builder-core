<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use SiteBuilder\Core\Components\Reference;
use SiteBuilder\Core\Composition\CopyContext;
use SiteBuilder\Core\Composition\ReferenceCheck;

interface CopyReferenceInspector
{
    /** Return null when this trusted adapter does not own this reference. */
    public function inspect(Authenticatable $actor, Reference $reference, CopyContext $destination): ?ReferenceCheck;
}
