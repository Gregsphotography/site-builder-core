<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Tests\Fixtures\PortalUser;
use SiteBuilder\Core\Tests\TestCase;

class ActorMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__.'/../../database/migrations/0001_01_01_000100_create_site_builder_tables.php')->up();
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();
        parent::tearDown();
    }

    private function upgrade(): object
    {
        return require __DIR__.'/../../database/migrations/0001_01_01_000200_support_opaque_actor_identities.php';
    }

    public function test_upgrade_preserves_old_authors_timestamps_and_live_lease_without_guessing_their_model(): void
    {
        $page = Page::factory()->create();
        $token = (string) Str::uuid();
        $expiry = now()->addMinutes(20)->format('Y-m-d H:i:s');
        DB::table('site_builder_page_revisions')->insert([
            ['page_id' => $page->id, 'content' => null, 'created_by' => 7, 'label' => 'Historic', 'created_at' => '2020-03-04 05:06:07', 'origin' => 'legacy'],
            ['page_id' => $page->id, 'content' => null, 'created_by' => null, 'label' => null, 'created_at' => null, 'origin' => 'legacy'],
            ['page_id' => $page->id, 'content' => null, 'created_by' => 0, 'label' => 'Historical zero', 'created_at' => null, 'origin' => 'legacy'],
            ['page_id' => $page->id, 'content' => null, 'created_by' => '9007199254740993', 'label' => 'Bigint', 'created_at' => null, 'origin' => 'legacy'],
        ]);
        DB::table('site_builder_page_locks')->insert(['page_id' => $page->id, 'user_id' => 7, 'token_hash' => hash('sha256', $token), 'expires_at' => $expiry, 'created_at' => '2020-03-04 05:06:07']);
        $this->upgrade()->up();
        $authors = DB::table('site_builder_page_revisions')->orderBy('id')->get();
        $this->assertSame('7', (string) $authors[0]->created_by);
        $this->assertSame('2020-03-04 05:06:07', $authors[0]->created_at);
        $this->assertNull($authors[0]->created_by_type);
        $this->assertNull($authors[1]->created_by);
        $this->assertNull($authors[1]->created_at);
        $this->assertSame('0', (string) $authors[2]->created_by);
        $this->assertSame('9007199254740993', (string) $authors[3]->created_by);
        $lock = $page->editLock()->firstOrFail();
        $this->assertSame('7', $lock->user_id);
        $this->assertSame($expiry, $lock->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(hash('sha256', $token), $lock->token_hash);
        $this->assertNull($lock->user_type);
        config(['site-builder.auth.legacy_actor_type' => PortalUser::class]);
        Gate::before(fn () => true);
        try {
            app(PageLocks::class)->renew(new GenericUser(['id' => 7]), $page->id, $token);
            $this->fail('A different model must not claim a pre-upgrade lease.');
        } catch (WriteConflict) {
            $this->assertNull($lock->fresh()->user_type);
        }
        app(PageLocks::class)->renew(new PortalUser(['id' => '7']), $page->id, $token);
        $this->assertSame(PortalUser::class, $lock->fresh()->user_type);
        $this->assertSame(hash('sha256', $token), $lock->fresh()->token_hash);
    }

    public function test_numeric_legacy_rows_can_downgrade_without_losing_unknown_authorship(): void
    {
        $page = Page::factory()->create();
        DB::table('site_builder_page_revisions')->insert(['page_id' => $page->id, 'created_by' => 7, 'created_at' => '2020-03-04 05:06:07']);
        $migration = $this->upgrade();
        $migration->up();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('site_builder_page_revisions', 'created_by_type'));
        $row = DB::table('site_builder_page_revisions')->sole();
        $this->assertSame('7', (string) $row->created_by);
        $this->assertSame('2020-03-04 05:06:07', $row->created_at);
    }

    public static function unsupportedDowngrades(): array
    {
        return [['715f202e-74f0-4ea7-9255-3ade68307741', null], ['7', PortalUser::class], ['01JBDR03XETB5V4C2625GJ44JD', PortalUser::class]];
    }

    public function test_downgrade_respects_each_databases_exact_bigint_range(): void
    {
        $migration = $this->upgrade();
        $migration->up();
        $page = Page::factory()->create();
        $maximum = '18446744073709551615';
        DB::table('site_builder_page_revisions')->insert(['page_id' => $page->id, 'created_by' => $maximum, 'created_by_type' => null]);
        if (DB::getDriverName() === 'mysql') {
            $migration->down();
            $this->assertSame($maximum, (string) DB::table('site_builder_page_revisions')->value('created_by'));
        } else {
            try {
                $migration->down();
                $this->fail('SQLite must never turn an out-of-range bigint actor into a floating-point value.');
            } catch (RuntimeException) {
                $this->assertTrue(Schema::hasColumn('site_builder_page_revisions', 'created_by_type'));
                $this->assertSame($maximum, DB::table('site_builder_page_revisions')->value('created_by'));
            }
        }
    }

    #[DataProvider('unsupportedDowngrades')]
    public function test_downgrade_refuses_opaque_or_scoped_authors_before_any_schema_or_data_changes(string $id, ?string $type): void
    {
        $migration = $this->upgrade();
        $migration->up();
        $page = Page::factory()->create();
        DB::table('site_builder_page_revisions')->insert(['page_id' => $page->id, 'created_by' => $id, 'created_by_type' => $type]);
        try {
            $migration->down();
            $this->fail('An inexpressible actor must never be coerced into an old numeric identity.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('reviewed data migration', $exception->getMessage());
            $this->assertTrue(Schema::hasColumn('site_builder_page_locks', 'user_type'));
            $this->assertTrue(Schema::hasColumn('site_builder_page_revisions', 'created_by_type'));
            $this->assertSame($id, DB::table('site_builder_page_revisions')->value('created_by'));
            $this->assertSame($type, DB::table('site_builder_page_revisions')->value('created_by_type'));
        }
    }
}
