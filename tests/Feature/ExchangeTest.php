<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Exchange\Exchange;
use SiteBuilder\Core\Exchange\ExchangeSchema;
use SiteBuilder\Core\Exchange\LegacyDocuments;
use SiteBuilder\Core\Exchange\LegacyRows;
use SiteBuilder\Core\Exchange\Snapshot;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;

class ExchangeTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Europe/Zurich', 'site-builder.locales' => ['en' => [], 'de' => []]]);
        app(ComponentRegistry::class)->register(RenderBlock::class);
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
    }

    private function legacy(array $changes = []): string
    {
        $tree = '[{"id":"stable","type":"test.block","attributes":{"heading":{"en":"Live","de":null},"body":{"en":"<p>Text</p>"},"items":{"en":[]},"tone":"brand"},"children":[],"hidden":false}]';
        $tables = ['atelier_pages' => [array_replace(['id' => 7, 'title' => 'Stored title', 'type' => 'page', 'status' => 'published', 'layout' => null, 'draft_content' => str_replace('Live', 'Private draft', $tree), 'published_content' => $tree, 'seo' => '{"en":{"meta_title":"SEO"}}', 'schema' => null, 'data' => '{"empty":{},"list":[],"nothing":null}', 'published_at' => '2026-11-05 12:00:00', 'created_at' => '2026-01-01 09:00:00', 'updated_at' => '2026-11-05 12:00:00'], $changes)],
            'atelier_page_slugs' => [['id' => 9, 'page_id' => 7, 'locale' => 'en', 'slug' => 'example', 'created_at' => null, 'updated_at' => null]],
            'atelier_page_redirects' => [['id' => 8, 'page_id' => 7, 'locale' => 'en', 'from_slug' => 'old-example', 'status' => 301, 'created_at' => null, 'updated_at' => null]],
            'atelier_page_revisions' => [['id' => 11, 'page_id' => 7, 'content' => $tree, 'created_by' => 45, 'label' => 'Historic', 'created_at' => '2026-11-04 09:30:00']],
            'atelier_settings' => [['id' => 1, 'data' => '{"name":"Original"}', 'created_at' => null, 'updated_at' => null]], 'atelier_menus' => []];

        return json_encode(['format' => LegacyRows::FORMAT, 'version' => 1, 'source' => ['namespace' => 'fixture', 'application_timezone' => 'Europe/Zurich', 'locales' => ['en', 'de']], 'tables' => $tables], JSON_THROW_ON_ERROR);
    }

    private function snapshot(array $changes = []): Snapshot
    {
        return app(LegacyRows::class)->convert($this->legacy($changes));
    }

    private function codes(array $report): array
    {
        return array_column($report['diagnostics'], 'code');
    }

    public function test_dry_run_import_repeat_and_native_export_preserve_rows_and_provenance(): void
    {
        $exchange = app(Exchange::class);
        $snapshot = $this->snapshot();
        $dry = $exchange->import($snapshot, DB::connection(), true);
        $this->assertTrue($dry['cutover_eligible'], json_encode($dry['diagnostics']));
        $this->assertSame(5, $dry['counts']['create']);
        $this->assertSame(0, DB::table('site_builder_pages')->count());
        $first = $exchange->import(Snapshot::decode($snapshot->encode()), DB::connection());
        $this->assertSame('imported', $first['status']);
        $page = DB::table('site_builder_pages')->first();
        $this->assertSame(7, (int) $page->id);
        $this->assertNotSame($page->draft_content, $page->published_content);
        $this->assertSame('2026-11-05 12:00:00', $page->published_at);
        $revision = DB::table('site_builder_page_revisions')->first();
        $this->assertSame(45, (int) $revision->created_by);
        $this->assertSame('Historic', $revision->label);
        $this->assertSame('legacy', $revision->origin);
        $this->assertNull($revision->published_from_version);
        $this->assertSame(0, DB::table('site_builder_page_locks')->count());
        $this->assertSame(5, DB::table('site_builder_import_map')->count());
        $this->assertSame('unchanged', $exchange->import($snapshot, DB::connection())['status']);
        $this->assertSame(1, DB::table('site_builder_import_runs')->count());
        $export = $exchange->export(DB::connection(), 'native-copy');
        $this->assertSame(array_keys(app(ExchangeSchema::class)->tables()), array_keys($export->tables()));
        $this->assertNotContains('users', array_keys($export->tables()));
        $this->assertSame('Europe/Zurich', $export->source()['application_timezone']);
        $this->assertSame('rejected', $exchange->import($export, DB::connection())['status']);
        $this->assertContains('source_equals_target', $this->codes($exchange->plan($export, DB::connection())));
        $reverse = app(LegacyRows::class)->reverse($export);
        $original = json_decode($this->legacy(), true)['tables'];
        foreach ($original as $table => $rows) {
            foreach ($rows as $index => $row) {
                foreach ($row as $column => $value) {
                    $actual = $reverse[$table][$index][$column];
                    if (in_array($column, ['draft_content', 'published_content', 'content', 'seo', 'data'], true) && $value !== null) {
                        $this->assertTrue(app(CanonicalJson::class)->equals(json_decode($value), json_decode($actual)), $table.'.'.$column);
                    } else {
                        $this->assertEquals($value, $actual, $table.'.'.$column);
                    }
                }
            }
        }
    }

    public function test_changed_source_target_deletion_and_occupied_ids_are_never_overwritten(): void
    {
        $exchange = app(Exchange::class);
        $snapshot = $this->snapshot();
        $exchange->import($snapshot, DB::connection());
        $this->assertContains('source_changed', $this->codes($exchange->import($this->snapshot(['title' => 'Changed source']), DB::connection())));
        $this->assertSame('Stored title', DB::table('site_builder_pages')->value('title'));
        DB::table('site_builder_pages')->update(['title' => 'New editor work', 'write_version' => 1]);
        $this->assertContains('target_changed', $this->codes($exchange->import($snapshot, DB::connection())));
        $this->assertSame('New editor work', DB::table('site_builder_pages')->value('title'));
        $tables = $snapshot->tables();
        $tables['redirects'] = [];
        $missing = new Snapshot($snapshot->source(), $tables);
        $this->assertContains('source_entity_missing', $this->codes($exchange->plan($missing, DB::connection())));
        $other = new Snapshot(array_replace($snapshot->source(), ['namespace' => 'other']), $snapshot->tables());
        $this->assertContains('occupied_target_identity', $this->codes($exchange->plan($other, DB::connection())));
        $this->assertSame(1, DB::table('site_builder_import_runs')->count());
    }

    public function test_unknown_data_is_retained_but_cutover_is_blocked_before_any_write(): void
    {
        $tree = '[{"id":"unknown","type":"future.component","attributes":{"opaque":{"a":[],"b":{},"c":null}},"futureFlag":42}]';
        $snapshot = $this->snapshot(['draft_content' => $tree]);
        $converted = $snapshot->tables()['pages'][0]['draft_content'];
        $this->assertStringContainsString('future.component', $converted);
        $this->assertStringContainsString('futureFlag', $converted);
        $report = app(Exchange::class)->import($snapshot, DB::connection());
        $this->assertContains('unknown_component', $this->codes($report));
        $this->assertContains('unreviewed_legacy_node_fields', $this->codes($report));
        $this->assertSame(0, DB::table('site_builder_pages')->count());
        $this->assertSame(0, DB::table('site_builder_import_runs')->count());
    }

    public function test_orphans_duplicate_paths_context_and_active_editors_are_reported(): void
    {
        $snapshot = $this->snapshot();
        $wrongName = new Snapshot(array_replace($snapshot->source(), ['application_name' => 'Wrong fallback identity']), $snapshot->tables());
        $this->assertContains('context_mismatch', $this->codes(app(Exchange::class)->plan($wrongName, DB::connection())));
        $tables = $snapshot->tables();
        $tables['slugs'][] = array_replace($tables['slugs'][0], ['id' => 10, 'page_id' => 999]);
        $report = app(Exchange::class)->plan(new Snapshot(array_replace($snapshot->source(), ['locales' => ['de', 'en']]), $tables), DB::connection());
        foreach (['orphan_page_reference', 'duplicate_unique_value', 'context_mismatch'] as $code) {
            $this->assertContains($code, $this->codes($report));
        }
        app(Exchange::class)->import($snapshot, DB::connection());
        DB::table('site_builder_page_locks')->insert(['page_id' => 7, 'user_id' => 1, 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(5)]);
        $this->assertContains('editing_not_frozen', $this->codes(app(Exchange::class)->plan($snapshot, DB::connection())));
    }

    public function test_native_export_import_to_another_connection_and_atomic_database_failure(): void
    {
        $snapshot = $this->snapshot();
        $tables = $snapshot->tables();
        $tables['revisions'][0]['label'] = str_repeat('x', 300);
        // A database error after earlier rows were inserted must roll back every row and provenance record.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER reject_revision BEFORE INSERT ON site_builder_page_revisions BEGIN SELECT RAISE(ABORT, 'rehearsal failure'); END");
        } else {
            DB::statement("CREATE TRIGGER reject_revision BEFORE INSERT ON site_builder_page_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rehearsal failure'");
        }
        $report = app(Exchange::class)->import(new Snapshot($snapshot->source(), $tables), DB::connection());
        $this->assertSame('failed', $report['status']);
        foreach (['pages', 'page_slugs', 'page_revisions', 'import_runs', 'import_map'] as $table) {
            $this->assertSame(0, DB::table('site_builder_'.$table)->count());
        }
        DB::statement('DROP TRIGGER reject_revision');
    }

    public function test_native_export_imports_into_a_fresh_database_without_a_legacy_package(): void
    {
        $exchange = app(Exchange::class);
        $exchange->import($this->snapshot(), DB::connection());
        $export = $exchange->export(DB::connection(), 'native-source');
        config(['database.connections.exchange_copy' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        $this->artisan('migrate', $this->migrationOptions() + ['--database' => 'exchange_copy'])->assertExitCode(0);
        $copy = DB::connection('exchange_copy');
        $report = $exchange->import(Snapshot::decode($export->encode()), $copy);
        $this->assertSame('imported', $report['status'], json_encode($report['diagnostics']));
        foreach ($export->tables() as $kind => $rows) {
            $schema = app(ExchangeSchema::class);
            foreach ($rows as $row) {
                $actual = (array) $copy->table($schema->table($kind)['table'])->where('id', $row['id'])->first();
                $this->assertSame($schema->digest($kind, $row), $schema->digest($kind, $actual));
            }
        }
        $this->assertSame('unchanged', $exchange->import($export, $copy)['status']);
        DB::purge('exchange_copy');
    }

    public function test_plain_row_capture_uses_only_its_declared_tables_and_leaves_them_unchanged(): void
    {
        $raw = json_decode($this->legacy(), true)['tables'];
        $schema = DB::connection()->getSchemaBuilder();
        foreach ($raw as $table => $rows) {
            $columns = $rows === [] ? ['id', 'location', 'items', 'created_at', 'updated_at'] : array_keys($rows[0]);
            $schema->create($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column) {
                    $blueprint->text($column)->nullable();
                }
            });
            foreach ($rows as $row) {
                DB::table($table)->insert($row);
            }
        }
        try {
            $captured = app(LegacyRows::class)->capture(DB::connection(), 'captured');
            $input = json_decode($captured, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(array_keys($raw), array_keys($input['tables']));
            $this->assertSame(1, DB::table('atelier_pages')->count());
            $this->assertSame(0, DB::table('site_builder_pages')->count());
            $this->assertSame(0, DB::connection()->transactionLevel());
            $snapshot = app(LegacyRows::class)->convert($captured);
            $this->assertContains('source_equals_target', $this->codes(app(Exchange::class)->plan($snapshot, DB::connection())));
        } finally {
            foreach (array_reverse(array_keys($raw)) as $table) {
                $schema->drop($table);
            }
        }
    }

    public function test_sql_null_json_null_empty_objects_lists_and_unknown_fields_round_trip(): void
    {
        $adapter = app(LegacyDocuments::class);
        $this->assertNull($adapter->decode(null));
        foreach (['[]', '[{"id":"a","type":"future","attributes":null,"children":null}]', '[{"id":"a","type":"future","attributes":[]}]', '[{"id":"a","type":"future","attributes":{"empty":{},"list":[],"null":null,"nested":{"future":[]}},"hidden":false,"other":{}}]', '[{"id":"a","type":"test.block","attributes":{"heading":{}},"children":[]}]'] as $raw) {
            $this->assertTrue(app(CanonicalJson::class)->equals(json_decode($raw), json_decode($adapter->encode($adapter->decode($raw)))), $raw);
        }
        $schema = app(ExchangeSchema::class);
        $this->assertNotSame($schema->digest('pages', ['id' => 1, 'data' => null]), $schema->digest('pages', ['id' => 1, 'data' => 'null']));
        $this->assertNotSame($schema->digest('pages', ['id' => 1, 'data' => '{}']), $schema->digest('pages', ['id' => 1, 'data' => '[]']));
        $this->assertSame($schema->digest('pages', ['id' => 1, 'data' => '{"b":2,"a":1}']), $schema->digest('pages', ['id' => '1', 'data' => '{"a":1,"b":2}']));
    }

    public function test_reverse_refuses_native_data_with_no_lossless_legacy_representation(): void
    {
        $adapter = app(LegacyDocuments::class);
        try {
            $adapter->encode(new Document([new Node('new', 'test.block')]));
            $this->fail('A native registration alone does not prove the old artifact can render this type.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $adapter->allowNativeTypes(['test.block']);
        $this->assertSame('test.block', json_decode($adapter->encode(new Document([new Node('new', 'test.block')])), true)[0]['type']);
        $this->expectException(InvalidArgumentException::class);
        app(LegacyDocuments::class)->encode(new Document([new Node('a', 'test.block', props: new JsonObject(['items' => (object) ['base' => []]]), translations: new JsonObject(['en' => (object) ['items' => []]]))]));
    }

    public function test_future_envelopes_unreviewed_tables_and_malformed_legacy_documents_are_refused(): void
    {
        foreach (['null', '{}', '[1]', '[{"id":"a","type":"test.block","attributes":{"heading":{"not_a_locale":"ambiguous"}}}]'] as $raw) {
            try {
                app(LegacyDocuments::class)->decode($raw);
                $this->fail('Ambiguous legacy data must not be guessed.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $input = json_decode($this->legacy(), true);
        $input['tables']['users'] = [];
        try {
            app(LegacyRows::class)->convert(json_encode($input));
            $this->fail('Accounts must not be inferred.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $input = json_decode($this->snapshot()->encode(), true);
        $input['version'] = Snapshot::VERSION + 1;
        $this->expectException(InvalidArgumentException::class);
        Snapshot::decode(json_encode($input));
    }

    public function test_cli_defaults_to_dry_run_and_refuses_report_collisions_before_writing(): void
    {
        $directory = sys_get_temp_dir().'/site-builder-exchange-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/input.json', $this->legacy());
        file_put_contents($directory.'/occupied.json', 'keep');
        try {
            $this->artisan('site-builder:import', ['path' => $directory.'/input.json', '--legacy' => true])->assertExitCode(0);
            $this->assertSame(0, DB::table('site_builder_pages')->count());
            $this->artisan('site-builder:import', ['path' => $directory.'/input.json', '--legacy' => true, '--apply' => true, '--report' => $directory.'/occupied.json'])->assertExitCode(1);
            $this->assertSame('keep', file_get_contents($directory.'/occupied.json'));
            $this->assertSame(0, DB::table('site_builder_pages')->count());
            $this->artisan('site-builder:import', ['path' => $directory.'/input.json', '--legacy' => true, '--apply' => true, '--report' => $directory.'/report.json'])->assertExitCode(0);
            $this->assertSame('imported', json_decode(file_get_contents($directory.'/report.json'), true)['status']);
            $this->artisan('site-builder:export', ['path' => $directory.'/export.json', '--source' => 'cli'])->assertExitCode(0);
            $this->assertCount(1, Snapshot::decode(file_get_contents($directory.'/export.json'))->tables()['pages']);
            $this->assertSame(0600, fileperms($directory.'/export.json') & 0777);
            $this->artisan('site-builder:export', ['path' => $directory.'/export.json', '--source' => 'cli'])->assertExitCode(1);
            file_put_contents($directory.'/bad.json', '{"format":"future"}');
            $this->artisan('site-builder:import', ['path' => $directory.'/bad.json', '--apply' => true, '--report' => $directory.'/rejected.json'])->assertExitCode(1);
            $rejected = json_decode(file_get_contents($directory.'/rejected.json'), true);
            $this->assertFalse($rejected['transaction_committed']);
            $this->assertSame('unsupported_snapshot', $rejected['diagnostics'][0]['code']);
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
