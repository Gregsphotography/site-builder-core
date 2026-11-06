<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Persistence;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use SiteBuilder\Core\Models\Page;

final class PageHistory
{
    public function __construct(private PageAccess $access) {}

    public function revisions(Authenticatable $actor, int $pageId, int $limit = 20, ?int $beforeId = null): Collection
    {
        $page = Page::query()->findOrFail($pageId);
        $this->access->authorize($actor, 'view', $page);

        return $page->revisions()->where('scope', 'content')->when($beforeId !== null, fn ($query) => $query->where('id', '<', $beforeId))->limit(max(1, min(100, $limit)))->get();
    }
}
