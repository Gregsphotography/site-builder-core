<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Persistence;

use SiteBuilder\Core\Models\Page;

final readonly class MutationResult
{
    public function __construct(public Page $page, public string $draftDigest, public array $diagnostics = []) {}
}
