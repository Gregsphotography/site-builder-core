<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageRedirect;
use SiteBuilder\Core\Models\Setting;
use SiteBuilder\Core\Rendering\LayoutRegistry;
use SiteBuilder\Core\Routing\PageUrlResolver;
use SiteBuilder\Core\Routing\PreviewLinks;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;

class PublicPagesTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['site-builder.locales' => ['en' => ['dir' => 'ltr'], 'de' => [], 'fr' => []], 'app.key' => 'base64:'.base64_encode(str_repeat('c', 32))]);
        app(ComponentRegistry::class)->register(RenderBlock::class);
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
    }

    private function page(array $slugs = ['en' => 'home'], array $attributes = []): Page
    {
        $page = Page::factory()->create(array_merge(['title' => 'Shared title', 'status' => 'published', 'published_at' => '2026-11-05 12:00:00', 'draft_content' => $this->document('Private draft'), 'published_content' => $this->document('Live page')], $attributes));
        foreach ($slugs as $locale => $slug) {
            $page->slugs()->create(['locale' => $locale, 'slug' => $slug]);
        }

        return $page;
    }

    private function document(string $heading): Document
    {
        return new Document([new Node('hero', 'test.block', props: new JsonObject(['heading' => $heading, 'body' => '<p>Safe <strong>content</strong></p>'])), new Node('hidden', 'test.block', props: new JsonObject(['heading' => 'Hidden graph and body']), visibility: 'hidden')]);
    }

    private function graph(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $json = (new DOMXPath($dom))->query('//script[@type="application/ld+json"]')->item(0)->textContent;

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_public_urls_use_only_slug_rows_support_nested_paths_home_aliases_and_legacy_default_prefix(): void
    {
        $home = $this->page(['en' => 'home', 'de' => 'home']);
        $nested = $this->page(['en' => 'guides/getting-started']);
        $urls = app(PageUrlResolver::class);
        $this->assertSame('/', $urls->path($home, 'en'));
        $this->assertSame('/de', $urls->path($home, 'de'));
        $this->assertNull($urls->path($home, 'fr'));
        $this->get('/')->assertOk()->assertSee('Live page')->assertDontSee('Private draft')->assertDontSee('Hidden graph and body')->assertDontSee('preview.js')->assertDontSee('livewire');
        $this->get('/home')->assertRedirect('/')->assertStatus(301);
        $this->get('/en')->assertRedirect('/')->assertStatus(301);
        $this->get('/en/home')->assertRedirect('/')->assertStatus(301);
        $this->get('/de')->assertOk()->assertSee('lang="de"', false);
        $this->get('/de/home')->assertRedirect('/de')->assertStatus(301);
        $this->get('/guides/getting-started')->assertOk();
        $this->get('/en/guides/getting-started')->assertOk()->assertSee('href="http://localhost/guides/getting-started"', false);
        $this->get('http://localhost/guides/getting-started/?q=hello')->assertRedirect('/guides/getting-started?q=hello')->assertStatus(301);
        $this->get('/fr')->assertNotFound();
        $this->get('/de/guides/getting-started')->assertNotFound();
        $this->get('http://localhost/%2Fevil.test/')->assertNotFound();
        $nested->forceFill(['status' => 'draft'])->save();
        $this->get('/guides/getting-started')->assertNotFound();
    }

    public function test_host_routes_take_precedence_and_current_slug_beats_redirect_even_when_unpublished(): void
    {
        $page = $this->page(['en' => 'host']);
        Route::get('/host', fn () => response('Host controller wins'));
        $this->get('/host')->assertOk()->assertSee('Host controller wins')->assertDontSee('Live page');
        Route::get('/sitemap.xml', fn () => response('Host sitemap'));
        $this->get('/sitemap.xml')->assertOk()->assertSee('Host sitemap');
        PageRedirect::query()->create(['page_id' => $page->id, 'locale' => 'en', 'from_slug' => 'old', 'status' => 301]);
        $this->get('/old')->assertRedirect('/host')->assertStatus(301);
        $current = $this->page(['en' => 'old'], ['status' => 'draft']);
        $this->get('/old')->assertNotFound();
        $current->delete();
        $page->forceFill(['status' => 'draft'])->save();
        $this->get('/old')->assertNotFound();
    }

    public function test_seo_social_actual_alternates_and_structured_data_are_isolated_between_requests(): void
    {
        Setting::query()->create(['namespace' => 'site', 'data' => (object) ['name' => 'Neutral organization', 'telephone' => '+41000000000']]);
        $page = $this->page(['en' => 'about', 'de' => 'ueber'], ['seo' => (object) ['en' => (object) ['meta_title' => 'Public SEO title', 'meta_description' => 'A description', 'canonical' => 'https://example.test/canonical', 'og_image' => '/images/one.jpg', 'nofollow' => true], 'de' => (object) ['noindex' => true]], 'schema' => (object) ['type' => 'AboutPage', 'faq' => (object) ['en' => [(object) ['question' => 'Why?', 'answer' => '<p>Because.</p>']]], 'breadcrumbs' => (object) ['en' => [(object) ['name' => 'Home', 'url' => '/'], (object) ['name' => 'About', 'url' => '/about']]]]]);
        $response = $this->get('/about')->assertOk()->assertSee('<title>Public SEO title</title>', false)->assertSee('content="nofollow"', false)->assertDontSee('noindex')->assertSee('href="https://example.test/canonical"', false)->assertSee('hreflang="en"', false)->assertSee('hreflang="de"', false)->assertDontSee('hreflang="fr"', false)->assertSee('property="og:image"', false)->assertSee('summary_large_image');
        $graph = $this->graph($response->getContent());
        $types = array_column($graph['@graph'], '@type');
        $this->assertSame(['Organization', 'WebSite', 'AboutPage', 'FAQPage', 'BreadcrumbList', 'CreativeWork'], $types);
        $this->assertSame('Neutral organization', $graph['@graph'][0]['name']);
        $this->assertStringNotContainsString('Hidden graph', json_encode($graph));
        $this->assertSame('Because.', $graph['@graph'][3]['mainEntity'][0]['acceptedAnswer']['text']);
        $other = $this->page(['en' => 'other'], ['seo' => (object) ['en' => (object) ['noindex' => true]]]);
        $otherResponse = $this->get('/other')->assertOk()->assertSee('content="noindex"', false)->assertDontSee('nofollow')->assertDontSee('Why?');
        $this->assertCount(4, $this->graph($otherResponse->getContent())['@graph']);
        $page->forceFill(['title' => '</script><script>bad()</script>', 'seo' => null])->save();
        $this->get('/about')->assertOk()->assertDontSee('</script><script>bad()', false)->assertSee('\\u003C/script\\u003E', false);
    }

    public function test_sitemap_uses_published_indexable_translations_publication_times_and_only_indexable_alternates(): void
    {
        $page = $this->page(['en' => 'visible', 'de' => 'versteckt'], ['seo' => (object) ['de' => (object) ['noindex' => true]]]);
        $this->page(['en' => 'draft-only'], ['status' => 'draft']);
        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->assertSee('/visible')->assertDontSee('/versteckt')->assertDontSee('/draft-only')->assertSee('2026-11-05T12:00:00+00:00')->assertDontSee('hreflang="de"', false);
        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($response->getContent()));
        $this->assertSame(1, $dom->getElementsByTagName('url')->length);
        $this->get('/sitemap_index.xml')->assertRedirect('/sitemap.xml')->assertStatus(301);
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /_site-builder/')->assertSee('Sitemap: http://localhost/sitemap.xml');
        $page->forceFill(['status' => 'draft'])->save();
        $this->get('/sitemap.xml')->assertDontSee('/visible');
    }

    public function test_signed_preview_is_private_expiring_scoped_read_only_and_uses_public_renderer_and_layout(): void
    {
        Gate::define('preview', fn (GenericUser $user): bool => $user->id === 1);
        $page = $this->page();
        $url = app(PreviewLinks::class)->create(new GenericUser(['id' => 1]), $page->id, 'de');
        $this->get('/_site-builder/preview/'.$page->id.'/de')->assertForbidden()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response = $this->get($url)->assertOk()->assertSee('Private draft')->assertDontSee('Live page')->assertSee('preview.js')->assertSee('site-builder.css')->assertDontSee('livewire')->assertDontSee('rel="canonical"', false)->assertDontSee('application/ld+json')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Content-Security-Policy', "form-action 'none'; frame-ancestors 'self'");
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', $response->headers->get('Cache-Control'));
        $this->get(str_replace('/de?', '/fr?', $url))->assertForbidden();
        $other = $this->page(['en' => 'other']);
        $this->get(str_replace('/'.$page->id.'/', '/'.$other->id.'/', $url))->assertForbidden();
        $this->post($url)->assertStatus(405);
        $this->assertSame(0, $page->fresh()->write_version);
        $this->travel(1441)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_preview_creation_is_authorized_and_custom_layouts_are_registered_by_trusted_code_only(): void
    {
        $page = $this->page(['en' => 'custom'], ['layout' => 'brand']);
        app(LayoutRegistry::class)->register('brand', 'test-blocks::layout');
        $this->get('/custom')->assertOk()->assertSee('Host layout')->assertSee('Live page');
        $this->expectException(AuthorizationException::class);
        app(PreviewLinks::class)->create(new GenericUser(['id' => 99]), $page->id, 'en');
    }

    public function test_breadcrumbs_use_real_translated_ancestor_urls_and_preview_errors_remain_private(): void
    {
        $this->page(['en' => 'guides'], ['title' => 'Guides']);
        $nested = $this->page(['en' => 'guides/first'], ['title' => 'First guide']);
        $graph = $this->graph($this->get('/guides/first')->assertOk()->getContent());
        $trail = array_values(array_filter($graph['@graph'], fn ($node): bool => $node['@type'] === 'BreadcrumbList'))[0];
        $this->assertSame('http://localhost/guides', $trail['itemListElement'][0]['item']);
        $this->assertSame('http://localhost/guides/first', $trail['itemListElement'][1]['item']);
        $nested->forceFill(['layout' => 'missing-layout'])->save();
        $url = URL::temporarySignedRoute('site-builder.preview', now()->addMinutes(5), ['page' => $nested->id, 'locale' => 'en', 'epoch' => config('site-builder.preview.epoch')], absolute: false);
        $response = $this->get($url)->assertStatus(500)->assertSee('Preview could not be rendered.')->assertDontSee('Private draft')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_editor_preview_context_is_signed_scoped_and_available_without_editor_dependencies(): void
    {
        Gate::define('preview', fn (GenericUser $user): bool => $user->id === 1);
        $page = $this->page();
        $session = (string) Str::uuid();
        $channel = (string) Str::uuid();
        $url = app(PreviewLinks::class)->create(new GenericUser(['id' => 1]), $page->id, 'en', $session, $channel);
        $this->get($url)->assertOk()->assertSee('site-builder-preview-context')->assertSee($session)->assertSee($channel)->assertSee('preview.js?v=', false)->assertDontSee('livewire');
        $this->get(str_replace($channel, (string) Str::uuid(), $url))->assertForbidden();
        $invalid = URL::temporarySignedRoute('site-builder.preview', now()->addMinutes(5), ['page' => $page->id, 'locale' => 'en', 'epoch' => config('site-builder.preview.epoch'), 'session' => 'invalid', 'channel' => $channel], absolute: false);
        $this->get($invalid)->assertForbidden()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_preview_epoch_revokes_capabilities_without_changing_the_application_key(): void
    {
        Gate::define('preview', fn (GenericUser $user): bool => $user->id === 1);
        $page = $this->page();
        $key = config('app.key');
        $links = app(PreviewLinks::class);
        $old = $links->create(new GenericUser(['id' => 1]), $page->id, 'en');
        $this->get($old)->assertOk();
        config(['site-builder.preview.epoch' => 'next-cutover']);
        $this->get($old)->assertForbidden()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get(str_replace('epoch=1', 'epoch=next-cutover', $old))->assertForbidden();
        $withoutEpoch = URL::temporarySignedRoute('site-builder.preview', now()->addMinutes(5), ['page' => $page->id, 'locale' => 'en'], absolute: false);
        $this->get($withoutEpoch)->assertForbidden();
        $this->get($links->create(new GenericUser(['id' => 1]), $page->id, 'en'))->assertOk()->assertSee('Private draft');
        $this->assertSame($key, config('app.key'));
    }

    public function test_existing_published_preview_configuration_can_omit_the_new_epoch_default(): void
    {
        Gate::define('preview', fn (GenericUser $user): bool => $user->id === 1);
        config(['site-builder.preview' => ['expiry_minutes' => 10, 'widths' => ['mobile' => 420]]]);
        $page = $this->page();
        $url = app(PreviewLinks::class)->create(new GenericUser(['id' => 1]), $page->id, 'en');
        $this->get($url)->assertOk()->assertSee('Private draft');
        $this->assertStringContainsString('epoch=1', $url);
        $this->assertSame(420, config('site-builder.preview.widths.mobile'));
    }
}
