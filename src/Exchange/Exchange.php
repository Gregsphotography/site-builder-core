<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Exchange;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use SiteBuilder\Core\Components\ReferenceScanner;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Persistence\ActorIdentity;
use SiteBuilder\Core\Rendering\LayoutRegistry;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\SiteParts\NativeGraphImportScope;
use Throwable;

final class Exchange
{
    public function __construct(private ExchangeSchema $schema, private CanonicalJson $json, private DocumentCodec $codec, private DocumentValidator $documents, private LayoutRegistry $layouts, private ReferenceScanner $references) {}

    public function export(Connection $connection, string $namespace): Snapshot
    {
        $read = function () use ($connection, $namespace): Snapshot {
            $tables = [];
            foreach ($this->schema->tables() as $kind => $definition) {
                $tables[$kind] = $connection->table($definition['table'])->orderBy('id')->get()->map(fn (object $row): array => $this->schema->normalize($kind, get_object_vars($row)))->all();
            }
            $source = ['namespace' => $namespace, 'captured_at' => now()->toAtomString(), 'application_name' => config('app.name'), 'application_timezone' => config('app.timezone'), 'locales' => array_keys(config('site-builder.locales')), 'connection_fingerprint' => ConnectionIdentity::fingerprint($connection), 'mapper_version' => 1];
            if ($connection->getDriverName() === 'mysql') {
                $source['database'] = (array) $connection->selectOne('SELECT @@session.time_zone AS session_timezone, @@system_time_zone AS system_timezone');
            }

            return new Snapshot($source, $tables);
        };
        if ($connection->transactionLevel() > 0) {
            return $read();
        }
        if ($connection->getDriverName() === 'mysql') {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $connection->statement('SET TRANSACTION READ ONLY');
        }
        $connection->beginTransaction();
        try {
            return $read();
        } finally {
            $connection->rollBack();
        }
    }

    public function import(Snapshot $snapshot, Connection $target, bool $dryRun = false): array
    {
        $report = $this->plan($snapshot, $target);
        $report['dry_run'] = $dryRun;
        if (! $dryRun && NativeGraphImportScope::required($snapshot) && ! app(NativeGraphImportScope::class)->allows($snapshot, $target)) {
            $report['status'] = 'rejected';
            $report['cutover_eligible'] = false;
            $report['diagnostics'][] = ['severity' => 'error', 'code' => 'graph_import_reapproval_required', 'path' => '/source/graph_mapping', 'message' => 'Apply this mapped graph through NativeGraphImportCommands with the original source, exact map, fresh actor and trusted destination policy. Prepared artifact metadata is not write permission.'];

            return $report;
        }
        if (! $report['cutover_eligible'] || $dryRun) {
            $report['status'] = $report['cutover_eligible'] ? 'planned' : 'rejected';

            return $report;
        }
        if ($report['counts']['create'] === 0) {
            $report['status'] = 'unchanged';

            return $report;
        }
        try {
            return $target->transaction(function () use ($snapshot, $target): array {
                // Re-check under write locks; unique constraints also arbitrate simultaneous first imports.
                $fresh = $this->plan($snapshot, $target, true);
                if (! $fresh['cutover_eligible']) {
                    throw new InvalidArgumentException('The target changed after planning.');
                }
                if (NativeGraphImportScope::required($snapshot)) {
                    app(NativeGraphImportScope::class)->reapprove($snapshot, $target);
                }
                $run = $target->table('site_builder_import_runs')->insertGetId([
                    'source' => $snapshot->source()['namespace'], 'source_fingerprint' => $fresh['source_fingerprint'], 'format_version' => $snapshot->version(),
                    'mapper_version' => 1, 'status' => 'completed', 'summary' => json_encode(['counts' => $fresh['counts'], 'source_context' => $snapshot->source()], JSON_THROW_ON_ERROR),
                    'started_at' => now(), 'completed_at' => now(),
                ]);
                $tables = $snapshot->tables();
                // Registered order determines FK insertion order, never JSON object order.
                foreach ($this->schema->tables() as $kind => $definition) {
                    foreach ($tables[$kind] as $row) {
                        if ($fresh['actions'][$kind][(string) $row['id']] === 'create') {
                            $target->table($definition['table'])->insert($this->schema->prepareInsert($kind, $row, $snapshot, $target));
                        }
                    }
                }
                // Restore staged pointers/indexes before canonical verification and provenance.
                $this->schema->afterRows($snapshot, $target, $fresh);
                foreach ($tables as $kind => $rows) {
                    $definition = $this->schema->table($kind);
                    foreach ($rows as $row) {
                        $id = (string) $row['id'];
                        if ($fresh['actions'][$kind][$id] !== 'create') {
                            continue;
                        }
                        $actual = (array) $target->table($definition['table'])->where('id', $id)->first();
                        $digest = $this->schema->digest($kind, $actual);
                        if (! hash_equals($digest, $this->schema->digest($kind, $row))) {
                            throw new InvalidArgumentException('A target database conversion changed an imported row.');
                        }
                        $target->table('site_builder_import_map')->insert([
                            'run_id' => $run, 'source' => $snapshot->source()['namespace'], 'kind' => $kind, 'source_id' => $id, 'target_id' => $id,
                            'source_digest' => $snapshot->provenance($kind, $id) ?? $this->schema->digest($kind, $row), 'target_digest' => $digest,
                            'imported_write_version' => $kind === 'pages' ? $row['write_version'] : null,
                        ]);
                    }
                }
                $fresh['status'] = 'imported';
                $fresh['dry_run'] = false;
                $fresh['transaction_committed'] = true;
                $fresh['run_id'] = $run;
                unset($fresh['actions']);

                return $fresh;
            });
        } catch (Throwable $exception) {
            $report['status'] = 'failed';
            $report['cutover_eligible'] = false;
            $report['transaction_committed'] = false;
            $report['diagnostics'][] = ['severity' => 'error', 'code' => 'transaction_rolled_back', 'path' => '/', 'message' => 'Import failed atomically; no partially imported content was committed.'];

            return $report;
        }
    }

    public function plan(Snapshot $snapshot, Connection $target, bool $lock = false): array
    {
        if ($lock && $target->getSchemaBuilder()->hasTable($this->schema->table('pages')['table'])) {
            $ids = array_values(array_filter(array_column($snapshot->tables()['pages'] ?? [], 'id'), fn ($id): bool => preg_match('/^[1-9][0-9]{0,18}$/D', (string) $id) === 1));
            // Every graph adapter follows this with sorted resource locks, then mapping/index rows.
            $target->table($this->schema->table('pages')['table'])->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
        }

        return $this->schema->planning($snapshot, $target, $lock, fn (): array => $this->buildPlan($snapshot, $target, $lock));
    }

    private function buildPlan(Snapshot $snapshot, Connection $target, bool $lock): array
    {
        $source = $snapshot->source();
        $tables = $snapshot->tables();
        $report = ['format' => 'laravel-site-builder/import-report', 'version' => 1, 'source' => $source['namespace'], 'mapper_version' => 1, 'source_fingerprint' => null,
            'target_fingerprint' => ConnectionIdentity::fingerprint($target), 'status' => 'planned', 'dry_run' => true, 'transaction_committed' => false,
            'cutover_eligible' => false, 'counts' => ['create' => 0, 'unchanged' => 0, 'conflicts' => 0], 'entities' => [], 'diagnostics' => [], 'actions' => [], 'id_map' => []];
        if (NativeGraphImportScope::required($snapshot)) {
            $report['requires_fresh_graph_approval'] = true;
        }
        $issue = function (string $code, string $path, string $message) use (&$report): void {
            $report['diagnostics'][] = compact('code', 'path', 'message') + ['severity' => 'error'];
        };
        if (get_object_vars($snapshot->extensions()) !== []) {
            $issue('unhandled_snapshot_extensions', '/extensions', 'The raw extension metadata is retained in the file; register an explicit storage/conversion rule before cutover.');
        }
        if (($source['connection_fingerprint'] ?? null) === $report['target_fingerprint']) {
            $issue('source_equals_target', '/', 'The source and target resolve to the same database; use an isolated target.');
        }
        if (($source['application_timezone'] ?? null) !== config('app.timezone') || ($source['locales'] ?? null) !== array_keys(config('site-builder.locales')) || (isset($source['application_name']) && $source['application_name'] !== config('app.name'))) {
            $issue('context_mismatch', '/source', 'Application identity, ordered locales and timestamp semantics must match the captured source.');
        }
        if ($target->getDriverName() === 'mysql' && isset($source['database'])) {
            $context = (array) $target->selectOne('SELECT @@session.time_zone AS session_timezone, @@system_time_zone AS system_timezone');
            foreach (['session_timezone', 'system_timezone'] as $key) {
                if (data_get($source, 'database.'.$key) !== $context[$key]) {
                    $issue('database_timezone_mismatch', '/source/database', 'Match the source database timezone before importing naive SQL timestamps.');
                }
            }
        }
        if (array_diff(array_keys($tables), array_keys($this->schema->tables())) !== [] || array_diff(array_keys($this->schema->tables()), array_keys($tables)) !== []) {
            $issue('table_catalog_mismatch', '/tables', 'The snapshot must contain exactly the registered builder and explicit host tables.');

            return $report;
        }
        foreach (['site_builder_import_map', 'site_builder_import_runs'] as $table) {
            if (! $target->getSchemaBuilder()->hasTable($table)) {
                $issue('missing_import_schema', '/', 'Install the import provenance schema before planning.');

                return $report;
            }
        }
        try {
            $report['source_fingerprint'] = $snapshot->fingerprint($this->schema, $this->json);
        } catch (Throwable) {
            $issue('invalid_json_value', '/tables', 'Serialized JSON must be valid and interoperable; the raw snapshot remains unchanged.');

            return $report;
        }
        $pageIds = array_map('strval', array_column($tables['pages'], 'id'));
        $pageLayouts = array_column($tables['pages'], 'layout', 'id');
        $pageLocales = [];
        foreach ($tables['slugs'] as $slug) {
            $pageLocales[(string) $slug['page_id']][] = $slug['locale'];
        }
        foreach ($this->schema->tables() as $kind => $definition) {
            if (! $target->getSchemaBuilder()->hasTable($definition['table'])) {
                $issue('missing_target_table', '/tables/'.$kind, 'Install core and host schema migrations before planning a data import.');

                continue;
            }
            $columns = $target->getSchemaBuilder()->getColumnListing($definition['table']);
            if (array_diff($definition['columns'], $columns) !== []) {
                $issue('missing_host_columns', '/tables/'.$kind, 'A registered destination column is missing.');
            }
            $identities = [];
            $uniques = [];
            foreach ($tables[$kind] as $row) {
                $id = (string) $row['id'];
                $path = '/tables/'.$kind.'/'.$id;
                $uuid = $definition['identity'] === 'uuid';
                if (isset($identities[$id]) || ($uuid ? ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) : ! preg_match('/^[1-9][0-9]{0,18}$/D', $id))) {
                    $issue('invalid_or_duplicate_id', $path, 'Entity IDs must be unique canonical identities of their declared dataset kind.');
                }
                if ($uuid && $snapshot->version() < 3) {
                    $issue('unsupported_identity_format', $path, 'UUID datasets require native exchange envelope v3.');
                }
                if (! $uuid && strlen($id) === 19 && strcmp($id, (string) PHP_INT_MAX) > 0) {
                    $issue('identity_out_of_range', $path, 'The editor requires lossless positive PHP bigint identities.');
                }
                $identities[$id] = true;
                if (array_diff(array_keys($row), $definition['columns']) !== [] || array_diff($definition['columns'], array_keys($row)) !== []) {
                    $issue('unknown_or_missing_columns', $path, 'Review every source column instead of dropping or inventing fields.');
                }
                foreach ($definition['json'] as $column) {
                    if (isset($row[$column]) && ! is_string($row[$column])) {
                        $issue('invalid_json_storage', $path.'/'.$column, 'JSON columns contain serialized JSON strings or SQL null.');
                    }
                }
                foreach ($definition['integers'] as $column) {
                    if (isset($row[$column]) && ! preg_match('/^(0|[1-9][0-9]{0,18})$/D', (string) $row[$column])) {
                        $issue('invalid_integer', $path.'/'.$column, 'Integer column values cannot be coerced implicitly.');
                    }
                }
                if ($kind === 'revisions') {
                    $author = $row['created_by'] ?? null;
                    $actorType = $row['created_by_type'] ?? null;
                    $legacyZero = ($author === 0 || $author === '0') && $actorType === null;
                    if ($author !== null && ((! $legacyZero && ! ActorIdentity::validId($author)) || ($snapshot->version() === 1 && ! preg_match('/^(0|[1-9][0-9]{0,19})$/D', (string) $author)))) {
                        $issue('invalid_actor_identity', $path.'/created_by', 'Revision authors must be exact bounded actor IDs; v1 supports bigint authors only.');
                    }
                    if ($actorType !== null && (! is_string($actorType) || strlen($actorType) > 255 || ! preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $actorType) || $author === null || $snapshot->version() === 1)) {
                        $issue('invalid_actor_type', $path.'/created_by_type', 'Revision actor types require an explicit v2 bounded class identity and author.');
                    }
                }
                foreach ($row as $column => $value) {
                    if (($column === 'created_at' || $column === 'updated_at' || $column === 'published_at') && $value !== null && ! preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D', $value)) {
                        $issue('unsupported_timestamp', $path.'/'.$column, 'Preserve raw second-precision SQL timestamps; other precision/encodings need an explicit migration.');
                    }
                }
                if (in_array($kind, ['slugs', 'redirects', 'revisions'], true) && ! in_array((string) $row['page_id'], $pageIds, true)) {
                    $issue('orphan_page_reference', $path.'/page_id', 'A relationship points outside the captured page set.');
                }
                if ($kind === 'slugs' || $kind === 'redirects') {
                    $slug = $row[$kind === 'slugs' ? 'slug' : 'from_slug'];
                    if (! array_key_exists($row['locale'], config('site-builder.locales')) || ! is_string($slug) || $slug === '' || strlen($slug) > 255 || ! preg_match('~^[\pL\pN_-]+(?:/[\pL\pN_-]+)*$~uD', $slug)) {
                        $issue('invalid_locale_or_path', $path, 'Locales and authored paths must be explicit, configured and valid.');
                    }
                }
                foreach ($definition['unique'] as $constraint) {
                    $values = array_intersect_key($row, array_flip($constraint));
                    if (in_array(null, $values, true)) {
                        continue;
                    }
                    $key = $this->json->encode($values);
                    if (isset($uniques[$key])) {
                        $issue('duplicate_unique_value', $path, 'Source rows conflict on a declared unique constraint.');
                    }
                    $uniques[$key] = true;
                    $query = $target->table($definition['table'])->where($values)->where('id', '!=', $id);
                    if ($query->exists()) {
                        $issue('target_unique_conflict', $path, 'A destination identity already owns this unique value.');
                    }
                }
                if ($kind === 'pages') {
                    if (! in_array($row['status'], ['draft', 'published'], true) || ! is_string($row['title']) || $row['title'] === '') {
                        $issue('invalid_page_metadata', $path, 'Page status/title must be preserved as valid values.');
                    }
                    try {
                        $this->layouts->view($row['layout']);
                    } catch (Throwable) {
                        $issue('unsupported_layout', $path.'/layout', 'Register this layout before cutover.');
                    }
                }
                foreach ($kind === 'pages' ? ['draft_content', 'published_content'] : ($kind === 'revisions' ? ['content'] : []) as $column) {
                    if ($row[$column] === null) {
                        continue;
                    }
                    try {
                        $document = $this->codec->decode($row[$column]);
                        foreach ($this->references->scan($document) as $reference) {
                            if ($reference->kind === 'page' && ! in_array((string) $reference->value, $pageIds, true)) {
                                $issue('dangling_page_reference', $path.'/'.$column.'/'.$reference->nodeId.$reference->path, 'A declared component page reference is outside the preserved identity map.');
                            }
                        }
                        $diagnostics = $this->documents->diagnostics($document, true, $pageLocales[(string) ($row['page_id'] ?? $id)] ?? [], new RenderContext(array_key_first(config('site-builder.locales')), 'public', $kind === 'pages' ? (int) $id : (int) $row['page_id'], $kind === 'pages' ? $row['layout'] : ($pageLayouts[(string) $row['page_id']] ?? null)));
                        foreach ($diagnostics as $diagnostic) {
                            $issue($diagnostic->code, $path.'/'.$column.$diagnostic->path, $diagnostic->message);
                        }
                        $checkLegacy = function (array $nodes) use (&$checkLegacy, $issue, $path, $column): void {
                            foreach ($nodes as $node) {
                                if (isset($node->extensions->get('legacy.atelier')->node)) {
                                    $issue('unreviewed_legacy_node_fields', $path.'/'.$column, 'Unknown legacy node keys were retained and need semantic review.');
                                }
                                $checkLegacy($node->children);
                            }
                        };
                        $checkLegacy($document->nodes);
                    } catch (Throwable) {
                        $issue('unsupported_document', $path.'/'.$column, 'The raw document is retained in the snapshot; an explicit converter is required.');
                    }
                }
                $mappingQuery = $target->table('site_builder_import_map')->where(['source' => $source['namespace'], 'kind' => $kind, 'source_id' => $id]);
                $rowQuery = $target->table($definition['table'])->where('id', $id);
                if ($lock) {
                    $mappingQuery->lockForUpdate();
                    $rowQuery->lockForUpdate();
                }
                $mapping = $mappingQuery->first();
                $existing = $rowQuery->first();
                $expected = $this->schema->digest($kind, $row);
                $sourceDigest = $snapshot->provenance($kind, $id) ?? $expected;
                $action = 'create';
                if ($mapping !== null) {
                    if (! hash_equals($mapping->source_digest, $sourceDigest) || ! hash_equals($mapping->target_digest, $expected)) {
                        $issue('source_changed', $path, 'Reconcile the changed source or rebuild a clean isolated target; automatic overwrite is forbidden.');
                        $action = 'conflict';
                    } elseif ($existing === null || ! hash_equals($mapping->target_digest, $this->schema->digest($kind, (array) $existing))) {
                        $issue('target_changed', $path, 'A target editor changed/deleted this entity; their changes are retained.');
                        $action = 'conflict';
                    } else {
                        $action = 'unchanged';
                    }
                } elseif ($existing !== null) {
                    $issue('occupied_target_identity', $path, 'The destination ID is occupied; page IDs are preserved and never remapped implicitly.');
                    $action = 'conflict';
                }
                $report['actions'][$kind][$id] = $action;
                $report['id_map'][$kind][$id] = $id;
                $report['counts'][$action === 'conflict' ? 'conflicts' : $action]++;
                $report['entities'][$kind][$id] = ['source_digest' => $sourceDigest, 'expected_digest' => $expected, 'action' => $action];
            }
        }
        if ($target->getSchemaBuilder()->hasTable('site_builder_import_map')) {
            foreach ($target->table('site_builder_import_map')->where('source', $source['namespace'])->get() as $mapping) {
                if (! isset($report['actions'][$mapping->kind][$mapping->source_id])) {
                    $issue('source_entity_missing', '/tables/'.$mapping->kind.'/'.$mapping->source_id, 'Missing source entities never imply deletion of target content.');
                }
            }
        }
        if ($target->getSchemaBuilder()->hasTable('site_builder_page_locks') && $target->table('site_builder_page_locks')->whereIn('page_id', $pageIds)->where('expires_at', '>', now())->exists()) {
            $issue('editing_not_frozen', '/tables/pages', 'Close active target editor sessions before importing.');
        }
        $inspection = $this->schema->inspect($snapshot);
        $report['diagnostics'] = [...$report['diagnostics'], ...$inspection['diagnostics']];
        $report['metrics'] = $inspection['metrics'];
        $report['cutover_eligible'] = $report['diagnostics'] === [];

        return $report;
    }
}
