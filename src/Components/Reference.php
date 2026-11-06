<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Components;

final readonly class Reference
{
    public function __construct(public string $nodeId, public ?string $locale, public string $path, public string $kind, public mixed $value) {}
}
