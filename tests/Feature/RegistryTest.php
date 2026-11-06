<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use InvalidArgumentException;
use SiteBuilder\Core\Components\ChildPolicy;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\PropertyDefinition;
use SiteBuilder\Core\Components\ReferenceScanner;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Content\PropertyValues;
use SiteBuilder\Core\Contracts\ComponentDefinition;
use SiteBuilder\Core\Tests\TestCase;

class RegistryTest extends TestCase
{
    public function test_components_register_without_a_panel_and_duplicates_fail_explicitly(): void
    {
        $registry = app(ComponentRegistry::class);
        $definition = $this->definition();
        $registry->register($definition);
        $this->assertSame($definition, $registry->resolve('test.references'));
        $this->expectException(InvalidArgumentException::class);
        $registry->register($definition);
    }

    public function test_unknown_nodes_and_versions_produce_diagnostics_without_mutation(): void
    {
        $registry = app(ComponentRegistry::class)->register($this->definition());
        $codec = app(DocumentCodec::class);
        $document = new Document([new Node('parent', 'test.references', version: 2, children: [new Node('child', 'unavailable.type')])]);
        $before = $codec->encode($document);
        $codes = array_map(fn ($diagnostic) => $diagnostic->code, $registry->diagnostics($document));
        $this->assertSame(['unsupported_component_version', 'unknown_component'], $codes);
        $this->assertSame($before, $codec->encode($document));
    }

    public function test_reference_scanning_follows_declared_translated_lists_and_nested_children_only(): void
    {
        app(ComponentRegistry::class)->register($this->definition());
        $values = new JsonObject((object) ['en' => (object) ['links' => [(object) ['page_id' => 7, 'source_id' => 999]]]]);
        $child = new Node('child', 'test.references', props: new JsonObject(['image' => '/media/picture.webp']));
        $document = new Document([new Node('root', 'test.references', translations: $values, children: [$child])]);
        $references = app(ReferenceScanner::class)->scan($document);
        $this->assertCount(2, $references);
        $this->assertSame(7, $references[0]->value);
        $this->assertSame('en', $references[0]->locale);
        $this->assertSame('/translations/en/links/0/page_id', $references[0]->path);
        $this->assertSame('child', $references[1]->nodeId);
        $this->assertSame('media', $references[1]->kind);
    }

    public function test_invalid_component_contracts_are_rejected_before_container_resolution(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ComponentRegistry::class)->register(\stdClass::class);
    }

    private function definition(): ComponentDefinition
    {
        return new class implements ComponentDefinition
        {
            public function type(): string
            {
                return 'test.references';
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
                return ['image' => PropertyDefinition::media(), 'links' => PropertyDefinition::list(PropertyDefinition::object(['page_id' => PropertyDefinition::page()]), translated: true)];
            }

            public function allowedChildren(): ChildPolicy
            {
                return ChildPolicy::none();
            }

            public function view(): string
            {
                return 'test.references';
            }
        };
    }
}
