<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use SiteBuilder\Core\Content\Node;

interface ComponentMigrator
{
    public function type(): string;

    public function fromVersion(): int;

    public function toVersion(): int;

    public function migrate(Node $node): Node;
}
