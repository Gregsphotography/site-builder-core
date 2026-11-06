<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;

final class SitePartHistory
{
    public function __construct(private SitePartAccess $access) {}

    public function revisions(Authenticatable $actor, string $partId, int $limit = 20, ?string $beforeId = null): Collection
    {
        $part = SitePart::query()->findOrFail($partId);
        $this->access->authorize($actor, 'view', $part);
        $query = $part->revisions();
        if ($beforeId !== null) {
            $before = $part->revisions()->findOrFail($beforeId);
            $query->where('sequence', '<', $before->sequence);
        }

        return $query->limit(max(1, min(100, $limit)))->get();
    }
}
