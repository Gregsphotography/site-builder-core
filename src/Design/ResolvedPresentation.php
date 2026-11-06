<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Design;

final readonly class ResolvedPresentation
{
    public function __construct(public ?ThemeIdentity $theme, public array $states = [], public array $attributes = [], public array $diagnostics = []) {}
}
