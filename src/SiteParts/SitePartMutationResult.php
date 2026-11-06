<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

final readonly class SitePartMutationResult
{
    public function __construct(public SitePart $part, public string $draftDigest, public array $diagnostics = []) {}
}
