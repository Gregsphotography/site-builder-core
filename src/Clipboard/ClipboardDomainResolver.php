<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Clipboard;

use Illuminate\Contracts\Auth\Authenticatable;
use SiteBuilder\Core\Models\Page;

/** A host with content domains/tenants must bind its trusted resolver. */
interface ClipboardDomainResolver
{
    public function domain(Authenticatable $actor, Page $page): string;
}
