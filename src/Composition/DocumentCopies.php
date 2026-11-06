<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use Illuminate\Support\Str;
use InvalidArgumentException;
use SiteBuilder\Core\Components\ReferenceScanner;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use stdClass;

final class DocumentCopies
{
    public function __construct(private DocumentCodec $codec, private ReferenceScanner $references) {}

    public function copy(Document $document): Document
    {
        return $this->instantiate(SourceSnapshot::fromDocument('trusted-copy', 'local', '1', $document), new CopyContext(0, ''), ReferenceMap::fromArray([]))->document;
    }

    public function instantiate(SourceSnapshot $source, CopyContext $destination, ReferenceMap $map): CopyResult
    {
        $this->codec->encode($source->document);
        $declared = [];
        foreach ($this->references->scan($source->document) as $reference) {
            $declared[ReferenceMap::key($reference->nodeId, $reference->path)] = $reference;
        }
        foreach ($map->all() as $key => $entry) {
            $reference = $declared[$key] ?? null;
            if ($reference === null || $reference->kind !== $entry['kind'] || ! app(CanonicalJson::class)->equals($reference->value, $entry['original'])) {
                throw new InvalidArgumentException('Only exact declared reference occurrences may be mapped.');
            }
        }
        $ids = [];
        $clone = function (Node $node) use (&$clone, &$ids, $map): Node {
            $id = (string) Str::uuid();
            $ids[$node->id] = $id;
            $values = (object) ['props' => $node->props->toObject(), 'translations' => $node->translations->toObject()];
            foreach ($map->all() as $entry) {
                if ($entry['nodeId'] === $node->id) {
                    $this->replace($values, $entry['path'], $entry['value']);
                }
            }

            return new Node($id, $node->type, $node->version, new JsonObject($values->props), new JsonObject($values->translations), $node->visibility, array_map($clone, $node->children), $node->extensions);
        };
        $document = new Document(array_map($clone, $source->document->nodes), $source->document->extensions);
        $this->codec->encode($document);

        return new CopyResult($document, $ids);
    }

    private function replace(stdClass $root, string $path, mixed $value): void
    {
        $parts = array_map(fn (string $part): string => str_replace(['~1', '~0'], ['/', '~'], $part), explode('/', ltrim($path, '/')));
        $cursor = &$root;
        foreach ($parts as $part) {
            if ($cursor instanceof stdClass && property_exists($cursor, $part)) {
                $cursor = &$cursor->{$part};
            } elseif (is_array($cursor) && ctype_digit($part) && array_key_exists((int) $part, $cursor)) {
                $cursor = &$cursor[(int) $part];
            } else {
                throw new InvalidArgumentException('Reference mapping path is unavailable.');
            }
        }
        $cursor = $value;
    }
}
