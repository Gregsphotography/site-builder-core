<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use SiteBuilder\Core\Composition\CopyOwner;

/** Legacy page-only inspectors must opt in before seeing a site-part destination. */
interface OwnerAwareCopyReferenceInspector extends CopyReferenceInspector
{
    public function supportsOwner(CopyOwner $owner): bool;
}
