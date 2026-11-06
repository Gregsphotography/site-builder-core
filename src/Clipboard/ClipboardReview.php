<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Clipboard;

use SiteBuilder\Core\Composition\ReferenceReport;

final readonly class ClipboardReview
{
    public function __construct(public ClipboardEnvelope $envelope, public ReferenceReport $references, public string $textDigest, public int $destinationVersion) {}

    public function summary(): array
    {
        return $this->envelope->summary() + ['textDigest' => $this->textDigest, 'destinationVersion' => $this->destinationVersion];
    }
}
