<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Clipboard;

final readonly class ClipboardLimits
{
    public int $ttlSeconds;

    public int $snapshots;

    public int $bytes;

    public int $envelopeBytes;

    public function __construct()
    {
        $this->ttlSeconds = max(1, min(7200, (int) config('site-builder.clipboard.ttl_seconds', 1800)));
        $this->snapshots = max(1, min(50, (int) config('site-builder.clipboard.snapshots_per_actor', 10)));
        $this->bytes = max(1024, min(32 * 1024 * 1024, (int) config('site-builder.clipboard.bytes_per_actor', 8 * 1024 * 1024)));
        $this->envelopeBytes = max(512, min(2 * 1024 * 1024, (int) config('site-builder.clipboard.max_envelope_bytes', 1024 * 1024)));
    }
}
