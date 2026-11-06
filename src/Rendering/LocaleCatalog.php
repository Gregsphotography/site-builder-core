<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use InvalidArgumentException;

final class LocaleCatalog
{
    public function all(): array
    {
        $locales = config('site-builder.locales', []);
        if ($locales === [] || array_any(array_keys($locales), fn ($key): bool => ! is_string($key) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,11}$/D', $key))) {
            throw new InvalidArgumentException('Configure an ordered, nonempty locale map.');
        }

        return array_keys($locales);
    }

    public function default(): string
    {
        return $this->all()[0];
    }

    public function has(string $locale): bool
    {
        return in_array($locale, $this->all(), true);
    }
}
