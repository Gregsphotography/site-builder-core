<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Models\ImportMap;
use SiteBuilder\Core\Models\ImportRun;
use SiteBuilder\Core\Models\Menu;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageLock;
use SiteBuilder\Core\Models\PageRedirect;
use SiteBuilder\Core\Models\PageRevision;
use SiteBuilder\Core\Models\PageSlug;
use SiteBuilder\Core\Models\Setting;
use SiteBuilder\Core\Tests\TestCase;

class SchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'mysql') {
            $this->assertStringStartsWith('8.4.', DB::selectOne('SELECT VERSION() AS version')->version);
        }
        $this->artisan('migrate', $this->migrationOptions())->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate:rollback', $this->migrationOptions())->assertExitCode(0);
        parent::tearDown();
    }

    public function test_new_schema_does_not_depend_on_a_host_users_table_and_has_safe_defaults(): void
    {
        $this->assertFalse(Schema::hasTable('users'));
        $page = Page::create(['title' => 'A new page'])->fresh();
        $this->assertSame('draft', $page->status);
        $this->assertSame('page', $page->type);
        $this->assertSame(0, $page->write_version);
        $this->assertNull($page->published_from_version);
        $this->assertNull($page->draft_content);
        $this->assertNull($page->published_content);
        $lock = PageLock::factory()->create(['page_id' => $page->id, 'user_id' => 999]);
        $this->assertSame('999', $lock->user_id);
        $this->assertArrayNotHasKey('token_hash', $lock->toArray());
        $this->assertFalse(Schema::hasColumn('site_builder_pages', 'source_key'));
    }

    public function test_independent_documents_and_json_shapes_survive_database_storage(): void
    {
        $draft = new Document([new Node('stable', 'unknown.component', props: new JsonObject(['heading' => 'Private draft']))]);
        $published = new Document([new Node('stable', 'unknown.component', props: new JsonObject(['heading' => 'Published']))]);
        $page = Page::factory()->create(['draft_content' => $draft, 'published_content' => $published, 'seo' => (object) [], 'schema' => [], 'data' => (object) ['empty' => (object) [], 'list' => [], 'null' => null]])->fresh();
        $this->assertSame('Private draft', $page->draft_content->nodes[0]->props->get('heading'));
        $this->assertSame('Published', $page->published_content->nodes[0]->props->get('heading'));
        $this->assertInstanceOf(\stdClass::class, $page->seo);
        $this->assertSame([], $page->schema);
        $this->assertInstanceOf(\stdClass::class, $page->data->empty);
        $this->assertSame([], $page->data->list);
        $this->assertNull($page->data->null);
        $this->assertTrue(app(CanonicalJson::class)->equals($draft, $page->draft_content));
        $page->forceFill(['draft_content' => null])->save();
        $this->assertNull($page->fresh()->draft_content);
        $this->assertSame('Published', $page->fresh()->published_content->nodes[0]->props->get('heading'));
    }

    public function test_historical_content_revision_retains_null_authorship_and_original_time(): void
    {
        $revision = PageRevision::factory()->create(['created_by' => null, 'label' => null, 'created_at' => '2020-03-04 05:06:07', 'origin' => 'legacy', 'published_from_version' => null])->fresh();
        $this->assertSame('content', $revision->scope);
        $this->assertNull($revision->created_by);
        $this->assertNull($revision->label);
        $this->assertNull($revision->published_from_version);
        $this->assertSame('2020-03-04 05:06:07', $revision->created_at->format('Y-m-d H:i:s'));
        $this->assertArrayNotHasKey('updated_at', $revision->getAttributes());
    }

    #[DataProvider('slugConflicts')]
    public function test_slug_uniqueness_is_enforced_for_both_pairs(string $conflict): void
    {
        $first = PageSlug::factory()->create(['locale' => 'en', 'slug' => 'unique-path']);
        $this->expectException(QueryException::class);
        PageSlug::factory()->create($conflict === 'locale-and-slug'
            ? ['locale' => 'en', 'slug' => 'unique-path']
            : ['page_id' => $first->page_id, 'locale' => 'en', 'slug' => 'other-path']);
    }

    public static function slugConflicts(): array
    {
        return [['locale-and-slug'], ['page-and-locale']];
    }

    public function test_page_foreign_keys_prevent_dangling_references(): void
    {
        $this->expectException(QueryException::class);
        PageSlug::factory()->create(['page_id' => 999999]);
    }

    public function test_page_delete_cascades_to_only_its_dependent_rows(): void
    {
        $page = Page::factory()->create();
        PageSlug::factory()->create(['page_id' => $page->id]);
        PageRedirect::factory()->create(['page_id' => $page->id]);
        PageRevision::factory()->create(['page_id' => $page->id]);
        PageLock::factory()->create(['page_id' => $page->id]);
        $other = Page::factory()->create();
        $otherSlug = PageSlug::factory()->create(['page_id' => $other->id]);
        $page->delete();
        foreach (['page_slugs', 'page_redirects', 'page_revisions', 'page_locks'] as $table) {
            $this->assertSame(0, DB::table('site_builder_'.$table)->where('page_id', $page->id)->count());
        }
        $this->assertModelExists($otherSlug);
    }

    public function test_only_one_lock_row_can_exist_per_page(): void
    {
        $lock = PageLock::factory()->create();
        $this->expectException(QueryException::class);
        PageLock::factory()->create(['page_id' => $lock->page_id]);
    }

    public function test_neutral_stores_keep_empty_json_shapes_and_start_revision_at_zero(): void
    {
        $setting = Setting::factory()->create(['data' => (object) []])->fresh();
        $menu = Menu::factory()->create(['items' => []])->fresh();
        $this->assertInstanceOf(\stdClass::class, $setting->data);
        $this->assertSame([], $menu->items);
        $this->assertSame(0, $setting->revision);
        $this->assertSame(0, $menu->revision);
    }

    public function test_import_identity_is_unique_and_run_provenance_cannot_be_deleted_while_referenced(): void
    {
        $map = ImportMap::factory()->create();
        try {
            ImportMap::factory()->create(['source' => $map->source, 'kind' => $map->kind, 'source_id' => $map->source_id]);
            $this->fail('Duplicate import identities must be rejected.');
        } catch (QueryException) {
            $this->assertSame(1, ImportMap::count());
        }
        $this->expectException(QueryException::class);
        ImportRun::findOrFail($map->run_id)->delete();
    }

    public function test_migration_rolls_back_and_reapplies_cleanly(): void
    {
        Page::factory()->create();
        $this->artisan('migrate:rollback', $this->migrationOptions())->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('site_builder_pages'));
        $this->assertFalse(Schema::hasTable('site_builder_import_map'));
        $this->artisan('migrate', $this->migrationOptions())->assertExitCode(0);
        $this->assertSame(0, Page::count());
    }

    private function migrationOptions(): array
    {
        return ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true, '--force' => true];
    }
}
