<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use Illuminate\Validation\ValidationException;

final readonly class ReferenceReport
{
    public function __construct(public array $occurrences, public array $diagnostics, public bool $blocking) {}

    public function toArray(): array
    {
        return ['occurrences' => $this->occurrences, 'diagnostics' => $this->diagnostics, 'blocking' => $this->blocking];
    }

    public function assertApplicable(): void
    {
        if ($this->blocking) {
            throw ValidationException::withMessages(['references' => 'Resolve or explicitly acknowledge the reported source references before applying.']);
        }
    }
}
