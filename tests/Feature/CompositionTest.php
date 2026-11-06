<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Composition\CompositionCommands;
use SiteBuilder\Core\Composition\InsertPosition;
use SiteBuilder\Core\Composition\ReferenceMap;
use SiteBuilder\Core\Composition\SourceSelection;
use SiteBuilder\Core\Composition\StartingPointDescriptor;
use SiteBuilder\Core\Composition\StartingPointRegistry;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Exceptions\InvalidDocument;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Rendering\PageResponse;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\CompositionBlock;

class CompositionTest extends DatabaseTestCase
{
    private GenericUser $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['site-builder.locales' => ['en' => [], 'de' => []]]);
        app(ComponentRegistry::class)->register(new CompositionBlock);
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
        Gate::before(fn (GenericUser $actor): ?bool => $actor->allowed === true ? true : null);
        $this->actor = new GenericUser(['id' => 7, 'allowed' => true]);
    }

    private function source(?Document $document = null): SourceSelection
    {
        $document ??= new Document([new Node('source', 'test.composition', props: new JsonObject(['heading' => 'New source']), children: [new Node('child', 'test.composition')])]);
        $registry = app(StartingPointRegistry::class);
        $registry->register(new StartingPointDescriptor('example', 1, 'Example'), fn () => $document);
        $snapshot = $registry->snapshot('example', 1, 'en');

        return new SourceSelection($snapshot->kind, $snapshot->id, $snapshot->version, $snapshot->digest, 'en');
    }

    public function test_insert_anchor_and_replace_are_atomic_draft_only_and_preserve_source_scope_provenance(): void
    {
        $extensions = new JsonObject(['opaque' => (object) ['empty' => (object) [], 'list' => [], 'null' => null]]);
        $source = $this->source(new Document([new Node('source', 'test.composition', props: new JsonObject(['heading' => 'New source']), translations: new JsonObject(['de' => (object) ['heading' => 'Deutsch']]), extensions: $extensions)], $extensions));
        $old = new Document([new Node('before', 'test.composition'), new Node('after', 'test.composition')], new JsonObject(['host' => (object) ['value' => 'Keep']]));
        $page = Page::factory()->create(['title' => 'Shared metadata', 'draft_content' => $old, 'published_content' => $old]);
        $lease = app(PageLocks::class)->acquire($this->actor, $page->id);
        $result = app(CompositionCommands::class)->insert($this->actor, $page->id, $lease->token, 0, $source, InsertPosition::before('after'));
        $draft = $result->mutation->page->draft_content;
        $this->assertSame(['before', $result->insertedIds[0], 'after'], array_map(fn ($node) => $node->id, $draft->nodes));
        $this->assertSame('Deutsch', $draft->nodes[1]->translations->get('de')->heading);
        $this->assertSame('Keep', $draft->extensions->get('host')->value);
        $provenance = $draft->extensions->get('site-builder.copy')->entries[0];
        $this->assertTrue(app(CanonicalJson::class)->equals($extensions, $provenance->sourceDocumentExtensions));
        $this->assertTrue(app(CanonicalJson::class)->equals($extensions, $draft->nodes[1]->extensions));
        $this->assertTrue(app(CanonicalJson::class)->equals($old, $page->fresh()->published_content));
        $this->assertSame('Shared metadata', $page->fresh()->title);
        try {
            app(CompositionCommands::class)->replaceDraftSections($this->actor, $page->id, $lease->token, 1, $result->mutation->draftDigest, $source);
            $this->fail('Replacing draft sections requires explicit confirmation.');
        } catch (ValidationException) {
            $this->assertSame(1, $page->fresh()->write_version);
        }
        $replaced = app(CompositionCommands::class)->replaceDraftSections($this->actor, $page->id, $lease->token, 1, $result->mutation->draftDigest, $source, confirmation: true);
        $this->assertCount(1, $replaced->mutation->page->draft_content->nodes);
        $this->assertNotSame($result->insertedIds[0], $replaced->insertedIds[0]);
        $this->assertSame(2, $replaced->mutation->page->write_version);
        $this->assertTrue(app(CanonicalJson::class)->equals($old, $page->fresh()->published_content));
    }

    public function test_missing_anchor_stale_version_token_source_digest_and_revoked_actor_never_write(): void
    {
        $source = $this->source();
        $page = Page::factory()->create(['draft_content' => new Document]);
        $lease = app(PageLocks::class)->acquire($this->actor, $page->id);
        foreach ([
            fn () => app(CompositionCommands::class)->insert($this->actor, $page->id, $lease->token, 0, $source, InsertPosition::after('gone')),
            fn () => app(CompositionCommands::class)->insert($this->actor, $page->id, $lease->token, 9, $source, InsertPosition::end()),
            fn () => app(CompositionCommands::class)->insert($this->actor, $page->id, (string) Str::uuid(), 0, $source, InsertPosition::end()),
            fn () => app(CompositionCommands::class)->insert($this->actor, $page->id, $lease->token, 0, new SourceSelection('component', 'example', 1, str_repeat('0', 64), 'en'), InsertPosition::end()),
        ] as $action) {
            try {
                $action();
                $this->fail('The invalid composition request must be refused.');
            } catch (InvalidArgumentException|WriteConflict) {
                $this->assertSame(0, $page->fresh()->write_version);
            }
        }
        $this->actor->allowed = false;
        $this->expectException(AuthorizationException::class);
        app(CompositionCommands::class)->insert($this->actor, $page->id, $lease->token, 0, $source, InsertPosition::end());
    }

    public function test_unverified_media_requires_explicit_keep_and_typed_mapping_rechecks_every_locale(): void
    {
        $target = Page::factory()->create(['status' => 'published']);
        $target->slugs()->create(['locale' => 'en', 'slug' => 'target']);
        $source = $this->source(new Document([new Node('source', 'test.composition', props: new JsonObject(['image' => '/host.jpg', 'target' => 99999]), translations: new JsonObject(['de' => (object) ['target' => $target->id]]))]));
        $page = Page::factory()->create(['draft_content' => new Document]);
        $lease = app(PageLocks::class)->acquire($this->actor, $page->id);
        $commands = app(CompositionCommands::class);
        $report = $commands->review($this->actor, $page->id, $lease->token, 0, $source);
        $this->assertTrue($report->blocking);
        $this->assertSame(['unverified', 'missing', 'valid'], array_column($report->occurrences, 'status'));
        $this->assertSame('de', $report->occurrences[2]['locale']);
        $this->assertStringContainsString('no published URL', $report->occurrences[2]['message']);
        $map = ReferenceMap::fromArray([
            ['nodeId' => 'source', 'path' => '/props/image', 'kind' => 'media', 'original' => '/host.jpg', 'value' => '/host.jpg', 'keepUnverified' => true],
            ['nodeId' => 'source', 'path' => '/props/target', 'kind' => 'page', 'original' => 99999, 'value' => $target->id],
        ]);
        $this->assertFalse($commands->review($this->actor, $page->id, $lease->token, 0, $source, $map)->blocking);
        $result = $commands->insert($this->actor, $page->id, $lease->token, 0, $source, InsertPosition::end(), $map);
        $this->assertSame($target->id, $result->mutation->page->draft_content->nodes[0]->props->get('target'));
        $this->assertSame($target->id, $result->mutation->page->draft_content->nodes[0]->translations->get('de')->target);
    }

    public function test_required_document_render_features_are_never_dropped_by_partial_source_admission(): void
    {
        $document = new Document([new Node('gated', 'test.composition', props: new JsonObject(['heading' => 'Must stay gated']))], new JsonObject(['site-builder.render' => (object) ['version' => 999, 'future' => (object) []]]));
        $before = app(DocumentCodec::class)->encode($document);
        try {
            $this->source($document);
            $this->fail('A section source cannot discard a required document render feature.');
        } catch (ValidationException) {
            $this->assertSame($before, app(DocumentCodec::class)->encode($document));
        }
    }

    public function test_complete_result_limits_apply_before_any_draft_or_provenance_write(): void
    {
        $source = $this->source(new Document([new Node('source', 'test.composition')]));
        app()->instance(DocumentCodec::class, new DocumentCodec(maxNodes: 2));
        $page = Page::factory()->create(['draft_content' => new Document([new Node('a', 'test.composition'), new Node('b', 'test.composition')])]);
        $lease = app(PageLocks::class)->acquire($this->actor, $page->id);
        try {
            app(CompositionCommands::class)->insert($this->actor, $page->id, $lease->token, 0, $source, InsertPosition::end());
            $this->fail('The assembled document exceeds the node budget.');
        } catch (InvalidDocument) {
            $this->assertSame(0, $page->fresh()->write_version);
            $this->assertNull($page->fresh()->draft_content->extensions->get('site-builder.copy'));
        }
    }

    public function test_authorized_source_preview_uses_actual_layout_without_writes_or_editor_channel(): void
    {
        $page = Page::factory()->create(['draft_content' => new Document]);
        $response = app(PageResponse::class)->previewDocument($this->actor, $page->id, new Document([new Node('preview', 'test.composition', props: new JsonObject(['heading' => 'Truthful preview']))]), 'en');
        $this->assertStringContainsString('Truthful preview', $response->getContent());
        $this->assertStringContainsString('preview.js', $response->getContent());
        $this->assertStringNotContainsString('site-builder-preview-context', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(0, $page->fresh()->write_version);
    }
}
