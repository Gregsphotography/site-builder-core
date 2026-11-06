<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Clipboard;

use Illuminate\Contracts\Auth\Authenticatable;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Models\Page;

/** Assembly copies only document content. Hosts must refuse protected component data. */
final class ContentOnlyExportPolicy implements ClipboardExportPolicy
{
    public function assertExportable(Authenticatable $actor, Page $source, Document $document): void {}

    public function assertTransferable(Authenticatable $actor, Page $source, Page $destination, Document $document): void {}
}
