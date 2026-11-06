<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

final class SetupFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $exitStatus = 64)
    {
        parent::__construct($message);
    }
}
