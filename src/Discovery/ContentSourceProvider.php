<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Discovery;

interface ContentSourceProvider
{
    public function label(): string;

    /** Enumerate only currently public records in the server-derived scope. @return iterable<ContentItem> */
    public function items(ContentContext $context): iterable;

    /** Revalidate current visibility and hydrate in batches. Missing/private records are omitted. @return array<string, ContentItem> */
    public function hydrate(array $ids, ContentContext $context): array;
}
