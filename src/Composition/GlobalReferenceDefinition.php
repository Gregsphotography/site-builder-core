<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use SiteBuilder\Core\Components\ChildPolicy;
use SiteBuilder\Core\Components\PropertyDefinition;
use SiteBuilder\Core\Content\PropertyValues;
use SiteBuilder\Core\Contracts\ComponentDefinition;

final class GlobalReferenceDefinition implements ComponentDefinition
{
    public function type(): string
    {
        return 'core.global-reference';
    }

    public function version(): int
    {
        return 1;
    }

    public function defaults(): PropertyValues
    {
        return new PropertyValues;
    }

    public function properties(): array
    {
        return ['revision' => PropertyDefinition::revision()];
    }

    public function allowedChildren(): ChildPolicy
    {
        return ChildPolicy::none();
    }

    public function view(): string
    {
        return 'site-builder::global-reference';
    }
}
