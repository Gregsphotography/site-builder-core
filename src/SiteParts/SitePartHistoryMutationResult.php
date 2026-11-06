<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use SiteBuilder\Core\DraftHistory\HistorySession;

final readonly class SitePartHistoryMutationResult
{
    public function __construct(public SitePartMutationResult $mutation, public HistorySession $history) {}
}
