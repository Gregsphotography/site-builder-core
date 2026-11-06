<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Exchange\Exchange;
use SiteBuilder\Core\Exchange\ExchangeSchema;
use SiteBuilder\Core\Exchange\LegacyRows;
use SiteBuilder\Core\Exchange\Snapshot;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageRevision;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\PortalUser;

class OpaqueExchangeTest extends DatabaseTestCase
{
    public function test_v2_round_trips_uuid_and_model_discriminators_without_host_user_tables(): void
    {
        Gate::before(fn () => true);
        $actor = new PortalUser(['id' => '715f202e-74f0-4ea7-9255-3ade68307741']);
        $created = app(PageCommands::class)->create($actor, ['title' => 'Portable'], ['en' => 'portable'], new Document);
        $lease = app(PageLocks::class)->acquire($actor, $created->page->id);
        app(PageCommands::class)->publish($actor, $created->page->id, $lease->token, 0, $created->draftDigest);
        $snapshot = app(Exchange::class)->export(DB::connection(), 'opaque-source');
        $this->assertSame(3, $snapshot->version());
        $decoded = Snapshot::decode($snapshot->encode());
        $author = $decoded->tables()['revisions'][0];
        $this->assertSame($actor->id, $author['created_by']);
        $this->assertSame(PortalUser::class, $author['created_by_type']);
        config(['database.connections.opaque_copy' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        try {
            $this->artisan('migrate', $this->migrationOptions() + ['--database' => 'opaque_copy'])->assertSuccessful();
            $copy = DB::connection('opaque_copy');
            $report = app(Exchange::class)->import($decoded, $copy);
            $this->assertSame('imported', $report['status'], json_encode($report['diagnostics']));
            $this->assertSame($actor->id, $copy->table('site_builder_page_revisions')->value('created_by'));
            $this->assertSame(PortalUser::class, $copy->table('site_builder_page_revisions')->value('created_by_type'));
            $this->assertSame('unchanged', app(Exchange::class)->import($decoded, $copy)['status']);
            $this->assertSame(3, (int) $copy->table('site_builder_import_runs')->value('format_version'));
            $this->assertFalse($copy->getSchemaBuilder()->hasTable('users'));
        } finally {
            DB::purge('opaque_copy');
        }
        app(LegacyRows::class)->allowActorType(PortalUser::class);
        $this->expectException(InvalidArgumentException::class);
        app(LegacyRows::class)->reverse($snapshot);
    }

    public function test_legacy_reverse_requires_one_explicit_numeric_actor_domain(): void
    {
        $page = Page::factory()->create(['draft_content' => new Document]);
        PageRevision::factory()->create(['page_id' => $page->id, 'created_by' => '7', 'created_by_type' => PortalUser::class]);
        $snapshot = app(Exchange::class)->export(DB::connection(), 'numeric-domain');
        $legacy = app(LegacyRows::class);
        try {
            $legacy->reverse($snapshot);
            $this->fail('A native model discriminator cannot be guessed into a legacy actor domain.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $rows = $legacy->allowActorType(PortalUser::class)->reverse($snapshot);
        $this->assertSame('7', $rows['atelier_page_revisions'][0]['created_by']);
        $this->assertArrayNotHasKey('created_by_type', $rows['atelier_page_revisions'][0]);
        $this->expectException(InvalidArgumentException::class);
        $legacy->allowActorType(GenericUser::class);
    }

    public function test_legacy_reverse_refuses_bigint_overflow_without_coercing_the_author(): void
    {
        $page = Page::factory()->create(['draft_content' => new Document]);
        PageRevision::factory()->create(['page_id' => $page->id, 'created_by' => '18446744073709551616', 'created_by_type' => null]);
        $snapshot = app(Exchange::class)->export(DB::connection(), 'numeric-overflow');
        $this->expectException(InvalidArgumentException::class);
        app(LegacyRows::class)->reverse($snapshot);
    }

    public function test_v1_numeric_snapshots_keep_their_envelope_fingerprint_and_revision_digest_after_upgrade(): void
    {
        $page = Page::factory()->create(['draft_content' => new Document]);
        PageRevision::factory()->create(['page_id' => $page->id, 'created_by' => '7', 'created_by_type' => null]);
        $snapshot = app(Exchange::class)->export(DB::connection(), 'legacy-native');
        $wire = json_decode($snapshot->encode(), true);
        $wire['version'] = 1;
        unset($wire['tables']['revisions'][0]['created_by_type']);
        $wire['provenance'] = (object) [];
        $wire['extensions'] = (object) [];
        $originalRow = $wire['tables']['revisions'][0];
        $schema = app(ExchangeSchema::class);
        $rowDigests = [];
        foreach ($wire['tables'] as $kind => $rows) {
            foreach ($rows as $row) {
                $rowDigests[$kind][(string) $row['id']] = $schema->digest($kind, $row);
            }
        }
        $source = $wire['source'];
        unset($source['captured_at']);
        $expectedFingerprint = app(CanonicalJson::class)->digest(['format' => Snapshot::FORMAT, 'version' => 1, 'source' => $source, 'rows' => $rowDigests, 'extensions' => (object) []]);
        $decoded = Snapshot::decode(json_encode($wire, JSON_THROW_ON_ERROR));
        $this->assertSame(1, $decoded->version());
        $this->assertNull($decoded->tables()['revisions'][0]['created_by_type']);
        $this->assertSame($schema->digest('revisions', $originalRow), $schema->digest('revisions', $decoded->tables()['revisions'][0]));
        $this->assertSame($expectedFingerprint, $decoded->fingerprint($schema, app(CanonicalJson::class)));
        $this->assertSame(json_decode(json_encode($wire), true), json_decode($decoded->encode(), true));
        $this->assertSame($decoded->fingerprint($schema, app(CanonicalJson::class)), Snapshot::decode($decoded->encode())->fingerprint($schema, app(CanonicalJson::class)));
        config(['database.connections.v1_copy' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        try {
            $this->artisan('migrate', $this->migrationOptions() + ['--database' => 'v1_copy'])->assertSuccessful();
            $copy = DB::connection('v1_copy');
            $report = app(Exchange::class)->import($decoded, $copy);
            $this->assertSame('imported', $report['status'], json_encode($report['diagnostics']));
            $this->assertSame(1, (int) $copy->table('site_builder_import_runs')->value('format_version'));
            $this->assertSame('7', $copy->table('site_builder_page_revisions')->value('created_by'));
            $this->assertNull($copy->table('site_builder_page_revisions')->value('created_by_type'));
        } finally {
            DB::purge('v1_copy');
        }
    }
}
