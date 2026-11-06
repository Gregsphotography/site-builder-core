<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Design;

final readonly class DesignCompatibilityReport
{
    public function __construct(public bool $compatible, public array $diagnostics = [], public array $changedDefaults = []) {}

    public function toArray(): array
    {
        return ['compatible' => $this->compatible, 'diagnostics' => $this->diagnostics, 'changedDefaults' => $this->changedDefaults];
    }
}
