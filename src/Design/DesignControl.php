<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Design;

use InvalidArgumentException;

final readonly class DesignControl
{
    public function __construct(public string $key, public string $label, public array $options, public bool $responsive = true)
    {
        if (! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $key) || $label === '' || $options === []) {
            throw new InvalidArgumentException('Design controls require explicit bounded options.');
        }
        foreach ($options as $id => $label) {
            if (! is_string($id) || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $id) || ! is_string($label)) {
                throw new InvalidArgumentException('Design option IDs/labels must be trusted strings.');
            }
        }
    }
}
