<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use stdClass;

interface DocumentMigrator
{
    public function fromVersion(): int;

    public function toVersion(): int;

    public function migrate(stdClass $document): stdClass;
}
