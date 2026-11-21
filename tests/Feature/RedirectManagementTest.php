<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Exchange\Exchange;
use SiteBuilder\Core\Models\Menu;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageRedirect;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageIncomingLinks;
use SiteBuilder\Core\Routing\ManagedRedirects;
use SiteBuilder\Core\Tests\DatabaseTestCase;

final class RedirectManagementTest extends DatabaseTestCase
{
    private GenericUser $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = new GenericUser(['id' => 1]);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('r', 32)), 'site-builder.locales' => ['en' => [], 'de' => []]]);
        Gate::before(fn () => true);
    }

    private function published(string $slug): Page
    {
        $page = Page::factory()->create(['status' => 'published', 'published_content' => new Document]);
        $page->slugs()->create(['locale' => 'de', 'slug' => $slug]);

        return $page;
    }

    public function test_unknown_builder_paths_are_aggregated_by_locale_and_capped_without_request_details(): void
    {
        config(['site-builder.missing_paths.max_paths' => 2, 'site-builder.missing_paths.retention_days' => 2]);
        $this->get('/de/kontakt-alt?email=person@example.test', ['User-Agent' => 'private-agent', 'Referer' => 'https://private.example/'])->assertNotFound();
        $this->get('/de/kontakt-alt?key=secret')->assertNotFound();
        foreach (['/auth/token-value', '/password/reset/secret', '/address/name%40example.test', '/admin/unknown', '/assets/logo.png', '/public/123456789', '/0123456789abcdef0123456789abcdef', '/preview?signature=private'] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->getJson('/json-only')->assertNotFound();
        $this->call('HEAD', '/head-only')->assertNotFound();
        $this->get('/en/kontakt-alt')->assertNotFound();
        $this->get('/exceeds-limit')->assertNotFound();
        $table = DB::table('site_builder_missing_paths');
        $this->assertSame(2, $table->count());
        $german = (clone $table)->where('locale', 'de')->first();
        $this->assertSame('kontakt-alt', $german->slug);
        $this->assertSame(2, (int) $german->hits);
        $this->assertEqualsCanonicalizing(['id', 'locale', 'slug', 'hits', 'last_seen_at'], array_keys((array) $german));
        $table->update(['last_seen_at' => now()->subDays(3)]);
        $this->get('/after-expiry')->assertNotFound();
        $this->assertSame('after-expiry', $table->sole()->slug);
        config(['site-builder.missing_paths.enabled' => false]);
        $this->get('/disabled')->assertNotFound();
        $this->assertSame(1, $table->count());
        app(PageCommands::class)->create($this->actor, ['title' => 'Reclaimed URL'], ['en' => 'after-expiry']);
        $this->assertSame(0, $table->count());
    }

    public function test_only_unknown_public_slugs_are_recorded_and_host_or_private_paths_do_not_enter_the_report(): void
    {
        config(['site-builder.routes.prefix' => 'pages']);
        Route::get('/pages/host-page', fn () => abort(404))->name('host.missing');
        $target = $this->published('ziel');
        $target->forceFill(['status' => 'draft'])->save();
        $target->redirects()->create(['from_slug' => 'former', 'locale' => 'de', 'status' => 301]);
        foreach (['/outside', '/pages/host-page', '/pages/de/ziel', '/pages/de/former', '/_site-builder/preview/1/de'] as $url) {
            $response = $this->get($url);
            $this->assertContains($response->getStatusCode(), [403, 404]);
        }
        $this->get('/pages/de/unbekannt')->assertNotFound();
        $this->assertSame('unbekannt', DB::table('site_builder_missing_paths')->sole()->slug);
    }

    public function test_legacy_filenames_resolve_to_live_translations_and_their_stale_edits_are_rejected(): void
    {
        config(['site-builder.routes.prefix' => 'pages']);
        $target = $this->published('dienst');
        $this->get('/pages/de/archiv/alt.html?private=x')->assertNotFound();
        $service = app(ManagedRedirects::class);
        $rule = $service->save($this->actor, 'de', '/archiv/alt.html', $target->id, 302);
        $this->assertSame(0, DB::table('site_builder_missing_paths')->count());
        $this->get('/pages/de/archiv/alt.html?private=x')->assertRedirect('/pages/de/dienst')->assertStatus(302);
        foreach ([['en', 'other', 301], ['de', '../secret', 301], ['de', 'https://elsewhere.test/a', 301], ['de', '/de/path', 301], ['de', 'dienst', 301], ['de', 'archiv/alt.html', 301], ['de', 'legal', 3080]] as [$locale, $source, $status]) {
            try {
                $service->save($this->actor, $locale, $source, $target->id, $status);
                $this->fail('An invalid or duplicate mapping was accepted.');
            } catch (ValidationException) {
                $this->assertSame(1, PageRedirect::query()->count());
            }
        }
        $version = ManagedRedirects::version($rule);
        $updated = $service->save($this->actor, 'de', 'archiv/alt.html', $target->id, 308, $rule->id, $version);
        try {
            $service->remove($this->actor, $rule->id, $version);
            $this->fail('A stale deletion removed a changed redirect.');
        } catch (ValidationException) {
            $this->get('/pages/de/archiv/alt.html')->assertRedirect('/pages/de/dienst')->assertStatus(308);
        }

        $target->forceFill(['status' => 'draft'])->save();
        $this->get('/pages/de/archiv/alt.html')->assertNotFound();
        $service->remove($this->actor, $rule->id, ManagedRedirects::version($updated));
        $this->assertSame(0, PageRedirect::query()->count());
    }

    public function test_host_owned_source_is_rejected_and_incoming_links_include_historical_filenames(): void
    {
        Route::get('/de/host', fn () => 'Owned')->name('host.owned');
        $target = $this->published('seite');
        $service = app(ManagedRedirects::class);
        try {
            $service->save($this->actor, 'de', 'host', $target->id, 301);
            $this->fail('A host route was mapped.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source', $exception->errors());
        }
        $service->save($this->actor, 'de', 'frueher.php', $target->id, 301);
        Menu::create(['location' => 'primary', 'items' => ['de' => [['url' => '/de/frueher.php', 'label' => 'Old link']]]]);
        $this->assertContains('primary', array_column(app(PageIncomingLinks::class)->report($this->actor, $target), 'name'));
    }

    public function test_native_backup_accepts_historical_redirect_filenames(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->markTestSkipped('Native exchange supports SQLite and MySQL.');
        }
        $target = $this->published('seite');
        app(ManagedRedirects::class)->save($this->actor, 'de', 'frueher.php', $target->id, 301);
        $backup = app(Exchange::class)->export(DB::connection(), 'redirect-test');
        $this->assertSame('frueher.php', $backup->tables()['redirects'][0]['from_slug']);
        $report = app(Exchange::class)->plan($backup, DB::connection());
        $this->assertNotContains('invalid_locale_or_path', array_column($report['diagnostics'], 'code'));
    }
}
