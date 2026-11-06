<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Exceptions;

use InvalidArgumentException;
use SiteBuilder\Core\Content\Diagnostic;

final class InvalidDocument extends InvalidArgumentException
{
    /** @param list<Diagnostic> $diagnostics */
    public function __construct(public readonly array $diagnostics)
    {
        parent::__construct($diagnostics[0]->message ?? 'Invalid document.');
    }
}
