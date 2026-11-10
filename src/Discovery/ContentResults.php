<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Discovery;

final readonly class ContentResults
{
    /** @param list<ContentItem> $items */
    public function __construct(public array $items = [], public int $total = 0, public int $page = 1, public int $perPage = 12, public array $types = [], public array $tags = [], public bool $available = true) {}

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
