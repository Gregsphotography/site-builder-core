<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Snapshots;

use SiteBuilder\Core\Contracts\SnapshotSourceProvider;

final class SnapshotSourceRegistry
{
    /** @var array<string, SnapshotSourceProvider> */
    private array $providers = [];

    public function register(string $providerId, SnapshotSourceProvider $provider): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $providerId) || isset($this->providers[$providerId])) {
            throw new \InvalidArgumentException('Snapshot providers require unique bounded trusted identifiers.');
        }
        $this->providers[$providerId] = $provider;
    }

    public function all(): array
    {
        return $this->providers;
    }

    public function get(string $id): SnapshotSourceProvider
    {
        return $this->providers[$id] ?? throw new \InvalidArgumentException('This snapshot source is unavailable.');
    }
}
