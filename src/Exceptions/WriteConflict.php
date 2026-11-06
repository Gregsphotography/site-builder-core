<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class WriteConflict extends HttpException
{
    public function __construct(public readonly string $reason, public readonly ?int $currentVersion = null)
    {
        parent::__construct(409, $reason);
    }
}
