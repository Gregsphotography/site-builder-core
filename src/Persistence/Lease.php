<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Persistence;

use Carbon\CarbonImmutable;

final readonly class Lease
{
    public function __construct(public int $pageId, public string $token, public CarbonImmutable $expiresAt, public int $writeVersion) {}
}
