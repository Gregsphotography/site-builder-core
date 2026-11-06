<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Unit;

use InvalidArgumentException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Editing\DocumentOperations;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;
use SiteBuilder\Core\Tests\TestCase;

class DocumentOperationsTest extends TestCase
{
    public function test_operations_preserve_other_languages_unknown_data_and_structure_and_duplicate_every_id(): void
    {
        app(ComponentRegistry::class)->register(RenderBlock::class);
        $child = new Node('child', 'test.block', props: new JsonObject(['future' => (object) ['empty' => (object) [], 'list' => [], 'null' => null]]));
        $node = new Node('parent', 'test.block', translations: new JsonObject(['en' => (object) ['heading' => 'English'], 'de' => (object) ['heading' => 'Deutsch'], 'xx' => (object) ['unused' => []]]), children: [$child]);
        $unknown = new Node('unknown', 'future.block', props: new JsonObject(['unread' => (object) []]));
        $document = new Document([$node, $unknown], new JsonObject(['provenance' => 'retained']));
        $original = app(CanonicalJson::class)->digest($document);
        $operations = app(DocumentOperations::class);
        $copy = $operations->duplicate($document, 'parent');
        $this->assertNotSame('parent', $copy->nodes[1]->id);
        $this->assertNotSame('child', $copy->nodes[1]->children[0]->id);
        $this->assertTrue(app(CanonicalJson::class)->equals($node->translations, $copy->nodes[1]->translations));
        $this->assertTrue(app(CanonicalJson::class)->equals($child->props, $copy->nodes[1]->children[0]->props));
        $hidden = $operations->visibility($copy, 'parent', false);
        $this->assertSame('hidden', $hidden->nodes[0]->visibility);
        $moved = $operations->reorder($hidden, ['unknown', $copy->nodes[1]->id, 'parent']);
        $this->assertSame($unknown, $moved->nodes[0]);
        $this->assertSame('parent', $operations->topLevelId($moved, 'child'));
        $removed = $operations->remove($moved, 'unknown');
        $this->assertCount(2, $removed->nodes);
        $added = $operations->add($removed, 'test.block', 1);
        $this->assertSame('Never inject defaults', $added->nodes[1]->translations->toObject()->en->heading);
        $this->assertFalse(property_exists($added->nodes[1]->translations->toObject(), 'de'));
        $this->assertSame('retained', $added->extensions->get('provenance'));
        $this->assertSame($original, app(CanonicalJson::class)->digest($document));
    }

    public function test_reordering_cannot_drop_or_duplicate_nodes_and_unsupported_nodes_cannot_be_duplicated(): void
    {
        $operations = app(DocumentOperations::class);
        $document = new Document([new Node('a', 'future'), new Node('b', 'future')]);
        foreach ([['a'], ['a', 'a'], ['b', 'foreign']] as $ids) {
            try {
                $operations->reorder($document, $ids);
                $this->fail('Invalid reorder accepted.');
            } catch (InvalidArgumentException) {
                $this->assertCount(2, $document->nodes);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $operations->duplicate($document, 'a');
    }
}
