<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Exceptions\InvalidDocument;

class DocumentCodecTest extends TestCase
{
    public function test_round_trip_preserves_unknown_nodes_all_locales_and_json_container_types(): void
    {
        $json = file_get_contents(__DIR__.'/../Fixtures/document.json');
        $codec = new DocumentCodec;
        $document = $codec->decode($json);
        $this->assertTrue((new CanonicalJson)->equals(json_decode($json), json_decode($codec->encode($document))));
        $this->assertInstanceOf(\stdClass::class, $document->nodes[0]->props->get('object'));
        $this->assertSame([], $document->nodes[0]->props->get('list'));
        $this->assertNull($document->nodes[0]->props->get('nothing'));
        $this->assertSame('9007199254740992', $document->nodes[0]->props->get('external_identifier'));
        $this->assertSame(7, $document->nodes[0]->version);
        $this->assertSame('hidden', $document->nodes[0]->visibility);
    }

    public function test_reading_nested_json_values_cannot_mutate_the_document(): void
    {
        $props = new JsonObject((object) ['nested' => (object) ['name' => 'Original']]);
        $copy = $props->get('nested');
        $copy->name = 'Changed';
        $this->assertSame('Original', $props->get('nested')->name);
        $document = new Document([new Node('stable', 'test.text', props: $props)]);
        $serialized = $document->jsonSerialize();
        $serialized->extensions->changed = true;
        $this->assertNull($document->extensions->get('changed'));
    }

    #[DataProvider('invalidDocuments')]
    public function test_invalid_or_unsupported_documents_return_diagnostics(string $json, string $code): void
    {
        try {
            (new DocumentCodec)->decode($json);
            $this->fail('Invalid input was accepted.');
        } catch (InvalidDocument $exception) {
            $this->assertSame($code, $exception->diagnostics[0]->code);
        }
    }

    public static function invalidDocuments(): array
    {
        $empty = ['format' => Document::FORMAT, 'version' => 1, 'nodes' => [], 'extensions' => (object) []];
        $unknown = $empty;
        $unknown['version'] = 2;
        $extra = $empty;
        $extra['unrecognized'] = true;
        $node = ['id' => 'same', 'type' => 'test.container', 'version' => 1, 'props' => (object) [], 'translations' => (object) [], 'visibility' => 'visible', 'children' => [], 'extensions' => (object) []];
        $duplicate = $empty;
        $parent = $node;
        $parent['children'] = [$node];
        $duplicate['nodes'] = [$parent];

        return [
            'malformed JSON' => ['{', 'invalid_json'],
            'unknown version' => [json_encode($unknown), 'unsupported_document_version'],
            'extra envelope data' => [json_encode($extra), 'invalid_structure'],
            'list instead of object' => [str_replace('"extensions":{}', '"extensions":[]', json_encode($empty)), 'invalid_structure'],
            'duplicate descendant ID' => [json_encode($duplicate), 'duplicate_node_id'],
            'unsafe integer' => [str_replace('"extensions":{}', '"extensions":{"number":9007199254740992}', json_encode($empty)), 'non_interoperable_number'],
            'overflow' => [str_replace('"extensions":{}', '"extensions":{"number":1e400}', json_encode($empty)), 'non_interoperable_number'],
        ];
    }

    public function test_tree_and_size_limits_fail_without_truncating_content(): void
    {
        $json = (new DocumentCodec)->encode(new Document([new Node('root', 'test.container', children: [new Node('child', 'test.text')])]));
        foreach ([new DocumentCodec(maxDepth: 1), new DocumentCodec(maxNodes: 1), new DocumentCodec(maxBytes: 5)] as $codec) {
            try {
                $codec->decode($json);
                $this->fail('Expected a document limit diagnostic.');
            } catch (InvalidDocument $exception) {
                $this->assertContains($exception->diagnostics[0]->code, ['tree_limit', 'size_limit']);
            }
        }
    }

    public function test_canonical_digests_ignore_object_order_but_preserve_list_order_and_scalar_types(): void
    {
        $canonical = new CanonicalJson;
        $this->assertTrue($canonical->equals(json_decode('{"b":2,"a":{"y":true,"x":null}}'), json_decode('{"a":{"x":null,"y":true},"b":2}')));
        $this->assertFalse($canonical->equals([1, 2], [2, 1]));
        $this->assertFalse($canonical->equals('1', 1));
        $this->assertFalse($canonical->equals((object) [], []));
        $this->assertFalse($canonical->equals(null, ''));
        foreach (json_decode(file_get_contents(__DIR__.'/../Fixtures/canonical.json'), true) as $fixture) {
            $this->assertSame($fixture['canonical'], $canonical->encode(json_decode($fixture['input'])));
            $this->assertSame($fixture['sha256'], $canonical->digest(json_decode($fixture['input'])));
        }
    }
}
