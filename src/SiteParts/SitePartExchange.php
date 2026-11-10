<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use SiteBuilder\Core\Components\ReferenceScanner;
use SiteBuilder\Core\Composition\CompositionResolver;
use SiteBuilder\Core\Composition\PublishedPartReference;
use SiteBuilder\Core\Composition\SourceDocumentValidator;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Exchange\ExchangeSchema;
use SiteBuilder\Core\Exchange\Snapshot;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\ActorIdentity;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\SavedSections\SavedSectionVersion;
use Throwable;

/** Explicit v3 graph exchange. Historical author facts never confer destination permissions. */
final class SitePartExchange
{
    public static function register(ExchangeSchema $schema): void
    {
        $schema->register('site_parts', 'site_builder_site_parts', ['id', 'kind', 'name', 'description', 'draft_payload', 'write_version', 'current_revision_id', 'archived_at', 'created_by', 'created_by_type', 'updated_by', 'updated_by_type', 'created_at', 'updated_at'], ['draft_payload'], ['write_version'], [], 'uuid');
        $schema->register('site_part_revisions', 'site_builder_site_part_revisions', ['id', 'resource_id', 'kind', 'sequence', 'payload', 'digest', 'published_from_version', 'created_by', 'created_by_type', 'label', 'published_at', 'withdrawn_at', 'withdrawal_reason', 'withdrawn_by', 'withdrawn_by_type'], ['payload'], ['sequence', 'published_from_version'], [['resource_id', 'sequence']], 'uuid');

        $schema->aroundPlanningUsing(function (Snapshot $snapshot, Connection $target, bool $lock, Closure $next): array {
            $tables = $snapshot->tables();
            if ($lock && $target->getSchemaBuilder()->hasTable('site_builder_site_parts')) {
                $ids = array_column($tables['site_parts'] ?? [], 'id');
                sort($ids, SORT_STRING);
                $target->table('site_builder_site_parts')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
            }
            $parts = array_column($tables['site_parts'] ?? [], null, 'id');
            $revisions = array_column($tables['site_part_revisions'] ?? [], null, 'id');
            $reader = function (PublishedPartReference $reference) use ($parts, $revisions): array {
                if (! isset($parts[$reference->resourceId], $revisions[$reference->revisionId])) {
                    throw ValidationException::withMessages(['composition' => 'The snapshot is missing a resource or immutable revision from its explicit dependency closure.']);
                }

                return [(new SitePart)->newFromBuilder($parts[$reference->resourceId]), (new SitePartRevision)->newFromBuilder($revisions[$reference->revisionId])];
            };
            $report = app(CompositionResolver::class)->withRevisionReader($reader, $next);
            if ($target->getSchemaBuilder()->hasTable('site_builder_site_part_locks') && $target->table('site_builder_site_part_locks')->whereIn('resource_id', array_keys($parts))->where('expires_at', '>', now())->exists()) {
                $report['diagnostics'][] = ['severity' => 'error', 'code' => 'site_part_editing_not_frozen', 'path' => '/tables/site_parts', 'message' => 'Close active target site-part editor sessions before importing.'];
                $report['cutover_eligible'] = false;
            }

            return $report;
        });

        $schema->prepareInsertUsing(fn (string $kind, array $row): array => $kind === 'site_parts' ? array_replace($row, ['current_revision_id' => null]) : $row);
        $schema->afterRowsUsing(function (Snapshot $snapshot, Connection $target, array $report): void {
            foreach ($snapshot->tables()['site_parts'] ?? [] as $row) {
                if (($report['actions']['site_parts'][$row['id']] ?? null) === 'create') {
                    $target->table('site_builder_site_parts')->where('id', $row['id'])->update(['current_revision_id' => $row['current_revision_id']]);
                }
            }
            self::onTarget($target, function () use ($snapshot): void {
                $dependencies = app(ContentDependencies::class);
                foreach ($snapshot->tables()['pages'] ?? [] as $row) {
                    $dependencies->syncPage(Page::query()->findOrFail($row['id']));
                }
                foreach ($snapshot->tables()['site_parts'] ?? [] as $row) {
                    $dependencies->syncPart(SitePart::query()->findOrFail($row['id']));
                }
                foreach ($snapshot->tables()['saved_section_versions'] ?? [] as $row) {
                    $dependencies->syncSavedVersion(SavedSectionVersion::query()->findOrFail($row['id']));
                }
            });
        });
        $schema->inspectUsing(fn (Snapshot $snapshot): array => self::inspect($schema, $snapshot));
    }

    private static function inspect(ExchangeSchema $schema, Snapshot $snapshot): array
    {
        $diagnostics = [];
        $issue = function (string $code, string $path, string $message) use (&$diagnostics): void {
            $diagnostics[] = ['severity' => 'error', 'code' => $code, 'path' => $path, 'message' => $message];
        };
        $tables = $snapshot->tables();
        $parts = array_column($tables['site_parts'] ?? [], null, 'id');
        $revisions = array_column($tables['site_part_revisions'] ?? [], null, 'id');
        $pageIds = array_map('strval', array_column($tables['pages'] ?? [], 'id'));
        $documents = [];
        $add = function (string $path, Document $document, ?string $layout = null, ?string $owner = null, bool $detached = false) use (&$documents): void {
            $documents[] = compact('path', 'document', 'layout', 'owner', 'detached');
        };
        foreach ($parts as $id => $row) {
            $path = '/tables/site_parts/'.$id;
            if (array_diff($schema->table('site_parts')['columns'], array_keys($row)) !== []) {
                $issue('incomplete_site_part', $path, 'Every resource column must be explicit.');

                continue;
            }
            if (! in_array($row['kind'], ['section', 'header', 'footer', 'design', 'overlay'], true) || ! is_string($row['name']) || trim($row['name']) === '' || mb_strlen($row['name']) > 255 || ($row['description'] !== null && (! is_string($row['description']) || mb_strlen($row['description']) > 4000)) || ! self::counter($row['write_version'])) {
                $issue('invalid_site_part_descriptor', $path, 'Resource kind, bounded metadata and lossless write version must be explicit.');
            }
            foreach (['created', 'updated'] as $actor) {
                if (! ActorIdentity::validId($row[$actor.'_by']) || ! self::actorType($row[$actor.'_by_type'])) {
                    $issue('invalid_site_part_actor', $path, 'Historical authors require exact actor IDs/model types; no permission is imported.');
                }
            }
            if (! self::timestamp($row['archived_at'])) {
                $issue('invalid_site_part_timestamp', $path, 'Resource archive time requires explicit SQL timestamp semantics.');
            }
            if ($row['current_revision_id'] !== null) {
                $current = $revisions[$row['current_revision_id']] ?? null;
                if ($current === null || ($current['resource_id'] ?? null) !== $id || ($current['kind'] ?? null) !== $row['kind']) {
                    $issue('invalid_site_part_current_revision', $path, 'The current pointer must name an included immutable revision of this exact resource and kind.');
                }
            }
            try {
                $payload = SitePartPayload::decode($row['draft_payload']);
                if ($payload->kind !== $row['kind']) {
                    $issue('site_part_kind_mismatch', $path, 'Resource and payload kinds disagree.');
                }
                $add($path.'/draft_payload', $payload->validationDocument(), config('site-builder.site_parts.preview_layout'), $id);
            } catch (Throwable) {
                $issue('invalid_site_part_payload', $path, 'The raw resource payload is retained; a supported bounded site-part payload is required.');
            }
        }
        foreach ($revisions as $id => $row) {
            $path = '/tables/site_part_revisions/'.$id;
            if (array_diff($schema->table('site_part_revisions')['columns'], array_keys($row)) !== []) {
                $issue('incomplete_site_part_revision', $path, 'Every immutable revision column must be explicit.');

                continue;
            }
            $part = $parts[$row['resource_id']] ?? null;
            if ($part === null || ($part['kind'] ?? null) !== $row['kind'] || ! self::counter($row['sequence'], false) || ! self::counter($row['published_from_version'])) {
                $issue('invalid_site_part_revision_owner', $path, 'Immutable revisions require an included resource of the same kind and valid counters.');
            }
            if (! ActorIdentity::validId($row['created_by']) || ! self::actorType($row['created_by_type'])) {
                $issue('invalid_site_part_actor', $path, 'Immutable authors require exact historical IDs/model types.');
            }
            if (! self::timestamp($row['published_at'], false) || ! self::timestamp($row['withdrawn_at']) || ($row['label'] !== null && (! is_string($row['label']) || mb_strlen($row['label']) > 255))) {
                $issue('invalid_site_part_timestamp', $path, 'Revision publication/withdrawal times and labels must retain supported explicit values.');
            }
            if ($row['withdrawn_at'] !== null && (! is_string($row['withdrawal_reason']) || trim($row['withdrawal_reason']) === '' || mb_strlen($row['withdrawal_reason']) > 2000 || ! ActorIdentity::validId($row['withdrawn_by']) || ! self::actorType($row['withdrawn_by_type']))) {
                $issue('invalid_site_part_withdrawal', $path, 'A withdrawn revision must retain its exact bounded reason and author facts.');
            }
            try {
                $payload = SitePartPayload::decode($row['payload']);
                if ($payload->kind !== $row['kind'] || ! is_string($row['digest']) || ! preg_match('/^[a-f0-9]{64}$/D', $row['digest']) || ! hash_equals($row['digest'], app(CanonicalJson::class)->digest($payload))) {
                    $issue('site_part_revision_digest_changed', $path, 'Immutable payload kind/digest does not match the captured revision.');
                }
                $add($path.'/payload', $payload->validationDocument(), config('site-builder.site_parts.preview_layout'), $row['resource_id']);
            } catch (Throwable) {
                $issue('invalid_site_part_payload', $path, 'The immutable payload must be a supported bounded site-part value.');
            }
        }
        foreach ($tables['pages'] ?? [] as $row) {
            foreach (['draft_content', 'published_content'] as $column) {
                if (($row[$column] ?? null) !== null) {
                    try {
                        $add('/tables/pages/'.$row['id'].'/'.$column, app(DocumentCodec::class)->decode($row[$column]), $row['layout'] ?? null);
                    } catch (Throwable) {
                        $issue('invalid_composition_owner', '/tables/pages/'.$row['id'].'/'.$column, 'A composition owner requires a valid bounded document.');
                    }
                }
            }
        }
        foreach (['revisions' => 'content', 'saved_section_versions' => 'document'] as $kind => $column) {
            foreach ($tables[$kind] ?? [] as $row) {
                if (($row[$column] ?? null) !== null) {
                    try {
                        $add('/tables/'.$kind.'/'.$row['id'].'/'.$column, app(DocumentCodec::class)->decode($row[$column]), detached: $kind === 'saved_section_versions');
                    } catch (Throwable) {
                        $issue('invalid_composition_owner', '/tables/'.$kind.'/'.$row['id'].'/'.$column, 'Retained content requires a valid bounded document.');
                    }
                }
            }
        }
        $occurrences = 0;
        foreach ($documents as $entry) {
            try {
                foreach (app(ReferenceScanner::class)->scan($entry['document']) as $reference) {
                    if ($reference->kind === 'page' && ! in_array((string) $reference->value, $pageIds, true)) {
                        $issue('dangling_site_part_page_reference', $entry['path'].$reference->path, 'A declared page reference is outside the explicit included identity map.');
                    }
                }
                $context = new RenderContext(array_key_first(config('site-builder.locales')), 'public', layout: $entry['layout']);
                if ($entry['detached']) {
                    // Saved sources can retain read-only unavailable definitions;
                    // their concrete destination is validated again on insertion.
                    app(SourceDocumentValidator::class)->validate($entry['document'], $context);
                    $contentDiagnostics = [];
                } else {
                    $contentDiagnostics = app(DocumentValidator::class)->diagnostics($entry['document'], true, array_keys(config('site-builder.locales')), $context);
                }
                foreach ($contentDiagnostics as $diagnostic) {
                    if (! in_array($diagnostic->code, ['unknown_property', 'unconfigured_locale'], true)) {
                        $issue($diagnostic->code, $entry['path'].$diagnostic->path, $diagnostic->message);
                    }
                }
                $resolution = app(CompositionResolver::class)->resolve($entry['document'], $context, ownerResourceId: $entry['owner']);
                $occurrences += $resolution->usage['mounts'];
            } catch (Throwable $exception) {
                $issue('invalid_site_part_closure', $entry['path'], 'The complete pinned graph is unavailable, cyclic, incompatible or beyond expanded limits. '.$exception->getMessage());
            }
        }

        return ['diagnostics' => $diagnostics, 'metrics' => ['site_parts' => count($parts), 'site_part_revisions' => count($revisions), 'site_part_mounts_checked' => $occurrences, 'site_part_author_facts' => 'historical_only_no_destination_permission_grants']];
    }

    private static function onTarget(Connection $target, Closure $work): void
    {
        $name = $target->getName();
        if (! is_string($name) || $name === '' || DB::connection($name) !== $target) {
            throw new InvalidArgumentException('Graph import requires the exact registered destination connection.');
        }
        DB::usingConnection($name, $work);
    }

    private static function counter(mixed $value, bool $zero = true): bool
    {
        if (! is_int($value) && ! is_string($value)) {
            return false;
        }
        $value = (string) $value;

        return preg_match($zero ? '/^(0|[1-9][0-9]{0,18})$/D' : '/^[1-9][0-9]{0,18}$/D', $value) === 1 && (strlen($value) < 19 || strcmp($value, (string) PHP_INT_MAX) <= 0);
    }

    private static function actorType(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 255 && preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $value) === 1;
    }

    private static function timestamp(mixed $value, bool $nullable = true): bool
    {
        return ($nullable && $value === null) || (is_string($value) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D', $value) === 1);
    }
}
