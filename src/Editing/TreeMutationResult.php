<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Editing;

use SiteBuilder\Core\Persistence\MutationResult;

final readonly class TreeMutationResult
{
    /** @param list<string> $selectedIds */
    public function __construct(public MutationResult $mutation, public array $selectedIds) {}
}
