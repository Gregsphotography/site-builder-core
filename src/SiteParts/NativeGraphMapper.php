<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\Reference;
use SiteBuilder\Core\Components\ReferenceScanner;
use SiteBuilder\Core\Composition\CompositionData;
use SiteBuilder\Core\Composition\PublishedPartReference;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Design\DesignData;
use SiteBuilder\Core\Exchange\Exchange;
use SiteBuilder\Core\Exchange\ExchangeSchema;
use SiteBuilder\Core\Exchange\Snapshot;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\ActorIdentity;
use SiteBuilder\Core\Persistence\PageAccess;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;
use SiteBuilder\Core\Rendering\SafeUrl;
use stdClass;

/** Produces a new reviewed snapshot only. There is no database apply or URL/media fetch. */
final class NativeGraphMapper
{
    public function __construct(private ExchangeSchema $schema, private CanonicalJson $json, private DocumentCodec $codec, private ReferenceScanner $scanner, private ComponentRegistry $components) {}

    public function describe(Snapshot $source): array
    {
        $identities = [];
        foreach ($source->tables() as $kind => $rows) {
            $identities[$kind] = array_map(fn ($row): string => (string) $row['id'], $rows);
        }
        $occurrences = [];
        foreach ($this->documents($source) as $key => $document) {
            foreach ($this->references($document) as $reference) {
                $occurrences[] = ['document' => $key, 'nodeId' => $reference->nodeId, 'path' => $reference->path, 'kind' => $reference->kind, 'original' => $reference->value, 'value' => $reference->value];
            }
        }

        return compact('identities', 'occurrences');
    }

    public function map(Authenticatable $actor, Snapshot $source, SitePartGraphMap $map, Connection $target, NativeGraphImportPolicy $policy): Snapshot
    {
        ActorIdentity::from($actor);
        if ($source->version() !== 3) {
            $this->fail('Graph remapping requires an explicit v3 source; legacy envelopes are never silently upgraded.');
        }
        $tables = $source->tables();
        $known = ['pages', 'slugs', 'redirects', 'revisions', 'settings', 'menus', 'saved_sections', 'saved_section_versions', 'site_parts', 'site_part_revisions'];
        if (array_diff(array_keys($tables), $known) !== [] || array_diff(array_keys($tables), array_keys($this->schema->tables())) !== [] || array_diff(array_keys($tables), array_keys($map->identities())) !== [] || array_diff(array_keys($map->identities()), array_keys($tables)) !== []) {
            $this->fail('Map every registered content dataset explicitly; host business datasets need a separate reviewed converter.');
        }
        $changing = false;
        foreach ($tables as $kind => $rows) {
            $definition = $this->schema->table($kind);
            if (count($rows) !== count($map->identities()[$kind])) {
                $this->fail('Typed identity maps must cover every source row exactly once.');
            }
            foreach ($rows as $row) {
                $id = $map->target($kind, $row['id']);
                $valid = $definition['identity'] === 'uuid' ? Str::isUuid($id) && strtolower($id) === $id : preg_match('/^[1-9][0-9]{0,18}$/D', $id) && (strlen($id) < 19 || strcmp($id, (string) PHP_INT_MAX) <= 0);
                if (! $valid) {
                    $this->fail('A mapped target identity is invalid; existing rows are never reassigned or overwritten.');
                }
                $changing = $changing || $id !== (string) $row['id'];
            }
        }
        foreach ($map->occurrences() as $choice) {
            $changing = $changing || ! $this->json->equals($choice['original'], $choice['value']);
        }
        if (($tables['pages'] ?? []) !== []) {
            app(PageAccess::class)->authorize($actor, 'create', Page::class);
        }
        foreach (array_unique(array_column($tables['site_parts'] ?? [], 'kind')) as $kind) {
            if (! in_array($kind, ['section', 'header', 'footer', 'design', 'overlay'], true)) {
                $this->fail('Only supported explicit site-part kinds can be mapped.');
            }
            app(SitePartAccess::class)->authorize($actor, 'create', [SitePart::class, $kind]);
        }
        $policy->authorize($actor, $source, $map, $target);
        $revisionRows = array_column($tables['site_part_revisions'] ?? [], null, 'id');
        $mappedRevisions = [];
        $visiting = [];
        $used = [];
        $mapDocument = null;
        $mapRevision = function (string $id) use (&$mapRevision, &$mapDocument, &$mappedRevisions, &$visiting, $revisionRows, $map): array {
            if (isset($mappedRevisions[$id])) {
                return $mappedRevisions[$id];
            }
            if (isset($visiting[$id]) || ! isset($revisionRows[$id])) {
                $this->fail('The explicit source revision graph is cyclic or missing a required immutable revision.');
            }
            $visiting[$id] = true;
            $row = $revisionRows[$id];
            $payload = SitePartPayload::decode($row['payload']);
            if (! hash_equals($row['digest'], $this->json->digest($payload))) {
                $this->fail('A source immutable revision failed its canonical digest check.');
            }
            $mapped = $payload->document === null ? $payload : SitePartPayload::document($payload->kind, $mapDocument('site_part_revisions:'.$id.':payload', $payload->document));
            $digest = $this->json->digest($mapped);
            $newId = $map->target('site_part_revisions', $id);
            $resource = $map->target('site_parts', $row['resource_id']);
            if (($digest !== $row['digest'] || $resource !== $row['resource_id']) && $newId === $id) {
                $this->fail('Changed immutable payload/ownership requires an explicitly new revision UUID.');
            }
            unset($visiting[$id]);

            return $mappedRevisions[$id] = array_replace($row, ['id' => $newId, 'resource_id' => $resource, 'payload' => $this->encode($mapped), 'digest' => $digest]);
        };
        $mapPin = function (PublishedPartReference $reference) use (&$mapRevision, $revisionRows): stdClass {
            $original = $revisionRows[$reference->revisionId] ?? null;
            if ($original === null || $original['resource_id'] !== $reference->resourceId || ! hash_equals($original['digest'], $reference->digest)) {
                $this->fail('A source pin does not match the included immutable revision owner/digest.');
            }
            $mapped = $mapRevision($reference->revisionId);

            return (new PublishedPartReference($mapped['resource_id'], $mapped['id'], $mapped['digest']))->jsonSerialize();
        };
        $mapDocument = function (string $key, Document $document) use (&$mapDocument, $mapPin, $map, $policy, $actor, $target, $changing, &$used): Document {
            $opaque = array_any($this->components->diagnostics($document), fn ($diagnostic): bool => in_array($diagnostic->code, ['unknown_component', 'unsupported_component_version'], true));
            $features = app(RenderFeatureRegistry::class);
            $opaque = $opaque || $features->readOnly($document);
            foreach (DesignData::nodes($document) as $node) {
                $opaque = $opaque || $features->readOnly($document, $node);
            }
            if ($changing && $opaque && ! $policy->allowOpaque($actor, $key, $document)) {
                $this->fail('Nonidentity mapping with unknown component/reference semantics requires an explicit trusted opaque-data adapter.');
            }
            $raw = json_decode($this->codec->encode($document), flags: JSON_THROW_ON_ERROR);
            foreach ($this->references($document) as $reference) {
                $choice = $map->choice($key, $reference->nodeId, $reference->path);
                if ($choice['kind'] !== $reference->kind || ! $this->json->equals($choice['original'], $reference->value)) {
                    $this->fail('A mapping choice no longer matches its exact declared source occurrence.');
                }
                $used[SitePartGraphMap::key($key, $reference->nodeId, $reference->path)] = true;
                $value = $choice['value'];
                if ($reference->kind === 'page' && $value !== null) {
                    $expected = $map->target('pages', $reference->value);
                    if ((! is_int($value) && ! is_string($value)) || (string) $value !== $expected) {
                        $this->fail('A page occurrence must use its explicit mapped page identity or be explicitly cleared.');
                    }
                    $value = (int) $expected;
                } elseif ($reference->kind === 'revision' && $value !== null) {
                    $pin = $mapPin(PublishedPartReference::from($reference->value));
                    $requested = is_array($value) ? (object) $value : $value;
                    if (! $requested instanceof stdClass || array_diff(array_keys(get_object_vars($requested)), ['resourceId', 'revisionId', 'digest']) !== [] || ($requested->resourceId ?? null) !== $pin->resourceId || ($requested->revisionId ?? null) !== $pin->revisionId || (property_exists($requested, 'digest') && $requested->digest !== $pin->digest)) {
                        $this->fail('A revision occurrence must name its mapped immutable UUIDs and match any supplied target digest.');
                    }
                    $value = $pin;
                } elseif ($reference->kind === 'media' && $value !== null && (! is_string($value) || ! app(SafeUrl::class)->valid($value, true))) {
                    $this->fail('Mapped media must be a safe explicit reference or null; bytes are never imported implicitly.');
                }
                if ($policy->reference($actor, $key, $reference, $value, $target)->status !== 'valid') {
                    $this->fail('The trusted destination policy did not authorize a mapped page/media/revision reference.');
                }
                $owner = $reference->nodeId === '@document' ? $raw : $this->find($raw->nodes, $reference->nodeId);
                $this->replace($owner, $reference->path, $value);
            }

            return $this->codec->decode($this->encode($raw));
        };
        foreach (array_keys($revisionRows) as $id) {
            $mapRevision($id);
        }
        $provenance = [];
        $origins = [];
        $result = [];
        foreach ($tables as $kind => $rows) {
            $result[$kind] = [];
            foreach ($rows as $row) {
                $originalId = (string) $row['id'];
                $originalDigest = $source->provenance($kind, $originalId) ?? $this->schema->digest($kind, $row);
                $row['id'] = $map->target($kind, $originalId);
                if (in_array($kind, ['slugs', 'redirects', 'revisions'], true)) {
                    $row['page_id'] = $map->target('pages', $row['page_id']);
                }
                foreach ($kind === 'pages' ? ['draft_content', 'published_content'] : ($kind === 'revisions' ? ['content'] : []) as $column) {
                    if ($row[$column] !== null) {
                        $row[$column] = $this->codec->encode($mapDocument($kind.':'.$originalId.':'.$column, $this->codec->decode($row[$column])));
                    }
                }
                if ($kind === 'site_part_revisions') {
                    $row = $mappedRevisions[$originalId];
                } elseif ($kind === 'site_parts') {
                    $payload = SitePartPayload::decode($row['draft_payload']);
                    if ($payload->document !== null) {
                        $row['draft_payload'] = $this->encode(SitePartPayload::document($payload->kind, $mapDocument($kind.':'.$originalId.':draft_payload', $payload->document)));
                    }
                    if ($row['current_revision_id'] !== null) {
                        $row['current_revision_id'] = $map->target('site_part_revisions', $row['current_revision_id']);
                    }
                } elseif ($kind === 'saved_section_versions') {
                    $document = $mapDocument($kind.':'.$originalId.':document', $this->codec->decode($row['document']));
                    $digest = $this->json->digest($document);
                    $section = $map->target('saved_sections', $row['section_id']);
                    if (($digest !== $row['digest'] || $section !== $row['section_id']) && $row['id'] === $originalId) {
                        $this->fail('Changed immutable saved content/ownership requires a new version UUID.');
                    }
                    $row = array_replace($row, ['section_id' => $section, 'document' => $this->codec->encode($document), 'digest' => $digest]);
                }
                $result[$kind][] = $row;
                $provenance[$kind][(string) $row['id']] = $originalDigest;
                $origins[$kind][(string) $row['id']] = data_get($source->source(), ['graph_mapping', 'origin_rows', $kind, $originalId], ['namespace' => $source->source()['namespace'], 'id' => $originalId, 'digest' => $originalDigest]);
            }
        }
        if (array_diff(array_keys($map->occurrences()), array_keys($used)) !== []) {
            $this->fail('A graph choice is not an exact declared source occurrence; opaque values are never guessed.');
        }
        $metadata = $source->source();
        $sourceFingerprint = $source->fingerprint($this->schema, $this->json);
        $mapDigest = $this->json->digest($map->toArray());
        $sourceNamespace = $metadata['namespace'];
        $metadata['namespace'] = 'graph-map:'.substr($this->json->digest([$sourceNamespace, $sourceFingerprint, $mapDigest]), 0, 48);
        $metadata['graph_mapping'] = ['format' => 'laravel-site-builder/graph-map', 'version' => 1, 'source_namespace' => $sourceNamespace, 'source_fingerprint' => $sourceFingerprint, 'map_digest' => $mapDigest, 'map' => $map->toArray(), 'origin_rows' => $origins];
        $mapped = new Snapshot($metadata, $result, $provenance, $source->extensions());
        if (strlen($mapped->encode()) > 64 * 1024 * 1024) {
            $this->fail('The mapped graph and explicit provenance exceed the native envelope limit.');
        }
        $fingerprint = $mapped->fingerprint($this->schema, $this->json);
        foreach ($mapped->tables() as $kind => $rows) {
            foreach ($rows as $row) {
                $actual = $target->table($this->schema->table($kind)['table'])->where('id', $row['id'])->first();
                if ($actual === null) {
                    continue;
                }
                $prior = $target->table('site_builder_import_map')->where(['source' => $metadata['namespace'], 'kind' => $kind, 'source_id' => (string) $row['id']])->first();
                $run = $prior === null ? null : $target->table('site_builder_import_runs')->where('id', $prior->run_id)->first();
                $expected = $this->schema->digest($kind, $row);
                if ($prior === null || $run === null || $run->status !== 'completed' || $run->source !== $metadata['namespace'] || ! hash_equals($run->source_fingerprint, $fingerprint) || (string) $prior->target_id !== (string) $row['id'] || ! hash_equals($prior->source_digest, $mapped->provenance($kind, (string) $row['id'])) || ! hash_equals($prior->target_digest, $expected) || ! hash_equals($expected, $this->schema->digest($kind, (array) $actual))) {
                    $this->fail('A mapped target identity is occupied without exact prior conversion provenance, or its source/target changed. No overwrite is permitted.');
                }
            }
        }
        $report = app(Exchange::class)->plan($mapped, $target);
        if (! $report['cutover_eligible']) {
            $this->fail('The mapped graph is not eligible for import: '.implode(', ', array_unique(array_column($report['diagnostics'], 'code'))));
        }

        return $mapped;
    }

    private function documents(Snapshot $snapshot): array
    {
        $documents = [];
        foreach ($snapshot->tables() as $kind => $rows) {
            foreach ($rows as $row) {
                foreach ($kind === 'pages' ? ['draft_content', 'published_content'] : ($kind === 'revisions' ? ['content'] : ($kind === 'saved_section_versions' ? ['document'] : [])) as $column) {
                    if ($row[$column] !== null) {
                        $documents[$kind.':'.$row['id'].':'.$column] = $this->codec->decode($row[$column]);
                    }
                }
                if (in_array($kind, ['site_parts', 'site_part_revisions'], true)) {
                    $column = $kind === 'site_parts' ? 'draft_payload' : 'payload';
                    $payload = SitePartPayload::decode($row[$column]);
                    if ($payload->document !== null) {
                        $documents[$kind.':'.$row['id'].':'.$column] = $payload->document;
                    }
                }
            }
        }

        return $documents;
    }

    private function references(Document $document): array
    {
        $references = $this->scanner->scan($document);
        foreach (CompositionData::references($document) as $path => $entry) {
            if ($entry['nodeId'] === null) {
                $references[] = new Reference('@document', null, $path, 'revision', $entry['reference']->jsonSerialize());
            }
        }

        return $references;
    }

    private function find(array $nodes, string $id): stdClass
    {
        foreach ($nodes as $node) {
            if ($node->id === $id) {
                return $node;
            }
            try {
                return $this->find($node->children, $id);
            } catch (InvalidArgumentException) {
            }
        }
        throw new InvalidArgumentException('The mapped node occurrence is unavailable.');
    }

    private function replace(stdClass $owner, string $path, mixed $value): void
    {
        $parts = array_map(fn (string $part): string => str_replace(['~1', '~0'], ['/', '~'], $part), explode('/', ltrim($path, '/')));
        $cursor = &$owner;
        foreach ($parts as $part) {
            if ($cursor instanceof stdClass && property_exists($cursor, $part)) {
                $cursor = &$cursor->{$part};
            } elseif (is_array($cursor) && ctype_digit($part) && array_key_exists((int) $part, $cursor)) {
                $cursor = &$cursor[(int) $part];
            } else {
                $this->fail('The exact declared reference path is unavailable.');
            }
        }
        $cursor = $value;
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['graphMap' => $message]);
    }
}
