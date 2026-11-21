<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Persistence;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\SiteParts\ContentDependencies;

final class PageTrash
{
    public function __construct(private PageAccess $access) {}

    public function restore(Authenticatable $actor, int $id, int $expectedVersion): Page
    {
        return DB::transaction(function () use ($actor, $id, $expectedVersion): Page {
            $page = Page::trash()->lockForUpdate()->findOrFail($id);
            $this->access->authorize($actor, 'view', $page);
            $this->access->authorize($actor, 'restore', $page);
            if ($page->write_version !== $expectedVersion) {
                throw new WriteConflict('stale_version', $page->write_version);
            }
            $page->forceFill(['status' => 'draft', 'published_at' => null, 'write_version' => $expectedVersion + 1])->save();
            app(ContentDependencies::class)->syncPage($page);

            return $page;
        }, 3);
    }
}
