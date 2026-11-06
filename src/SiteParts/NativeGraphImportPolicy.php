<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use SiteBuilder\Core\Components\Reference;
use SiteBuilder\Core\Composition\ReferenceCheck;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Exchange\Snapshot;

/** Trusted destination-specific import/domain/media policy; there is no default allow adapter. */
interface NativeGraphImportPolicy
{
    public function authorize(Authenticatable $actor, Snapshot $source, SitePartGraphMap $map, Connection $target): void;

    public function reference(Authenticatable $actor, string $document, Reference $source, mixed $mapped, Connection $target): ReferenceCheck;

    public function allowOpaque(Authenticatable $actor, string $document, Document $source): bool;
}
