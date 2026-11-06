<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Fixtures;

use SiteBuilder\Core\Components\ChildPolicy;
use SiteBuilder\Core\Components\PropertyDefinition as Property;
use SiteBuilder\Core\Content\PropertyValues;
use SiteBuilder\Core\Contracts\ComponentDefinition;
use SiteBuilder\Core\Contracts\ContributesStructuredData;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\ResolvedNode;

class RenderBlock implements ComponentDefinition, ContributesStructuredData
{
    public function type(): string
    {
        return 'test.block';
    }

    public function version(): int
    {
        return 1;
    }

    public function defaults(): PropertyValues
    {
        return new PropertyValues(translations: ['en' => ['heading' => 'Never inject defaults']]);
    }

    public function properties(): array
    {
        return [
            'heading' => Property::text(translated: true, rules: ['max:255'], requiredForPublish: true),
            'body' => Property::html(translated: true),
            'link' => Property::url(translated: true),
            'image' => Property::media(translated: true),
            'tone' => Property::enum(['brand', 'neutral']),
            'space' => new Property('token', options: ['space.section']),
            'items' => Property::list(Property::object(['label' => Property::text(), 'page' => Property::page(), 'media' => Property::media(), 'url' => Property::url()]), translated: true),
        ];
    }

    public function allowedChildren(): ChildPolicy
    {
        return ChildPolicy::types(['test.block']);
    }

    public function view(): string
    {
        return 'test-blocks::block';
    }

    public function structuredData(ResolvedNode $node, RenderContext $context): array
    {
        return [['@type' => 'CreativeWork', 'name' => $node->props->get('heading')]];
    }
}
