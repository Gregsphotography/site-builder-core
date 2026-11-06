<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Clipboard;

use Illuminate\Contracts\Auth\Authenticatable;
use SiteBuilder\Core\Models\Page;

final class MainDomainResolver implements ClipboardDomainResolver
{
    public function domain(Authenticatable $actor, Page $page): string
    {
        return 'main';
    }
}
