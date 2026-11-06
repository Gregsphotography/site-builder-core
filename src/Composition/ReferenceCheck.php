<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use InvalidArgumentException;

final readonly class ReferenceCheck
{
    public function __construct(public string $status, public string $message = '')
    {
        if (! in_array($status, ['valid', 'unverified', 'missing', 'unauthorized'], true)) {
            throw new InvalidArgumentException('Unknown reference inspection status.');
        }
    }

    public static function valid(string $message = ''): self
    {
        return new self('valid', $message);
    }

    public static function unverified(string $message): self
    {
        return new self('unverified', $message);
    }

    public static function missing(string $message): self
    {
        return new self('missing', $message);
    }

    public static function unauthorized(string $message): self
    {
        return new self('unauthorized', $message);
    }
}
