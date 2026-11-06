<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use SiteBuilder\Core\Models\Page;

interface PageDeletionGuard
{
    /** Throw to veto deletion when host references need review. Runs inside the page transaction. */
    public function check(Page $page, Authenticatable $actor): void;
}
