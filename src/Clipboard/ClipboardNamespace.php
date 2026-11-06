<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Clipboard;

use Illuminate\Support\Facades\DB;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Exchange\ConnectionIdentity;

final class ClipboardNamespace
{
    public function current(): string
    {
        // Neither credentials, database coordinates nor APP_KEY leave the server.
        return (new CanonicalJson)->digest([
            'database' => ConnectionIdentity::fingerprint(DB::connection()),
            'application' => rtrim((string) config('app.url'), '/'),
        ]);
    }
}
