<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Design;

use InvalidArgumentException;

final readonly class ThemeIdentity
{
    public function __construct(public string $id, public int $version, public string $preset)
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D', $id) || $version < 1 || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D', $preset)) {
            throw new InvalidArgumentException('Invalid trusted theme identity.');
        }
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'version' => $this->version, 'preset' => $this->preset];
    }
}
