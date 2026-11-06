<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use InvalidArgumentException;

final class PublicAssetRegistry
{
    private array $assets = [];

    public function register(string $id, PublicAsset $asset): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $id) || isset($this->assets[$id])) {
            throw new InvalidArgumentException('Public asset IDs must be valid and unique.');
        }
        $this->assets[$id] = $asset;
    }

    public function get(string $id): PublicAsset
    {
        return $this->assets[$id] ?? throw new InvalidArgumentException('Unknown public asset.');
    }
}
