<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Unit;

use InvalidArgumentException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\ReferenceScanner;
use SiteBuilder\Core\Composition\CopyContext;
use SiteBuilder\Core\Composition\DocumentCopies;
use SiteBuilder\Core\Composition\ReferenceMap;
use SiteBuilder\Core\Composition\SourceSnapshot;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Tests\Fixtures\CompositionBlock;
use SiteBuilder\Core\Tests\TestCase;

class DocumentCopiesTest extends TestCase
{
    public function test_recursive_copy_changes_only_node_ids_and_preserves_unknown_languages_and_json_shapes(): void
    {
        $opaque = new JsonObject(['empty' => (object) [], 'list' => [], 'null' => null, 'opaqueId' => 'child', 'nested' => (object) ['value' => [1, null, (object) []]]]);
        $source = new Document([new Node('root', 'future.block', 7, $opaque, new JsonObject(['en' => (object) ['list' => []], 'de' => (object) ['empty' => (object) []]]), 'hidden', [new Node('child', 'future.child', props: $opaque, extensions: $opaque)], $opaque)], $opaque);
        $before = app(DocumentCodec::class)->encode($source);
        $copy = app(DocumentCopies::class)->copy($source);
        $second = app(DocumentCopies::class)->copy($source);
        $this->assertNotSame($source->nodes[0]->id, $copy->nodes[0]->id);
        $this->assertNotSame($copy->nodes[0]->id, $second->nodes[0]->id);
        $this->assertNotSame('child', $copy->nodes[0]->children[0]->id);
        $this->assertSame('child', $copy->nodes[0]->props->get('opaqueId'));
        $this->assertTrue(app(CanonicalJson::class)->equals($opaque, $copy->extensions));
        $this->assertTrue(app(CanonicalJson::class)->equals($source->nodes[0]->translations, $copy->nodes[0]->translations));
        $this->assertTrue(app(CanonicalJson::class)->equals($source->nodes[0]->extensions, $copy->nodes[0]->extensions));
        $this->assertSame('hidden', $copy->nodes[0]->visibility);
        $this->assertSame($before, app(DocumentCodec::class)->encode($source));
    }

    public function test_declared_mapping_uses_escaped_occurrence_paths_and_never_rewrites_opaque_matching_values(): void
    {
        app(ComponentRegistry::class)->register(new CompositionBlock);
        $source = SourceSnapshot::fromDocument('saved-section', 'source', 1, new Document([new Node('root', 'test.composition', props: new JsonObject(['image/path' => '/old.jpg', 'opaque' => (object) ['image/path' => '/old.jpg']]))]));
        $reference = app(ReferenceScanner::class)->scan($source->document)[0];
        $this->assertSame('/props/image~1path', $reference->path);
        $map = ReferenceMap::fromArray([['nodeId' => 'root', 'path' => $reference->path, 'kind' => 'media', 'original' => '/old.jpg', 'value' => '/new.jpg']]);
        $result = app(DocumentCopies::class)->instantiate($source, new CopyContext(1, 'en'), $map);
        $this->assertSame('/new.jpg', $result->document->nodes[0]->props->get('image/path'));
        $this->assertSame('/old.jpg', $result->document->nodes[0]->props->get('opaque')->{'image/path'});
        $this->assertSame($result->document->nodes[0]->id, $result->nodeIdMap['root']);
        $this->expectException(InvalidArgumentException::class);
        app(DocumentCopies::class)->instantiate($source, new CopyContext(1, 'en'), ReferenceMap::fromArray([['nodeId' => 'root', 'path' => '/props/opaque/image~1path', 'kind' => 'media', 'original' => '/old.jpg', 'value' => '/new.jpg']]));
    }

    public function test_unavailable_schema_versions_remain_opaque_to_references_and_parent_child_policies(): void
    {
        app(ComponentRegistry::class)->register(new CompositionBlock(schemaVersion: 2, children: false));
        $document = new Document([new Node('old', 'test.composition', version: 1, props: new JsonObject(['target' => 123]), children: [new Node('child', 'future.block')])]);
        $this->assertSame([], app(ReferenceScanner::class)->scan($document));
        $this->assertNotContains('disallowed_child', array_map(fn ($diagnostic) => $diagnostic->code, app(DocumentValidator::class)->validate($document)));
        $copy = app(DocumentCopies::class)->copy($document);
        $this->assertSame(123, $copy->nodes[0]->props->get('target'));
        $this->expectException(InvalidArgumentException::class);
        app(DocumentCopies::class)->instantiate(SourceSnapshot::fromDocument('saved-section', 'old', 1, $document), new CopyContext(1, 'en'), ReferenceMap::fromArray([['nodeId' => 'old', 'path' => '/props/target', 'kind' => 'page', 'original' => 123, 'value' => 456]]));
    }
}
