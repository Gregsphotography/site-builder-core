<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use InvalidArgumentException;

final readonly class SourceSelection
{
    public string $version;

    public function __construct(public string $kind, public string $id, int|string $version, public string $digest, public string $locale, public ?string $variant = null)
    {
        $this->version = (string) $version;
        if (! in_array($kind, ['component', 'code-template', 'saved-section'], true) || strlen($id) > 191 || $id === '' || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,190}$/D', $this->version) || ! preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            throw new InvalidArgumentException('Source selection must pin a trusted source version and digest.');
        }
    }
}
