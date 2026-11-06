<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use SiteBuilder\Core\Components\ChildPolicy;
use SiteBuilder\Core\Components\PropertyDefinition;
use SiteBuilder\Core\Content\PropertyValues;

interface ComponentDefinition
{
    public function type(): string;

    public function version(): int;

    public function defaults(): PropertyValues;

    /** @return array<string, PropertyDefinition> */
    public function properties(): array;

    public function allowedChildren(): ChildPolicy;

    public function view(): string;
}
