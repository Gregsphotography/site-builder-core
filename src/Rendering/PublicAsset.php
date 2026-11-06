<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use InvalidArgumentException;

/** Trusted published, same-origin assets; content cannot construct registrations. */
final readonly class PublicAsset
{
    public function __construct(public string $kind, public string $url)
    {
        if (! in_array($kind, ['module', 'stylesheet'], true) || ! preg_match('~^/(?!/)[a-zA-Z0-9/_.-]+(?:\?v=[a-f0-9]{16,64})?$~D', $url) || str_contains($url, '..')) {
            throw new InvalidArgumentException('Use a same-origin published asset path and optional content digest.');
        }
    }
}
