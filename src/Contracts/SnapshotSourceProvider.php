<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use SiteBuilder\Core\Composition\SourceSnapshot;
use SiteBuilder\Core\Snapshots\SnapshotChoice;
use SiteBuilder\Core\Snapshots\SnapshotDestination;

interface SnapshotSourceProvider
{
    /** @return list<SnapshotChoice> */
    public function choices(Authenticatable $actor, SnapshotDestination $destination, string $locale): array;

    public function snapshot(Authenticatable $actor, SnapshotDestination $destination, string $sourceId, string $locale, bool $lock): SourceSnapshot;
}
