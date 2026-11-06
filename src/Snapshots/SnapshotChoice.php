<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Snapshots;

final readonly class SnapshotChoice
{
    public function __construct(public string $id, public string $label, public string $componentType)
    {
        if ($id === '' || strlen($id) > 120 || $label === '' || mb_strlen($label) > 255 || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D', $componentType)) {
            throw new \InvalidArgumentException('Snapshot choices require bounded source identities and labels.');
        }
    }
}
