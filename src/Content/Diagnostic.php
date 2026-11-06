<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Content;

final readonly class Diagnostic
{
    public function __construct(public string $code, public string $path, public string $message) {}
}
