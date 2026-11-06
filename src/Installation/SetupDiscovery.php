<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

/** Internal trusted facts. Plans expose only their digest and bounded diagnostics. */
final readonly class SetupDiscovery
{
    public function __construct(public array $facts) {}
}
