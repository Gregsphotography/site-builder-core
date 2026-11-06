<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use RuntimeException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\Reference;
use SiteBuilder\Core\Composition\CompositionData;
use SiteBuilder\Core\Composition\CompositionResolver;
use SiteBuilder\Core\Composition\ReferenceCheck;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Exchange\Exchange;
use SiteBuilder\Core\Exchange\ExchangeSchema;
use SiteBuilder\Core\Exchange\Snapshot;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageRevision;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\SavedSections\SavedSectionCommands;
use SiteBuilder\Core\SavedSections\SavedSectionExchange;
use SiteBuilder\Core\SiteBuilderServiceProvider;
use SiteBuilder\Core\SiteParts\NativeGraphImportCommands;
use SiteBuilder\Core\SiteParts\NativeGraphImportPolicy;
use SiteBuilder\Core\SiteParts\NativeGraphImportScope;
use SiteBuilder\Core\SiteParts\NativeGraphMapper;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Core\SiteParts\SitePartExchange;
use SiteBuilder\Core\SiteParts\SitePartGraphMap;
use SiteBuilder\Core\SiteParts\SitePartPayload;
use SiteBuilder\Core\SiteParts\SitePartRevision;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\CompositionBlock;

class SitePartExchangeTest extends DatabaseTestCase
{
    private ?Connection $target = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['site-builder.locales' => ['en' => [], 'de' => []]]);
        $this->migrateOptional();
        app(ComponentRegistry::class)->register(new CompositionBlock);
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
        Gate::before(fn () => true);
        SavedSectionExchange::register(app(ExchangeSchema::class), preserveActorOwnership: true);
        SitePartExchange::register(app(ExchangeSchema::class));
    }

    protected function tearDown(): void
    {
        if ($this->target !== null) {
            $this->target->getSchemaBuilder()->dropAllTables();
            DB::purge('site_part_exchange_target');
        }
        parent::tearDown();
    }

    private function migrateOptional(?string $connection = null): void
    {
        $root = dirname((new ReflectionClass(SiteBuilderServiceProvider::class))->getFileName(), 2);
        foreach (['saved-sections-migrations', 'site-parts-migrations'] as $directory) {
            $options = ['--path' => $root.'/database/'.$directory, '--realpath' => true, '--force' => true];
            if ($connection !== null) {
                $options['--database'] = $connection;
            }
            $this->artisan('migrate', $options)->assertExitCode(0);
        }
    }

    private function target(): Connection
    {
        if ($this->target !== null) {
            return $this->target;
        }
        $configuration = DB::connection()->getConfig();
        $database = getenv('SITE_BUILDER_EXCHANGE_TARGET_DATABASE');
        if ($configuration['driver'] === 'mysql' && is_string($database) && $database !== '') {
            if (! str_starts_with($database, 'site_builder_') || ! str_ends_with($database, '_test') || $database === DB::connection()->getDatabaseName()) {
                throw new RuntimeException('Graph exchange requires a distinct reserved site_builder_*_test target.');
            }
            $configuration['database'] = $database;
        } else {
            $configuration = ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true];
        }
        unset($configuration['name']);
        config(['database.connections.site_part_exchange_target' => $configuration]);
        $this->target = DB::connection('site_part_exchange_target');
        $this->target->getSchemaBuilder()->dropAllTables();
        $this->artisan('migrate', $this->migrationOptions() + ['--database' => 'site_part_exchange_target'])->assertExitCode(0);
        $this->migrateOptional('site_part_exchange_target');

        return $this->target;
    }

    private function document(string $heading): Document
    {
        return new Document([new Node('text', 'test.composition', translations: new JsonObject(['en' => (object) ['heading' => $heading], 'de' => (object) ['heading' => 'Deutsch '.$heading]]), extensions: new JsonObject(['opaque' => (object) ['empty' => (object) [], 'list' => [], 'null' => null]]))]);
    }

    private function revision(string $kind, Document $published, ?Document $draft = null): SitePartRevision
    {
        $payload = SitePartPayload::document($kind, $published);
        $part = new SitePart;
        $part->forceFill(['id' => (string) Str::uuid(), 'kind' => $kind, 'name' => 'Shared '.$kind, 'draft_payload' => SitePartPayload::document($kind, $draft ?? $published), 'write_version' => 3, 'created_by' => '01JBDR03XETB5V4C2625GJ44JD', 'created_by_type' => GenericUser::class, 'updated_by' => '01JBDR03XETB5V4C2625GJ44JD', 'updated_by_type' => GenericUser::class])->save();
        $revision = new SitePartRevision;
        $revision->forceFill(['id' => (string) Str::uuid(), 'resource_id' => $part->id, 'kind' => $kind, 'sequence' => 1, 'payload' => $payload, 'digest' => app(CanonicalJson::class)->digest($payload), 'published_from_version' => 1, 'created_by' => '01JBDR03XETB5V4C2625GJ44JD', 'created_by_type' => GenericUser::class, 'published_at' => now()])->save();
        $part->forceFill(['current_revision_id' => $revision->id])->save();

        return $revision;
    }

    private function reference(SitePartRevision $revision): Document
    {
        return CompositionData::promote(new Document([new Node('root', 'core.global-reference', props: new JsonObject(['revision' => $revision->reference()->jsonSerialize()]))]));
    }

    private function source(bool $withReferences = false): array
    {
        $innerDocument = $this->document('Published inner');
        if ($withReferences) {
            $targetPage = Page::factory()->create(['draft_content' => $this->document('Referenced page')]);
            $node = $innerDocument->nodes[0];
            $translations = $node->translations->toObject();
            $translations->de->items = [(object) ['label' => 'Deutsch', 'target' => $targetPage->id, 'image' => '/source/de.jpg']];
            $extensions = $node->extensions->toObject();
            $extensions->opaque->originalPageId = $targetPage->id;
            $innerDocument = new Document([new Node($node->id, $node->type, props: new JsonObject(['target' => $targetPage->id, 'image' => '/source/en.jpg']), translations: new JsonObject($translations), extensions: new JsonObject($extensions))]);
        }
        $inner = $this->revision('section', $innerDocument, $this->document('Private inner draft'));
        $outer = $this->revision('section', $this->reference($inner));
        $header = $this->revision('header', $this->document('Published header'));
        $draft = $this->reference($outer);
        $extensions = $draft->extensions->toObject();
        $extensions->{'site-builder.render'}->composition->header = $header->reference()->jsonSerialize();
        $draft = new Document($draft->nodes, new JsonObject($extensions));
        $page = Page::factory()->create(['draft_content' => $draft, 'published_content' => $this->reference($inner), 'status' => 'published']);
        $revision = new PageRevision;
        $revision->forceFill(['page_id' => $page->id, 'scope' => 'content', 'content' => $draft, 'created_by' => '01JBDR03XETB5V4C2625GJ44JD', 'created_by_type' => GenericUser::class, 'label' => 'Historical global pin', 'created_at' => now(), 'published_from_version' => 0, 'origin' => 'native'])->save();
        $actor = new GenericUser(['id' => 7]);
        $lease = app(PageLocks::class)->acquire($actor, $page->id);
        app(SavedSectionCommands::class)->capture($actor, $page->id, $lease->token, 0, 'root', ['name' => 'Retained global section']);

        return [app(Exchange::class)->export(DB::connection(), 'site-part-graph'), $inner, $outer];
    }

    public function test_full_v3_graph_roundtrips_pins_drafts_revisions_saved_content_and_author_facts_without_operational_state(): void
    {
        [$snapshot] = $this->source();
        $target = $this->target();
        $exchange = app(Exchange::class);
        $dry = $exchange->import($snapshot, $target, true);
        $this->assertTrue($dry['cutover_eligible'], json_encode($dry['diagnostics']));
        $this->assertSame(0, $target->table('site_builder_site_parts')->count());
        // Input JSON order cannot choose FK insertion order.
        $tables = array_reverse($snapshot->tables(), true);
        $report = $exchange->import(Snapshot::decode((new Snapshot($snapshot->source(), $tables))->encode()), $target);
        $this->assertSame('imported', $report['status'], json_encode($report['diagnostics']));
        foreach ($snapshot->tables() as $kind => $rows) {
            foreach ($rows as $row) {
                $schema = app(ExchangeSchema::class);
                $actual = (array) $target->table($schema->table($kind)['table'])->where('id', $row['id'])->first();
                $this->assertSame($schema->digest($kind, $row), $schema->digest($kind, $actual), $kind.' '.$row['id']);
            }
        }
        $this->assertSame(3, $target->table('site_builder_site_parts')->whereNotNull('current_revision_id')->count());
        $this->assertGreaterThanOrEqual(8, $target->table('site_builder_content_dependencies')->count());
        $this->assertSame(0, $target->table('site_builder_page_locks')->count());
        $this->assertSame(0, $target->table('site_builder_site_part_locks')->count());
        $this->assertFalse($target->getSchemaBuilder()->hasTable('users'));
        foreach (['site_part_locks', 'content_dependencies', 'draft_histories', 'clipboard_snapshots', 'users'] as $kind) {
            $this->assertArrayNotHasKey($kind, $snapshot->tables());
        }
        $this->assertSame('historical_only_no_destination_permission_grants', $report['metrics']['site_part_author_facts']);
        $this->assertSame('unchanged', $exchange->import($snapshot, $target)['status']);
        $this->assertSame(1, $target->table('site_builder_import_runs')->count());
        $this->assertSame(3, SitePart::count(), 'The original source graph is unchanged.');
    }

    public function test_missing_closure_never_falls_back_to_original_database_and_bad_digests_kinds_actors_and_cycles_block_before_apply(): void
    {
        [$snapshot, $inner] = $this->source();
        $target = $this->target();
        $mutations = [
            function (array &$tables) use ($inner): void {
                $tables['site_part_revisions'] = array_values(array_filter($tables['site_part_revisions'], fn ($row) => $row['id'] !== $inner->id));
            },
            function (array &$tables): void {
                $tables['site_part_revisions'][0]['digest'] = str_repeat('0', 64);
            },
            function (array &$tables): void {
                $tables['site_parts'][0]['current_revision_id'] = (string) Str::uuid();
            },
            function (array &$tables): void {
                $tables['site_parts'][0]['created_by_type'] = '../not-an-actor';
            },
            function (array &$tables) use ($inner): void {
                $index = array_find_key($tables['site_parts'], fn ($row) => $row['id'] === $inner->resource_id);
                $tables['site_parts'][$index]['draft_payload'] = json_encode(SitePartPayload::document('section', $this->reference($inner)), JSON_THROW_ON_ERROR);
            },
        ];
        foreach ($mutations as $mutate) {
            $tables = $snapshot->tables();
            $mutate($tables);
            $report = app(Exchange::class)->import(new Snapshot($snapshot->source(), $tables), $target);
            $this->assertSame('rejected', $report['status'], json_encode($report['diagnostics']));
            $this->assertNotEmpty($report['diagnostics']);
            $this->assertSame(0, $target->table('site_builder_pages')->count());
            $this->assertSame(0, $target->table('site_builder_site_parts')->count());
            $this->assertSame(0, $target->table('site_builder_import_map')->count());
        }
        $this->assertSame($inner->id, app(CompositionResolver::class)->revision($inner->reference())->id, 'Incoming catalog scope restores the ordinary database reader.');
        config(['site-builder.composition.max_mounts' => 1]);
        $this->assertFalse(app(Exchange::class)->plan($snapshot, $target)['cutover_eligible']);
    }

    public function test_failure_after_restored_pointers_and_dependency_writes_rolls_back_the_entire_graph_and_provenance(): void
    {
        [$snapshot] = $this->source();
        $target = $this->target();
        $observed = [];
        app(ExchangeSchema::class)->afterRowsUsing(function (Snapshot $snapshot, Connection $target) use (&$observed): void {
            $observed = ['pointers' => $target->table('site_builder_site_parts')->whereNotNull('current_revision_id')->count(), 'dependencies' => $target->table('site_builder_content_dependencies')->count(), 'runs' => $target->table('site_builder_import_runs')->count()];
            throw new RuntimeException('Forced late graph import failure.');
        });
        $report = app(Exchange::class)->import($snapshot, $target);
        $this->assertSame('failed', $report['status']);
        $this->assertSame(3, $observed['pointers']);
        $this->assertGreaterThan(0, $observed['dependencies']);
        $this->assertSame(1, $observed['runs']);
        foreach (['pages', 'page_revisions', 'saved_sections', 'saved_section_versions', 'site_parts', 'site_part_revisions', 'content_dependencies', 'import_map', 'import_runs'] as $table) {
            $this->assertSame(0, $target->table('site_builder_'.$table)->count(), $table);
        }
        $this->assertSame(3, SitePart::count());
    }

    private function mapping(Snapshot $snapshot): array
    {
        $mapper = app(NativeGraphMapper::class);
        $description = $mapper->describe($snapshot);
        $identities = $lookup = [];
        foreach ($description['identities'] as $kind => $ids) {
            $identities[$kind] = [];
            foreach ($ids as $id) {
                $target = app(ExchangeSchema::class)->table($kind)['identity'] === 'uuid' ? (string) Str::uuid() : (string) ((int) $id + 1000);
                $identities[$kind][] = ['source' => $id, 'target' => $target];
                $lookup[$kind][$id] = $target;
            }
        }
        $choices = $description['occurrences'];
        foreach ($choices as &$choice) {
            $choice['value'] = match ($choice['kind']) {
                'page' => (int) $lookup['pages'][(string) $choice['original']],
                'media' => '/mapped'.$choice['original'],
                'revision' => (object) ['resourceId' => $lookup['site_parts'][$choice['original']->resourceId], 'revisionId' => $lookup['site_part_revisions'][$choice['original']->revisionId]],
            };
        }
        unset($choice);

        return [$identities, $choices, $lookup];
    }

    private function policy(bool $references = true, bool $opaque = false): NativeGraphImportPolicy
    {
        return new class($references, $opaque) implements NativeGraphImportPolicy
        {
            public function __construct(private bool $references, private bool $opaque) {}

            public function authorize(Authenticatable $actor, Snapshot $source, SitePartGraphMap $map, Connection $target): void {}

            public function reference(Authenticatable $actor, string $document, Reference $source, mixed $mapped, Connection $target): ReferenceCheck
            {
                return $this->references ? ReferenceCheck::valid('Explicit test host import/domain approval.') : ReferenceCheck::unauthorized('Host domain/reference use refused.');
            }

            public function allowOpaque(Authenticatable $actor, string $document, Document $source): bool
            {
                return $this->opaque;
            }
        };
    }

    public function test_explicit_graph_mapping_avoids_occupied_ids_updates_nested_pin_digests_and_declared_translated_refs_only(): void
    {
        [$snapshot, $inner, $outer] = $this->source(true);
        $originalBytes = $snapshot->encode();
        $target = $this->target();
        $sourcePages = $snapshot->tables()['pages'];
        $occupiedPageId = $sourcePages[0]['id'];
        $target->table('site_builder_pages')->insert(['id' => $occupiedPageId, 'title' => 'Existing target page', 'write_version' => 99]);
        $originalPart = array_find($snapshot->tables()['site_parts'], fn ($row) => $row['id'] === $inner->resource_id);
        $target->table('site_builder_site_parts')->insert(array_replace($originalPart, ['name' => 'Existing target part', 'current_revision_id' => null]));
        $originalRevision = array_find($snapshot->tables()['site_part_revisions'], fn ($row) => $row['id'] === $inner->id);
        $target->table('site_builder_site_part_revisions')->insert($originalRevision);
        [$identities, $choices, $lookup] = $this->mapping($snapshot);
        $mapped = app(NativeGraphMapper::class)->map(new GenericUser(['id' => 7]), $snapshot, SitePartGraphMap::fromArray($identities, $choices), $target, $this->policy());
        $this->assertSame($originalBytes, $snapshot->encode());
        $rows = array_column($mapped->tables()['site_part_revisions'], null, 'id');
        $mappedInner = $rows[$lookup['site_part_revisions'][$inner->id]];
        $mappedOuter = $rows[$lookup['site_part_revisions'][$outer->id]];
        $this->assertNotSame($inner->digest, $mappedInner['digest']);
        $this->assertNotSame($outer->digest, $mappedOuter['digest']);
        $innerDocument = SitePartPayload::decode($mappedInner['payload'])->document;
        $this->assertSame((int) $lookup['pages'][(string) $occupiedPageId], $innerDocument->nodes[0]->props->get('target'));
        $this->assertSame('/mapped/source/en.jpg', $innerDocument->nodes[0]->props->get('image'));
        $this->assertSame('/mapped/source/de.jpg', $innerDocument->nodes[0]->translations->get('de')->items[0]->image);
        $this->assertSame((int) $occupiedPageId, $innerDocument->nodes[0]->extensions->get('opaque')->originalPageId, 'Opaque numbers are preserved, never guessed as references.');
        $this->assertSame($innerDocument->nodes[0]->id, $inner->payload->document->nodes[0]->id, 'Native identity mapping keeps document-local node identities.');
        foreach (app(NativeGraphMapper::class)->describe($mapped)['occurrences'] as $choice) {
            if ($choice['kind'] === 'revision') {
                $pin = $choice['original'];
                $this->assertSame($rows[$pin->revisionId]['digest'], $pin->digest);
                $this->assertSame($rows[$pin->revisionId]['resource_id'], $pin->resourceId);
            }
        }
        $this->assertSame(app(ExchangeSchema::class)->digest('site_part_revisions', $originalRevision), $mapped->provenance('site_part_revisions', $mappedInner['id']));
        $this->assertSame($inner->id, data_get($mapped->source(), ['graph_mapping', 'origin_rows', 'site_part_revisions', $mappedInner['id'], 'id']));
        $this->assertSame(1, $target->table('site_builder_pages')->count(), 'Mapping is read-only.');
        $report = app(NativeGraphImportCommands::class)->import(new GenericUser(['id' => 7]), $snapshot, SitePartGraphMap::fromArray($identities, $choices), $target, $this->policy());
        $this->assertSame('imported', $report['status'], json_encode($report['diagnostics']));
        $this->assertSame('Existing target page', $target->table('site_builder_pages')->where('id', $occupiedPageId)->value('title'));
        $this->assertSame('Existing target part', $target->table('site_builder_site_parts')->where('id', $inner->resource_id)->value('name'));
        $this->assertGreaterThan(0, $target->table('site_builder_content_dependencies')->where('revision_id', $mappedInner['id'])->count());
        [$againIds, $againChoices, $againLookup] = $this->mapping($mapped);
        $again = app(NativeGraphMapper::class)->map(new GenericUser(['id' => 7]), $mapped, SitePartGraphMap::fromArray($againIds, $againChoices), $target, $this->policy());
        $againInner = $againLookup['site_part_revisions'][$mappedInner['id']];
        $this->assertSame($inner->id, data_get($again->source(), ['graph_mapping', 'origin_rows', 'site_part_revisions', $againInner, 'id']));
        $this->assertSame(app(ExchangeSchema::class)->digest('site_part_revisions', $originalRevision), $again->provenance('site_part_revisions', $againInner));
    }

    public function test_incomplete_unauthorized_wrong_digest_and_changed_immutable_identity_maps_fail_without_apply(): void
    {
        [$snapshot, $inner] = $this->source(true);
        $target = $this->target();
        [$identities, $choices] = $this->mapping($snapshot);
        $cases = [];
        $duplicates = $identities;
        $duplicates['pages'][1]['target'] = $duplicates['pages'][0]['target'];
        $cases[] = [$duplicates, $choices, $this->policy()];
        $badId = $identities;
        $badId['pages'][0]['target'] = '00';
        $cases[] = [$badId, $choices, $this->policy()];
        $missing = $identities;
        array_pop($missing['pages']);
        $cases[] = [$missing, $choices, $this->policy()];
        $cases[] = [$identities, array_slice($choices, 1), $this->policy()];
        $badDigest = $choices;
        $index = array_find_key($badDigest, fn ($choice) => $choice['kind'] === 'revision');
        $badDigest[$index]['value'] = clone $badDigest[$index]['value'];
        $badDigest[$index]['value']->digest = str_repeat('0', 64);
        $cases[] = [$identities, $badDigest, $this->policy()];
        $unchangedUuid = $identities;
        foreach ($unchangedUuid['site_part_revisions'] as &$entry) {
            if ($entry['source'] === $inner->id) {
                $entry['target'] = $inner->id;
            }
        }
        unset($entry);
        $cases[] = [$unchangedUuid, $choices, $this->policy()];
        $cases[] = [$identities, $choices, $this->policy(references: false)];
        foreach ($cases as [$ids, $occurrences, $policy]) {
            try {
                app(NativeGraphMapper::class)->map(new GenericUser(['id' => 7]), $snapshot, SitePartGraphMap::fromArray($ids, $occurrences), $target, $policy);
                $this->fail('An incomplete/unauthorized/inconsistent remap must not produce an applicable graph.');
            } catch (ValidationException|\InvalidArgumentException) {
                $this->assertSame(0, $target->table('site_builder_pages')->count());
                $this->assertSame(0, $target->table('site_builder_site_parts')->count());
                $this->assertSame(0, $target->table('site_builder_import_runs')->count());
            }
        }
    }

    public function test_unknown_saved_schema_needs_explicit_opaque_adapter_and_is_never_blindly_rewritten(): void
    {
        [$snapshot] = $this->source(true);
        $tables = $snapshot->tables();
        $opaque = new Document([new Node('root', 'future.saved', props: new JsonObject(['uninterpretedPageId' => (int) $tables['pages'][0]['id'], 'empty' => (object) [], 'list' => []]))]);
        $tables['saved_section_versions'][0]['document'] = app(DocumentCodec::class)->encode($opaque);
        $tables['saved_section_versions'][0]['digest'] = app(CanonicalJson::class)->digest($opaque);
        $snapshot = new Snapshot($snapshot->source(), $tables);
        [$identities, $choices, $lookup] = $this->mapping($snapshot);
        $target = $this->target();
        try {
            app(NativeGraphMapper::class)->map(new GenericUser(['id' => 7]), $snapshot, SitePartGraphMap::fromArray($identities, $choices), $target, $this->policy());
            $this->fail('An unknown reference schema cannot be guessed during nonidentity conversion.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('unknown component/reference semantics', $exception->getMessage());
        }
        $mapped = app(NativeGraphMapper::class)->map(new GenericUser(['id' => 7]), $snapshot, SitePartGraphMap::fromArray($identities, $choices), $target, $this->policy(opaque: true));
        $this->assertNotSame($tables['saved_section_versions'][0]['id'], $mapped->tables()['saved_section_versions'][0]['id']);
        $this->assertSame(app(CanonicalJson::class)->digest($opaque), $mapped->tables()['saved_section_versions'][0]['digest']);
        $this->assertSame((int) $tables['pages'][0]['id'], app(DocumentCodec::class)->decode($mapped->tables()['saved_section_versions'][0]['document'])->nodes[0]->props->get('uninterpretedPageId'));
        $this->assertSame(0, $target->table('site_builder_pages')->count());
    }

    public function test_actual_graph_apply_reapproves_the_original_with_fresh_policy_and_never_trusts_mapped_artifact_metadata(): void
    {
        [$snapshot] = $this->source(true);
        $target = $this->target();
        [$identities, $choices] = $this->mapping($snapshot);
        $map = SitePartGraphMap::fromArray($identities, $choices);
        $policy = new class implements NativeGraphImportPolicy
        {
            public bool $allowed = true;

            public function authorize(Authenticatable $actor, Snapshot $source, SitePartGraphMap $map, Connection $target): void
            {
                if (! $this->allowed) {
                    throw new AuthorizationException('Destination import permission revoked.');
                }
            }

            public function reference(Authenticatable $actor, string $document, Reference $source, mixed $mapped, Connection $target): ReferenceCheck
            {
                return ReferenceCheck::valid('Explicit current host approval.');
            }

            public function allowOpaque(Authenticatable $actor, string $document, Document $source): bool
            {
                return false;
            }
        };
        $actor = new GenericUser(['id' => 7]);
        $artifact = app(NativeGraphMapper::class)->map($actor, $snapshot, $map, $target, $policy);
        $this->assertNotNull(data_get($artifact->source(), 'graph_mapping.map_digest'));
        $raw = app(Exchange::class)->import($artifact, $target);
        $this->assertSame('rejected', $raw['status']);
        $this->assertContains('graph_import_reapproval_required', array_column($raw['diagnostics'], 'code'));
        $this->assertTrue(app(Exchange::class)->import($artifact, $target, true)['requires_fresh_graph_approval']);
        $policy->allowed = false;
        foreach ([fn () => app(NativeGraphImportCommands::class)->import($actor, $snapshot, $map, $target, $policy), fn () => app(NativeGraphImportCommands::class)->import($actor, $artifact, $map, $target)] as $attempt) {
            try {
                $attempt();
                $this->fail('A previously approved mapped artifact cannot grant its own write permission.');
            } catch (AuthorizationException) {
                foreach (['pages', 'site_parts', 'site_part_revisions', 'content_dependencies', 'import_runs', 'import_map'] as $table) {
                    $this->assertSame(0, $target->table('site_builder_'.$table)->count());
                }
            }
        }
        $policy->allowed = true;
        $report = app(NativeGraphImportCommands::class)->import($actor, $snapshot, $map, $target, $policy);
        $this->assertSame('imported', $report['status'], json_encode($report['diagnostics']));
        $this->assertGreaterThan(0, $target->table('site_builder_content_dependencies')->count());
        $this->assertSame('unchanged', app(NativeGraphImportCommands::class)->import($actor, $snapshot, $map, $target, $policy)['status']);
        $this->assertSame('rejected', app(Exchange::class)->import($artifact, $target)['status'], 'Runtime permission ends when the approved command returns.');
        $this->assertSame(1, $target->table('site_builder_import_runs')->count());
        $policy->allowed = false;
        $before = $target->table('site_builder_content_dependencies')->count();
        try {
            app(NativeGraphImportCommands::class)->import($actor, $snapshot, $map, $target, $policy);
            $this->fail('Even an exact accepted conversion retry requires fresh operator approval.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $target->table('site_builder_content_dependencies')->count());
            $this->assertSame(1, $target->table('site_builder_import_runs')->count());
        }
    }

    public function test_identical_occupied_rows_without_profile_provenance_and_changed_source_or_target_never_gain_retry_permission(): void
    {
        [$snapshot] = $this->source(true);
        $target = $this->target();
        [$ids, $choices, $lookup] = $this->mapping($snapshot);
        $map = SitePartGraphMap::fromArray($ids, $choices);
        $actor = new GenericUser(['id' => 7]);
        $commands = app(NativeGraphImportCommands::class);
        $this->assertSame('imported', $commands->import($actor, $snapshot, $map, $target, $this->policy())['status']);
        $provenance = $target->table('site_builder_import_map')->get()->map(fn ($row): array => (array) $row)->all();
        $target->table('site_builder_import_map')->delete();
        try {
            $commands->import($actor, $snapshot, $map, $target, $this->policy());
            $this->fail('Identical occupied content is not proof of a prior accepted conversion profile.');
        } catch (ValidationException) {
            $this->assertSame(1, $target->table('site_builder_import_runs')->count());
            $this->assertSame(0, $target->table('site_builder_import_map')->count());
        }
        $target->table('site_builder_import_map')->insert($provenance);
        $tables = $snapshot->tables();
        $tables['pages'][0]['title'] = 'Changed source';
        try {
            $commands->import($actor, new Snapshot($snapshot->source(), $tables), $map, $target, $this->policy());
            $this->fail('A changed source cannot reuse occupied conversion identities.');
        } catch (ValidationException) {
            $this->assertNotSame('Changed source', $target->table('site_builder_pages')->where('id', $lookup['pages'][(string) $tables['pages'][0]['id']])->value('title'));
        }
        $id = $lookup['pages'][(string) $tables['pages'][0]['id']];
        $target->table('site_builder_pages')->where('id', $id)->update(['title' => 'Accepted target edit', 'write_version' => 8]);
        try {
            $commands->import($actor, $snapshot, $map, $target, $this->policy());
            $this->fail('An editor change cannot be overwritten by a conversion retry.');
        } catch (ValidationException) {
            $this->assertSame('Accepted target edit', $target->table('site_builder_pages')->where('id', $id)->value('title'));
            $this->assertSame(1, $target->table('site_builder_import_runs')->count());
        }
    }

    public function test_late_failed_approved_apply_never_leaks_runtime_graph_write_admission(): void
    {
        [$snapshot] = $this->source(true);
        $target = $this->target();
        [$ids, $choices] = $this->mapping($snapshot);
        $map = SitePartGraphMap::fromArray($ids, $choices);
        $actor = new GenericUser(['id' => 7]);
        $artifact = app(NativeGraphMapper::class)->map($actor, $snapshot, $map, $target, $this->policy());
        app(ExchangeSchema::class)->afterRowsUsing(fn () => throw new RuntimeException('Late approved import failure.'));
        $this->assertSame('failed', app(NativeGraphImportCommands::class)->import($actor, $snapshot, $map, $target, $this->policy())['status']);
        $this->assertFalse(app(NativeGraphImportScope::class)->allows($artifact, $target));
        $raw = app(Exchange::class)->import($artifact, $target);
        $this->assertSame('rejected', $raw['status']);
        $this->assertContains('graph_import_reapproval_required', array_column($raw['diagnostics'], 'code'));
        foreach (['pages', 'site_parts', 'site_part_revisions', 'content_dependencies', 'import_runs', 'import_map'] as $table) {
            $this->assertSame(0, $target->table('site_builder_'.$table)->count());
        }
    }

    public function test_permission_revoked_while_waiting_for_apply_locks_refuses_every_graph_write(): void
    {
        [$snapshot] = $this->source(true);
        $target = $this->target();
        [$ids, $choices] = $this->mapping($snapshot);
        $map = SitePartGraphMap::fromArray($ids, $choices);
        $policy = new class implements NativeGraphImportPolicy
        {
            public bool $allowed = true;

            public int $approvals = 0;

            public function authorize(Authenticatable $actor, Snapshot $source, SitePartGraphMap $map, Connection $target): void
            {
                $this->approvals++;
                if (! $this->allowed) {
                    throw new AuthorizationException('Permission revoked while waiting for target locks.');
                }
            }

            public function reference(Authenticatable $actor, string $document, Reference $source, mixed $mapped, Connection $target): ReferenceCheck
            {
                return ReferenceCheck::valid();
            }

            public function allowOpaque(Authenticatable $actor, string $document, Document $source): bool
            {
                return false;
            }
        };
        $locked = false;
        app(ExchangeSchema::class)->aroundPlanningUsing(function (Snapshot $source, Connection $connection, bool $lock, \Closure $next) use ($policy, &$locked): array {
            $report = $next();
            if ($lock) {
                $locked = true;
                $policy->allowed = false;
            }

            return $report;
        });
        $report = app(NativeGraphImportCommands::class)->import(new GenericUser(['id' => 7]), $snapshot, $map, $target, $policy);
        $this->assertTrue($locked);
        $this->assertSame(2, $policy->approvals, 'Recheck the original, map and fresh policy after the target graph locks.');
        $this->assertSame('failed', $report['status']);
        foreach (['pages', 'page_revisions', 'saved_sections', 'saved_section_versions', 'site_parts', 'site_part_revisions', 'content_dependencies', 'import_runs', 'import_map'] as $table) {
            $this->assertSame(0, $target->table('site_builder_'.$table)->count(), $table);
        }
    }

    public function test_graph_write_scope_pins_full_canonical_envelope_exact_connection_and_restores_nested_failures(): void
    {
        [$snapshot] = $this->source(true);
        $target = $this->target();
        [$ids, $choices] = $this->mapping($snapshot);
        $map = SitePartGraphMap::fromArray($ids, $choices);
        $actor = new GenericUser(['id' => 7]);
        $artifact = app(NativeGraphMapper::class)->map($actor, $snapshot, $map, $target, $this->policy());
        $scope = app(NativeGraphImportScope::class);
        $scope->run($actor, $snapshot, $map, $artifact, $target, function () use ($scope, $actor, $snapshot, $map, $artifact, $target): void {
            $this->assertTrue($scope->allows($artifact, $target));
            $this->assertFalse($scope->allows($artifact, DB::connection()));
            $payload = $artifact->jsonSerialize();
            $provenance = [];
            foreach ($payload->provenance as $kind => $rows) {
                $provenance[$kind] = get_object_vars($rows);
            }
            $originalProvenance = $provenance;
            $kind = array_key_first($provenance);
            $id = array_key_first($provenance[$kind]);
            $provenance[$kind][$id] = str_repeat('0', 64);
            $tampered = new Snapshot($artifact->source(), $artifact->tables(), $provenance);
            $this->assertSame($artifact->fingerprint(app(ExchangeSchema::class), app(CanonicalJson::class)), $tampered->fingerprint(app(ExchangeSchema::class), app(CanonicalJson::class)), 'Legacy semantic fingerprints deliberately exclude provenance.');
            $this->assertFalse($scope->allows($tampered, $target), 'Write admission pins the entire canonical envelope, including provenance.');
            $source = $artifact->source();
            $source['captured_at'] = '2000-01-01T00:00:00Z';
            $this->assertFalse($scope->allows(new Snapshot($source, $artifact->tables(), $originalProvenance), $target));
            try {
                $scope->run($actor, $snapshot, $map, $artifact, $target, fn () => throw new RuntimeException('Nested scope failed.'), fn () => $artifact);
            } catch (RuntimeException) {
                $this->assertTrue($scope->allows($artifact, $target), 'A nested failure restores the outer exact admission.');
            }
        }, fn () => $artifact);
        $this->assertFalse($scope->allows($artifact, $target));
        $this->assertSame(0, $target->table('site_builder_import_runs')->count());
    }
}
