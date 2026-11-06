<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Design\DesignCatalog;
use SiteBuilder\Core\Design\DesignCommands;
use SiteBuilder\Core\Design\DesignTarget;
use SiteBuilder\Core\Design\ThemeIdentity;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Rendering\PageResponse;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;
use SiteBuilder\Core\Tests\Fixtures\TestDesignTheme;

class DesignAvailabilityTest extends DatabaseTestCase
{
    private TestDesignTheme $theme;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        app(ComponentRegistry::class)->register(RenderBlock::class);
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
        $this->theme = new TestDesignTheme;
        app(DesignCatalog::class)->register($this->theme);
        app(DesignCatalog::class)->bindDefault(fn () => new ThemeIdentity('test.theme', 1, 'test'));
    }

    public function test_missing_artifacts_fail_closed_and_cannot_be_stripped_by_a_generic_content_save(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $base = new Document([new Node('node', 'test.block', props: new JsonObject(['heading' => 'Protected styling']))]);
        $page = app(PageCommands::class)->create($actor, ['title' => 'Styled'], [], $base)->page;
        $lease = app(PageLocks::class)->acquire($actor, $page->id);
        app(DesignCommands::class)->set($actor, $page->id, $lease->token, 0, DesignTarget::document(), 'width', 'narrow');
        $this->theme->isAvailable = false;
        $response = app(PageResponse::class)->render($page->fresh(), 'en', preview: true);
        $this->assertSame(503, $response->status());
        $this->assertStringNotContainsString('Protected styling', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        try {
            app(PageCommands::class)->saveDraft($actor, $page->id, $lease->token, 1, $base);
            $this->fail('A content save cannot silently remove unavailable required styling.');
        } catch (ValidationException) {
            $this->assertSame(1, $page->fresh()->write_version);
        }
        // Explicit known-scope reset and theme reset can recover to the legacy host
        // appearance without pretending that the missing artifact was rendered.
        app(DesignCommands::class)->resetScope($actor, $page->id, $lease->token, 1, DesignTarget::document());
        $cleared = app(DesignCommands::class)->setTheme($actor, $page->id, $lease->token, 2, null, confirmation: true);
        $this->assertNull($cleared->page->draft_content->extensions->get('site-builder.render'));
        $this->assertSame(200, app(PageResponse::class)->render($cleared->page, 'en', preview: true)->status());
    }

    public function test_direct_source_preview_inherits_destination_defaults_and_does_not_take_over_with_source_page_design(): void
    {
        $actor = new GenericUser(['id' => 7]);
        $page = app(PageCommands::class)->create($actor, ['title' => 'Destination'], [], new Document([new Node('existing', 'test.block')]))->page;
        $lease = app(PageLocks::class)->acquire($actor, $page->id);
        app(DesignCommands::class)->set($actor, $page->id, $lease->token, 0, DesignTarget::document(), 'width', 'narrow');
        $source = new Document([new Node('source', 'test.block', props: new JsonObject(['heading' => 'Source']))], new JsonObject(['site-builder.render' => (object) ['version' => 1, 'design' => (object) ['version' => 1, 'theme' => (object) ['id' => 'test.theme', 'version' => 1, 'preset' => 'alternate'], 'base' => (object) ['width' => 'wide']]]]));
        $response = app(PageResponse::class)->previewDocument($actor, $page->id, $source, 'en');
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('data-test-theme="test"', $response->getContent());
        $this->assertStringContainsString('test-design-base-width-narrow', $response->getContent());
        $this->assertStringNotContainsString('test-design-base-width-wide', $response->getContent());
        $this->assertSame(1, $page->fresh()->write_version);
    }
}
