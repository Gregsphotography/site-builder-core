<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Snapshots;

use SiteBuilder\Core\Composition\CopyOwner;

final readonly class SnapshotDestination implements \JsonSerializable
{
    public CopyOwner $owner;

    public function __construct(public string $kind, public int|string $id, public string $nodeId)
    {
        $this->owner = new CopyOwner($kind, $id);
        if ($nodeId === '' || strlen($nodeId) > 128) {
            throw new \InvalidArgumentException('Select a physical draft node.');
        }
    }

    public function jsonSerialize(): array
    {
        return ['kind' => $this->kind, 'id' => $this->id, 'nodeId' => $this->nodeId];
    }
}
