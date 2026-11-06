<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Clipboard;

/** This value is content/provenance, never an authorization capability. */
final readonly class ClipboardEnvelope
{
    public const string FORMAT = 'laravel-site-builder/clipboard';

    public const int VERSION = 1;

    public function __construct(private string $text, public string $snapshotId, public string $digest, public int $sourcePageId, public int $sourceVersion, public string $expiresAt, public int $nodeCount, public array $rootIds) {}

    public function toJson(): string
    {
        return $this->text;
    }

    public function summary(): array
    {
        return ['snapshotId' => $this->snapshotId, 'digest' => $this->digest, 'sourcePageId' => $this->sourcePageId, 'sourceVersion' => $this->sourceVersion, 'expiresAt' => $this->expiresAt, 'nodeCount' => $this->nodeCount, 'rootIds' => $this->rootIds, 'formatVersion' => self::VERSION];
    }
}
