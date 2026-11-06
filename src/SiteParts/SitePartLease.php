<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Carbon\CarbonImmutable;

final readonly class SitePartLease
{
    public function __construct(public string $partId, public string $token, public CarbonImmutable $expiresAt, public int $writeVersion) {}
}
