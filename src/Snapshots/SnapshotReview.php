<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Snapshots;

use SiteBuilder\Core\Composition\ReferenceReport;

final readonly class SnapshotReview
{
    public function __construct(public SnapshotSelection $selection, public array $changes, public ReferenceReport $references, public ?string $candidateDigest) {}

    public function toArray(): array
    {
        return ['selection' => $this->selection->jsonSerialize(), 'changes' => $this->changes, 'references' => $this->references->toArray(), 'candidateDigest' => $this->candidateDigest,
            'warning' => 'Copy replaces the listed draft fields in this locale, including the complete menu list. Shared dimensions affect every locale. Publication and existing page pins are separate.'];
    }
}
