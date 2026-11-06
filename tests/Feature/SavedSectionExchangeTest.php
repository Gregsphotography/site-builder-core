<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Exchange\Exchange;
use SiteBuilder\Core\Exchange\ExchangeSchema;
use SiteBuilder\Core\Exchange\LegacyRows;
use SiteBuilder\Core\Exchange\Snapshot;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\SavedSections\SavedSectionCommands;
use SiteBuilder\Core\SavedSections\SavedSectionExchange;
use SiteBuilder\Core\Tests\Fixtures\CompositionBlock;
use SiteBuilder\Core\Tests\SavedSectionsTestCase;

class SavedSectionExchangeTest extends SavedSectionsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(ComponentRegistry::class)->register(new CompositionBlock);
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
        Gate::before(fn () => true);
    }

    private function library(): void
    {
        $actor = new GenericUser(['id' => '01JBDR03XETB5V4C2625GJ44JD']);
        $page = Page::factory()->create(['draft_content' => new Document([new Node('root', 'test.composition', props: new JsonObject(['heading' => 'Reusable']))])]);
        $lease = app(PageLocks::class)->acquire($actor, $page->id);
        $saved = app(SavedSectionCommands::class);
        $section = $saved->capture($actor, $page->id, $lease->token, 0, 'root', ['name' => 'Original', 'tags' => ['Grüße']]);
        $saved->update($actor, $section->id, 0, ['name' => 'Renamed']);
    }

    private function copy(): Connection
    {
        config(['database.connections.saved_exchange_copy' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        $this->artisan('migrate', $this->migrationOptions() + ['--database' => 'saved_exchange_copy'])->assertSuccessful();
        $this->artisan('migrate', $this->savedMigrationOptions() + ['--database' => 'saved_exchange_copy'])->assertSuccessful();

        return DB::connection('saved_exchange_copy');
    }

    public function test_base_export_is_explicitly_page_only_and_opted_in_v3_roundtrips_saved_versions_and_actor_types(): void
    {
        $this->library();
        $schema = app(ExchangeSchema::class);
        $exchange = app(Exchange::class);
        $base = $exchange->export(DB::connection(), 'page-only');
        $this->assertArrayNotHasKey('saved_sections', $base->tables());
        SavedSectionExchange::register($schema, preserveActorOwnership: true);
        $full = Snapshot::decode($exchange->export(DB::connection(), 'saved-library')->encode());
        $this->assertSame(3, $full->version());
        $this->assertCount(1, $full->tables()['saved_sections']);
        $this->assertCount(2, $full->tables()['saved_section_versions']);
        $this->assertSame('01JBDR03XETB5V4C2625GJ44JD', $full->tables()['saved_sections'][0]['owner_id']);
        $this->assertSame(GenericUser::class, $full->tables()['saved_sections'][0]['owner_type']);
        try {
            $copy = $this->copy();
            $report = $exchange->import($full, $copy);
            $this->assertSame('imported', $report['status'], json_encode($report['diagnostics']));
            $this->assertSame(2, (int) $copy->table('site_builder_saved_sections')->value('current_version'));
            $this->assertSame(2, $copy->table('site_builder_saved_section_versions')->count());
            $this->assertSame('unchanged', $exchange->import($full, $copy)['status']);
            $this->assertFalse($copy->getSchemaBuilder()->hasTable('users'));
            $this->assertSame(0, $copy->table('site_builder_page_locks')->count());
            foreach (['saved_sections', 'saved_section_versions'] as $kind) {
                foreach ($full->tables()[$kind] as $row) {
                    $actual = (array) $copy->table($schema->table($kind)['table'])->where('id', $row['id'])->first();
                    $this->assertSame($schema->digest($kind, $row), $schema->digest($kind, $actual));
                }
            }
        } finally {
            DB::purge('saved_exchange_copy');
        }
        $this->expectException(InvalidArgumentException::class);
        app(LegacyRows::class)->reverse($full);
    }

    public function test_opt_in_config_registers_dataset_without_boot_time_schema_or_content_writes(): void
    {
        config(['site-builder.saved_sections.exchange' => true]);
        $schema = app(ExchangeSchema::class);
        $this->assertSame('uuid', $schema->table('saved_sections')['identity']);
        $this->assertSame(0, DB::table('site_builder_saved_sections')->count());
        $this->assertSame(0, DB::table('site_builder_saved_section_versions')->count());
    }

    public function test_retained_saved_document_references_and_digest_changes_block_before_import(): void
    {
        $this->library();
        SavedSectionExchange::register(app(ExchangeSchema::class), preserveActorOwnership: true);
        $snapshot = app(Exchange::class)->export(DB::connection(), 'invalid-saved-source');
        $tables = $snapshot->tables();
        $document = new Document([new Node('root', 'test.composition', props: new JsonObject(['target' => 99999]))]);
        $tables['saved_section_versions'][0]['document'] = app(DocumentCodec::class)->encode($document);
        $tables['saved_section_versions'][0]['digest'] = app(CanonicalJson::class)->digest($document);
        try {
            $copy = $this->copy();
            $report = app(Exchange::class)->import(new Snapshot($snapshot->source(), $tables), $copy);
            $this->assertContains('dangling_saved_page_reference', array_column($report['diagnostics'], 'code'));
            $this->assertSame(0, $copy->table('site_builder_pages')->count());
            $tables['saved_section_versions'][0]['digest'] = str_repeat('0', 64);
            $report = app(Exchange::class)->plan(new Snapshot($snapshot->source(), $tables), $copy);
            $this->assertContains('saved_section_digest_changed', array_column($report['diagnostics'], 'code'));
        } finally {
            DB::purge('saved_exchange_copy');
        }
    }

    public function test_forced_saved_version_insert_failure_rolls_back_pages_resources_and_import_provenance(): void
    {
        $this->library();
        SavedSectionExchange::register(app(ExchangeSchema::class), preserveActorOwnership: true);
        $snapshot = app(Exchange::class)->export(DB::connection(), 'atomic-saved-source');
        try {
            $copy = $this->copy();
            $copy->statement("CREATE TRIGGER reject_saved_version BEFORE INSERT ON site_builder_saved_section_versions BEGIN SELECT RAISE(ABORT, 'saved rehearsal failure'); END");
            $report = app(Exchange::class)->import($snapshot, $copy);
            $this->assertSame('failed', $report['status']);
            foreach (['site_builder_pages', 'site_builder_saved_sections', 'site_builder_saved_section_versions', 'site_builder_import_runs', 'site_builder_import_map'] as $table) {
                $this->assertSame(0, $copy->table($table)->count());
            }
        } finally {
            DB::purge('saved_exchange_copy');
        }
    }

    public function test_v2_base_snapshots_remain_readable_but_uuid_datasets_cannot_masquerade_as_v2(): void
    {
        $this->library();
        $wire = json_decode(app(Exchange::class)->export(DB::connection(), 'old-native')->encode());
        $wire->version = 2;
        $old = Snapshot::decode(json_encode($wire, JSON_THROW_ON_ERROR));
        $this->assertSame(2, $old->version());
        $this->assertSame(2, Snapshot::decode($old->encode())->version());
        SavedSectionExchange::register(app(ExchangeSchema::class));
        $wire = json_decode(app(Exchange::class)->export(DB::connection(), 'uuid-native')->encode());
        $wire->version = 2;
        $this->expectException(InvalidArgumentException::class);
        Snapshot::decode(json_encode($wire, JSON_THROW_ON_ERROR));
    }

    public function test_owner_facts_never_grant_private_destination_ownership_without_a_trusted_domain_assertion(): void
    {
        $this->library();
        SavedSectionExchange::register(app(ExchangeSchema::class));
        $snapshot = app(Exchange::class)->export(DB::connection(), 'different-identity-domain');
        try {
            $copy = $this->copy();
            $rejected = app(Exchange::class)->import($snapshot, $copy);
            $this->assertContains('saved_section_owner_mapping_required', array_column($rejected['diagnostics'], 'code'));
            $this->assertSame('unacknowledged', $rejected['metrics']['saved_section_ownership']['decision']);
            $this->assertSame(0, $copy->table('site_builder_saved_sections')->count());
            $this->assertSame(0, $copy->table('site_builder_pages')->count());
            $trustedSchema = new ExchangeSchema(app(CanonicalJson::class));
            SavedSectionExchange::register($trustedSchema, preserveActorOwnership: true);
            app()->instance(ExchangeSchema::class, $trustedSchema);
            $accepted = app(Exchange::class)->import($snapshot, $copy);
            $this->assertSame('imported', $accepted['status'], json_encode($accepted['diagnostics']));
            $this->assertSame('preserve_actor_ownership', $accepted['metrics']['saved_section_ownership']['decision']);
            $this->assertSame($snapshot->tables()['saved_sections'][0]['owner_id'], $copy->table('site_builder_saved_sections')->value('owner_id'));
        } finally {
            DB::purge('saved_exchange_copy');
        }
    }
}
