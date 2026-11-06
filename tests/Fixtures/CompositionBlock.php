<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Fixtures;

use SiteBuilder\Core\Components\ChildPolicy;
use SiteBuilder\Core\Components\PropertyDefinition;
use SiteBuilder\Core\Content\PropertyValues;
use SiteBuilder\Core\Contracts\ComponentDefinition;

final class CompositionBlock implements ComponentDefinition
{
    public function __construct(private int $schemaVersion = 1, private bool $children = true) {}

    public function type(): string
    {
        return 'test.composition';
    }

    public function version(): int
    {
        return $this->schemaVersion;
    }

    public function defaults(): PropertyValues
    {
        return new PropertyValues;
    }

    public function properties(): array
    {
        return ['heading' => PropertyDefinition::text(translated: true, nullable: true), 'body' => PropertyDefinition::html(translated: true, nullable: true), 'link' => PropertyDefinition::url(), 'image' => PropertyDefinition::media(translated: true), 'image/path' => PropertyDefinition::media(), 'target' => PropertyDefinition::page(translated: true), 'items' => PropertyDefinition::list(PropertyDefinition::object(['label' => PropertyDefinition::text(nullable: true), 'target' => PropertyDefinition::page(), 'image' => PropertyDefinition::media()]), translated: true)];
    }

    public function allowedChildren(): ChildPolicy
    {
        return $this->children ? ChildPolicy::types(['test.composition', 'future.block']) : ChildPolicy::none();
    }

    public function view(): string
    {
        return 'test-blocks::block';
    }
}
