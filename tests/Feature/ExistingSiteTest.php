<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageRedirect;
use SiteBuilder\Core\Routing\PageUrlResolver;
use SiteBuilder\Core\Routing\PreviewLinks;
use SiteBuilder\Core\Routing\SiteRoutes;
use SiteBuilder\Core\Tests\DatabaseTestCase;

class ExistingSiteTest extends DatabaseTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('site-builder.routes.enabled', false);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('g', 32)));
    }

    private function page(string $slug = 'home'): Page
    {
        $page = Page::factory()->create(['title' => 'Builder page', 'status' => 'published', 'draft_content' => new Document, 'published_content' => new Document]);
        $page->slugs()->create(['locale' => 'en', 'slug' => $slug]);

        return $page;
    }

    public function test_public_routes_can_live_under_a_prefix_with_host_routes_api_and_urls_unchanged(): void
    {
        config(['site-builder.routes.prefix' => 'pages', 'site-builder.routes.middleware' => ['web', ExistingSiteMiddleware::class]]);
        Route::get('/', fn () => response('Host home'));
        Route::get('/api/ping', fn () => response()->json(['host' => true]));
        Route::get('/pages/host-only', fn () => response('Explicit host route'));
        Route::fallback(fn () => response('Host not found', 404));
        SiteRoutes::register();
        $home = $this->page();
        $about = $this->page('about');
        $this->assertSame('/pages', app(PageUrlResolver::class)->path($home, 'en'));
        $this->assertSame('/pages/about', app(PageUrlResolver::class)->path($about, 'en'));
        $this->get('/')->assertOk()->assertSee('Host home')->assertHeaderMissing('X-Builder-Middleware');
        $this->get('/api/ping')->assertOk()->assertExactJson(['host' => true]);
        $this->get('/api/missing')->assertNotFound()->assertSee('Host not found');
        $this->get('/pages/host-only')->assertOk()->assertSee('Explicit host route')->assertHeaderMissing('X-Builder-Middleware');
        $this->get('/pages')->assertOk()->assertSee('Builder page')->assertHeader('X-Builder-Middleware', 'yes');
        $this->get('/pages/about')->assertOk()->assertSee('http://localhost/pages/about')->assertHeader('X-Builder-Middleware', 'yes');
        $this->get('/pages/home')->assertRedirect('/pages');
        PageRedirect::query()->create(['locale' => 'en', 'from_slug' => 'old-about', 'page_id' => $about->id]);
        $this->get('/pages/old-about')->assertRedirect('/pages/about');
        $this->get('/pages/sitemap.xml')->assertOk()->assertSee('http://localhost/pages/about');
        $this->get('/pages/sitemap_index.xml')->assertRedirect('/pages/sitemap.xml');
        $this->get('/pages/robots.txt')->assertOk()->assertSee('Sitemap: http://localhost/pages/sitemap.xml');
        $this->get('/about')->assertNotFound()->assertSee('Host not found');
    }

    public function test_an_existing_host_fallback_is_never_overwritten_at_the_same_prefix(): void
    {
        Route::fallback(fn () => response('Host custom 404', 404));
        SiteRoutes::register();
        $this->page('missing');
        $this->get('/missing')->assertNotFound()->assertSee('Host custom 404');
        $this->assertNull(Route::getRoutes()->getByName('site-builder.page'));
    }

    public function test_public_routes_and_css_can_be_disabled_independently_of_signed_preview(): void
    {
        config(['site-builder.routes.public' => false, 'site-builder.routes.preview_prefix' => 'cms/frame', 'site-builder.assets.public_css' => false]);
        SiteRoutes::register();
        $page = $this->page('private-host');
        $this->get('/private-host')->assertNotFound();
        Gate::define('preview', fn () => true);
        $url = app(PreviewLinks::class)->create(new GenericUser(['id' => 1]), $page->id, 'en');
        $this->assertStringStartsWith('/cms/frame/', $url);
        $this->get($url)->assertOk()->assertDontSee('site-builder.css')->assertSee('preview.js');
        $this->assertNull(Route::getRoutes()->getByName('site-builder.page'));
    }

    public function test_complete_route_opt_out_does_not_register_public_or_preview_routes(): void
    {
        $this->assertFalse(config('site-builder.routes.enabled'));
        $this->assertNull(Route::getRoutes()->getByName('site-builder.page'));
        $this->assertNull(Route::getRoutes()->getByName('site-builder.preview'));
        $this->get('/_site-builder/preview/1/en')->assertNotFound();
    }

    public function test_default_assets_define_tokens_only_inside_the_canvas_and_need_no_host_build_pipeline(): void
    {
        foreach (['css', 'dist'] as $directory) {
            $css = file_get_contents(__DIR__.'/../../resources/'.$directory.'/site-builder.css');
            $this->assertStringNotContainsString(':root', $css);
            $this->assertStringNotContainsString('@tailwind', $css);
            $this->assertStringContainsString('[data-site-builder-canvas]{--site-builder-color-primary:', $css);
            $this->assertStringContainsString('[data-site-builder-canvas] [data-site-builder-node]', $css);
        }
        Route::get('/builder-layout', fn () => view('site-builder::layouts.site', ['slot' => 'Scoped content', 'title' => 'Scoped title']));
        $this->get('/builder-layout')->assertOk()->assertSee('<main data-site-builder-canvas>', false)->assertSee('/vendor/site-builder/site-builder.css')->assertDontSee('vite');
    }
}

class ExistingSiteMiddleware
{
    public function handle($request, $next)
    {
        $response = $next($request);
        $response->headers->set('X-Builder-Middleware', 'yes');

        return $response;
    }
}
