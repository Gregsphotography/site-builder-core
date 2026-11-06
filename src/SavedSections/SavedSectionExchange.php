<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SavedSections;

use SiteBuilder\Core\Components\ReferenceScanner;
use SiteBuilder\Core\Composition\SourceDocumentValidator;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Exchange\ExchangeSchema;
use SiteBuilder\Core\Exchange\Snapshot;
use SiteBuilder\Core\Persistence\ActorIdentity;
use Throwable;

/** Explicit opt-in: page-only exchange never silently claims to back up the library. */
final class SavedSectionExchange
{
    public static function register(ExchangeSchema $schema, bool $preserveActorOwnership = false): void
    {
        $schema->register('saved_sections', 'site_builder_saved_sections', ['id', 'name', 'description', 'category', 'tags', 'visibility', 'owner_id', 'owner_type', 'write_version', 'current_version', 'created_by', 'created_by_type', 'updated_by', 'updated_by_type', 'archived_at', 'created_at', 'updated_at'], ['tags'], ['write_version', 'current_version'], [], 'uuid');
        $schema->register('saved_section_versions', 'site_builder_saved_section_versions', ['id', 'section_id', 'version', 'document', 'digest', 'metadata', 'source_kind', 'source_id', 'source_version', 'source_digest', 'source_root_ids', 'created_by', 'created_by_type', 'created_at'], ['document', 'metadata', 'source_root_ids'], ['version'], [['section_id', 'version']], 'uuid');
        $schema->inspectUsing(function (Snapshot $snapshot) use ($schema, $preserveActorOwnership): array {
            $diagnostics = [];
            $issue = function (string $code, string $path, string $message) use (&$diagnostics): void {
                $diagnostics[] = ['severity' => 'error', 'code' => $code, 'path' => $path, 'message' => $message];
            };
            $tables = $snapshot->tables();
            $hasOwners = ($tables['saved_sections'] ?? []) !== [];
            if ($hasOwners && ! $preserveActorOwnership) {
                $issue('saved_section_owner_mapping_required', '/tables/saved_sections', 'Saved owner IDs/models are not proof of the destination identity domain. Configure an explicit trusted owner mapping, or assert preserve_actor_ownership only for a verified same-host identity-domain restore. Snapshot metadata cannot authorize ownership.');
            }
            $sections = [];
            foreach ($tables['saved_sections'] ?? [] as $row) {
                if (array_diff($schema->table('saved_sections')['columns'], array_keys($row)) !== []) {
                    $issue('incomplete_saved_section', '/tables/saved_sections/'.($row['id'] ?? '?'), 'Every saved resource column must be explicit.');

                    continue;
                }
                $sections[$row['id']] = $row;
            }
            $versions = [];
            $pageIds = array_map('strval', array_column($tables['pages'] ?? [], 'id'));
            foreach ($tables['saved_section_versions'] ?? [] as $row) {
                $path = '/tables/saved_section_versions/'.$row['id'];
                if (array_diff($schema->table('saved_section_versions')['columns'], array_keys($row)) !== []) {
                    $issue('incomplete_saved_section_version', $path, 'Every immutable version column must be explicit.');

                    continue;
                }
                if (! isset($sections[$row['section_id']])) {
                    $issue('orphan_saved_section', $path, 'Saved versions must belong to an included resource.');
                }
                $versions[$row['section_id']][(string) $row['version']] = $row;
                if (! self::version($row['version'], false)) {
                    $issue('invalid_saved_section_version', $path, 'Saved versions require positive lossless PHP integer counters.');
                }
                if (! preg_match('/^[a-f0-9]{64}$/D', (string) $row['digest']) || ! preg_match('/^[a-f0-9]{64}$/D', (string) $row['source_digest'])) {
                    $issue('invalid_saved_section_digest', $path, 'Immutable snapshot and source digests must be explicit SHA-256 values.');
                }
                try {
                    $document = app(DocumentCodec::class)->decode($row['document']);
                    app(SourceDocumentValidator::class)->validate($document);
                    if (! hash_equals($row['digest'], app(CanonicalJson::class)->digest($document))) {
                        $issue('saved_section_digest_changed', $path, 'Saved-section immutable content does not match its digest.');
                    }
                    foreach (app(ReferenceScanner::class)->scan($document) as $reference) {
                        if ($reference->kind === 'page' && ! in_array((string) $reference->value, $pageIds, true)) {
                            $issue('dangling_saved_page_reference', $path.$reference->path, 'A saved-section page reference is outside the explicit preserved identity map.');
                        }
                    }
                    $metadata = json_decode($row['metadata'], flags: JSON_THROW_ON_ERROR);
                    $roots = json_decode($row['source_root_ids'], flags: JSON_THROW_ON_ERROR);
                    if (! $metadata instanceof \stdClass || ! is_array($roots) || ! array_is_list($roots) || array_any($roots, fn ($id): bool => ! is_string($id))) {
                        $issue('invalid_saved_section_metadata', $path, 'Version descriptors/provenance require explicit object/list shapes.');
                    }
                } catch (Throwable) {
                    $issue('invalid_saved_section_document', $path, 'Saved content must retain a structurally valid bounded v1 document.');
                }
                if (! ActorIdentity::validId($row['created_by']) || ! self::actorType($row['created_by_type'])) {
                    $issue('invalid_saved_section_actor', $path, 'Saved authors require exact actor IDs/model types.');
                }
            }
            foreach ($sections as $id => $row) {
                $path = '/tables/saved_sections/'.$id;
                if (! is_string($row['name']) || $row['name'] === '' || mb_strlen($row['name']) > 255 || ! in_array($row['visibility'], ['private', 'shared'], true) || ! self::version($row['write_version'], true) || ! self::version($row['current_version'], false) || ! isset($versions[$id][(string) $row['current_version']])) {
                    $issue('invalid_saved_section_current', $path, 'Resource descriptors/current-version pointers must be explicit and complete.');
                }
                foreach (['owner' => ['owner_id', 'owner_type'], 'created' => ['created_by', 'created_by_type'], 'updated' => ['updated_by', 'updated_by_type']] as [$actorId, $actorType]) {
                    if (! ActorIdentity::validId($row[$actorId]) || ! self::actorType($row[$actorType])) {
                        $issue('invalid_saved_section_actor', $path, 'Saved owners/authors require exact actor IDs/model types.');
                    }
                }
            }

            return ['diagnostics' => $diagnostics, 'metrics' => ['saved_sections' => count($sections), 'saved_section_versions' => count($tables['saved_section_versions'] ?? []), 'saved_section_ownership' => ['decision' => ! $hasOwners ? 'not_required' : ($preserveActorOwnership ? 'preserve_actor_ownership' : 'unacknowledged'), 'same_identity_domain_asserted_by_destination' => $preserveActorOwnership]]];
        });
    }

    private static function actorType(mixed $type): bool
    {
        return is_string($type) && strlen($type) <= 255 && preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $type) === 1;
    }

    private static function version(mixed $value, bool $zero): bool
    {
        $value = (string) $value;

        return preg_match($zero ? '/^(0|[1-9][0-9]{0,18})$/D' : '/^[1-9][0-9]{0,18}$/D', $value) === 1 && (strlen($value) < 19 || strcmp($value, (string) PHP_INT_MAX) <= 0);
    }
}
