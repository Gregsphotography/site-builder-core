<?php

declare(strict_types=1);

namespace SiteBuilder\Core\DraftHistory;

use SiteBuilder\Core\Persistence\MutationResult;

final readonly class HistoryMutationResult
{
    public function __construct(public MutationResult $mutation, public HistorySession $history) {}
}
