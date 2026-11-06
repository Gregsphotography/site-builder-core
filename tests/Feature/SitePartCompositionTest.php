<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Components\ChildPolicy;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\PlacementPolicy;
use SiteBuilder\Core\Components\PropertyDefinition;
use SiteBuilder\Core\Composition\CompositionData;
use SiteBuilder\Core\Composition\CompositionResolver;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Contracts\HasPlacementPolicy;
use SiteBuilder\Core\Design\DesignCatalog;
use SiteBuilder\Core\Design\DesignResolver;
use SiteBuilder\Core\Design\ThemeIdentity;
use SiteBuilder\Core\DraftHistory\DraftHistory;
use SiteBuilder\Core\DraftHistory\LogicalAction;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Rendering\DocumentRenderer;
use SiteBuilder\Core\Rendering\LayoutRegistry;
use SiteBuilder\Core\Rendering\PageResponse;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\SiteParts\ContentDependencies;
use SiteBuilder\Core\SiteParts\SitePartCommands;
use SiteBuilder\Core\SiteParts\SitePartDraftHistory;
use SiteBuilder\Core\SiteParts\SitePartLocks;
use SiteBuilder\Core\SiteParts\SitePartPayload;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;
use SiteBuilder\Core\Tests\Fixtures\TestDesignTheme;

class SitePartCompositionTest extends DatabaseTestCase
{
    private GenericUser $actor;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['history-migrations', 'site-parts-migrations'] as $directory) {
            $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/'.$directory), '--realpath' => true, '--force' => true])->assertExitCode(0);
        }
        Gate::before(fn () => true);
        $this->actor = new GenericUser(['id' => (string) Str::uuid()]);
        app(ComponentRegistry::class)->register(RenderBlock::class);
        app(ComponentRegistry::class)->register(new class extends RenderBlock implements HasPlacementPolicy
        {
            public function type(): string
            {
                return 'test.structure';
            }

            public function allowedChildren(): ChildPolicy
            {
                return ChildPolicy::types(['test.structure', 'test.block', 'core.global-reference']);
            }

            public function placement(): PlacementPolicy
            {
                return PlacementPolicy::anywhere(structural: true);
            }
        });
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
        app(LayoutRegistry::class)->register('parts', 'test-blocks::parts-layout', ['header', 'body', 'footer']);
        config(['site-builder.layout' => 'parts', 'site-builder.site_parts.preview_layout' => 'parts']);
        app(DesignCatalog::class)->register(new TestDesignTheme);
        app(DesignCatalog::class)->bindDefault(fn () => new ThemeIdentity('test.theme', 1, 'test'));
    }

    private function text(string $value = 'Visible'): Document
    {
        return new Document([new Node('source-text', 'test.block', props: new JsonObject(['heading' => $value]))]);
    }

    private function published(string $kind = 'section', ?Document $document = null): array
    {
        $commands = app(SitePartCommands::class);
        $created = $commands->create($this->actor, $kind, ['name' => 'Part'], SitePartPayload::document($kind, $document ?? $this->text()));
        $lease = app(SitePartLocks::class)->acquire($this->actor, $created->part->id);
        $published = $commands->publish($this->actor, $created->part->id, $lease->token, 0, $created->draftDigest);

        return [$published->part, $lease->token, $published->part->revisions()->firstOrFail()];
    }

    private function reference($revision, string $id, string $visibility = 'visible'): Node
    {
        return new Node($id, 'core.global-reference', props: new JsonObject(['revision' => $revision->reference()->jsonSerialize()]), visibility: $visibility);
    }

    private function structure(Node $child, int $count, string $prefix): Node
    {
        for ($i = 0; $i < $count; $i++) {
            $child = new Node($prefix.$i, 'test.structure', props: new JsonObject(['heading' => 'Container']), children: [$child]);
        }

        return $child;
    }

    public function test_repeated_mounts_have_distinct_render_addresses_and_physical_selection_without_source_mutation(): void
    {
        [, , $revision] = $this->published();
        $document = CompositionData::promote(new Document([$this->reference($revision, 'one'), $this->reference($revision, 'two')]));
        $before = app(CanonicalJson::class)->digest($revision->payload);
        $html = app(DocumentRenderer::class)->render($document, new RenderContext('en', 'preview', 9, 'parts'))->html->toHtml();
        $this->assertSame(2, substr_count($html, '>Visible<'));
        preg_match_all('/data-site-builder-render-address="([a-f0-9]+)"/', $html, $matches);
        $this->assertCount(2, array_unique($matches[1]));
        $this->assertStringContainsString('data-site-builder-node="one"', $html);
        $this->assertStringContainsString('data-site-builder-node="two"', $html);
        $this->assertStringNotContainsString('data-site-builder-node="source-text"', $html);
        $this->assertSame($before, app(CanonicalJson::class)->digest($revision->fresh()->payload));
        $hidden = CompositionData::promote(new Document([$this->reference($revision, 'hidden', 'hidden')]));
        $this->assertSame('', app(DocumentRenderer::class)->render($hidden, new RenderContext('en'))->html->toHtml());
    }

    public function test_combined_structural_depth_hidden_repeats_and_all_payload_bytes_are_bounded_before_output(): void
    {
        [, , $revision] = $this->published(document: new Document([$this->structure($this->text()->nodes[0], 3, 'source-')]));
        $document = CompositionData::promote(new Document([$this->structure($this->reference($revision, 'ref'), 2, 'page-')]));
        try {
            app(CompositionResolver::class)->resolve($document, new RenderContext('en'));
            $this->fail('Two independently valid structural trees exceeded their combined bound.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('structural', $exception->getMessage());
        }
        [, , $simple] = $this->published();
        config(['site-builder.composition.max_nodes' => 3]);
        try {
            app(CompositionResolver::class)->resolve(CompositionData::promote(new Document([$this->reference($simple, 'hidden-1', 'hidden'), $this->reference($simple, 'hidden-2', 'hidden')])), new RenderContext('en'));
            $this->fail('Hidden repeated mounts bypassed accounting.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('nodes', $exception->getMessage());
        }
        config(['site-builder.composition.max_nodes' => 1000, 'site-builder.composition.max_bytes' => 10]);
        $this->expectException(ValidationException::class);
        app(CompositionResolver::class)->resolve(new Document(extensions: new JsonObject(['allLocalesAndOpaque' => str_repeat('x', 20)])), new RenderContext('en'));
    }

    public function test_nested_root_region_pins_are_in_the_closure_and_withdrawal_fails_the_whole_response(): void
    {
        [, $headerLease, $header] = $this->published('header', $this->text('Nested header'));
        $extensions = new JsonObject(['site-builder.render' => (object) ['version' => 1, 'composition' => (object) ['version' => 1, 'header' => $header->reference()->jsonSerialize()]]]);
        $regionOnly = app(DocumentRenderer::class)->render(new Document(extensions: $extensions), new RenderContext('en', 'public', 9, 'parts'));
        $this->assertCount(1, $regionOnly->structuredData);
        $this->assertSame('Nested header', $regionOnly->structuredData[0]['name']);
        [, , $section] = $this->published(document: new Document($this->text('Body')->nodes, $extensions));
        $document = CompositionData::promote(new Document([$this->reference($section, 'outer')]));
        $resolution = app(CompositionResolver::class)->resolve($document, new RenderContext('en', layout: 'parts'));
        $this->assertSame(2, $resolution->usage['mounts']);
        $this->assertCount(2, $resolution->references);
        $page = Page::factory()->create(['published_content' => $document, 'layout' => 'parts']);
        $html = app(PageResponse::class)->render($page, 'en');
        $this->assertSame(200, $html->getStatusCode());
        $this->assertStringContainsString('Nested header', $html->getContent());
        app(SitePartCommands::class)->withdraw($this->actor, $header->resource_id, $headerLease, 1, $header->id, 'Controlled withdrawal');
        $this->assertSame(503, app(PageResponse::class)->render($page->fresh(), 'en')->getStatusCode());
    }

    public function test_page_and_site_part_history_are_separate_and_keep_gc_pins_even_if_index_is_deleted(): void
    {
        [$part, $partToken, $revision] = $this->published();
        $partHistory = app(SitePartDraftHistory::class);
        $session = $partHistory->open($this->actor, $part->id, $partToken, (string) Str::uuid());
        $edited = app(SitePartCommands::class)->saveDraft($this->actor, $part->id, $partToken, 1, SitePartPayload::document('section', $this->text('Draft only')), $session->context);
        $session = $partHistory->status($this->actor, $part->id, $partToken, $session->context);
        $this->assertSame(1, $session->status['undoCount']);
        $undone = $partHistory->undo($this->actor, $part->id, $partToken, 2, $session->context);
        $this->assertSame(3, $undone->mutation->part->write_version);
        $this->assertSame($revision->digest, app(CanonicalJson::class)->digest($undone->mutation->part->draft_payload));
        $page = app(PageCommands::class)->create($this->actor, ['title' => 'Pinned'], [], CompositionData::promote(new Document([$this->reference($revision, 'physical')])))->page;
        $token = app(PageLocks::class)->acquire($this->actor, $page->id)->token;
        $history = app(DraftHistory::class);
        $pageHistory = $history->open($this->actor, $page->id, $token, (string) Str::uuid());
        app(PageCommands::class)->saveDraft($this->actor, $page->id, $token, 0, new Document, $pageHistory->context, new LogicalAction('remove', 'Remove reference'));
        DB::table('site_builder_content_dependencies')->delete();
        try {
            app(SitePartCommands::class)->delete($this->actor, $part->id, $partToken, 3);
            $this->fail('A missing index authorized deleting an unexpired historical pin.');
        } catch (ValidationException) {
            $this->assertNotNull($part->fresh());
        }
        $this->assertNotEmpty(app(ContentDependencies::class)->authoritativeUses($part->id));
        $this->assertSame(1, app(DraftHistory::class)->status($this->actor, $page->id, $token, $pageHistory->context)->status['undoCount']);
        $this->assertSame(0, $partHistory->status($this->actor, $part->id, $partToken, $undone->history->context)->status['undoCount']);
    }

    public function test_resource_ancestry_cycles_are_rejected_and_destination_styles_inherit_through_a_mount(): void
    {
        [$first, $token, $firstRevision] = $this->published();
        [, , $secondRevision] = $this->published(document: CompositionData::promote(new Document([$this->reference($firstRevision, 'inner')])));
        try {
            app(SitePartCommands::class)->saveDraft($this->actor, $first->id, $token, 1, SitePartPayload::document('section', CompositionData::promote(new Document([$this->reference($secondRevision, 'cycle')]))));
            $this->fail('A new revision introduced its own resource into its ancestry.');
        } catch (ValidationException) {
            $this->assertSame(1, $first->fresh()->write_version);
        }
        $design = (object) ['version' => 1, 'theme' => (object) ['id' => 'test.theme', 'version' => 1, 'preset' => 'test'], 'base' => (object) ['align' => 'center']];
        $document = CompositionData::promote(new Document([$this->reference($firstRevision, 'mounted')], new JsonObject(['site-builder.render' => (object) ['version' => 1, 'design' => $design]])));
        $context = new RenderContext('en', layout: 'parts');
        $resolution = app(CompositionResolver::class)->resolve($document, $context);
        $entry = $resolution->body[0];
        $presentation = app(DesignResolver::class)->resolve($entry->document, $entry->node, $context, $entry->presentationOwner, $entry->ancestors);
        $this->assertSame('center', $presentation->states['base']['values']['align']);
        $this->assertSame('center', $presentation->states['wide']['values']['align']);
        $this->assertStringContainsString('test-design-base-align-center', app(DocumentRenderer::class)->render($document, $context)->html->toHtml());
    }

    public function test_declared_nested_translated_revision_values_are_dependencies_without_guessing_opaque_lookalikes(): void
    {
        [, , $revision] = $this->published();
        app(ComponentRegistry::class)->register(new class extends RenderBlock
        {
            public function type(): string
            {
                return 'test.revision-holder';
            }

            public function properties(): array
            {
                return [...parent::properties(), 'pinned' => PropertyDefinition::object(['items' => PropertyDefinition::list(PropertyDefinition::revision())], translated: true)];
            }
        });
        $pin = $revision->reference()->jsonSerialize();
        $node = new Node('holder', 'test.revision-holder', props: new JsonObject(['heading' => 'Holder', 'opaqueLookalike' => $pin]), translations: new JsonObject(['en' => (object) ['pinned' => (object) ['items' => [$pin]]], 'de' => (object) ['pinned' => (object) ['items' => [$pin]]]]));
        $document = CompositionData::promote(new Document([$node]));
        $this->assertCount(2, CompositionData::references($document));
        $this->assertSame(2, app(CompositionResolver::class)->resolve($document, new RenderContext('en'))->usage['mounts']);
        $page = app(PageCommands::class)->create($this->actor, ['title' => 'Declared pins'], [], $document)->page;
        $this->assertCount(2, app(ContentDependencies::class)->authoritativeUses($revision->resource_id));
        $this->assertSame(2, DB::table('site_builder_content_dependencies')->where('owner_id', (string) $page->id)->count());
    }

    public function test_unknown_required_composition_versions_are_preserved_and_render_unavailable(): void
    {
        $document = new Document($this->text()->nodes, new JsonObject(['site-builder.render' => (object) ['version' => 1, 'composition' => (object) ['version' => 99, 'futurePayload' => (object) ['empty' => [], 'nil' => null]]]]));
        $before = app(CanonicalJson::class)->digest($document);
        $render = app(DocumentRenderer::class)->render($document, new RenderContext('en'));
        $this->assertSame('', $render->html->toHtml());
        $page = Page::factory()->create(['published_content' => $document, 'layout' => 'parts']);
        $this->assertSame(503, app(PageResponse::class)->render($page, 'en')->getStatusCode());
        $this->assertSame($before, app(CanonicalJson::class)->digest($page->fresh()->published_content));
    }
}
