<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Composition\CompositionCommands;
use SiteBuilder\Core\Composition\InsertPosition;
use SiteBuilder\Core\Composition\SourceSelection;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Design\DesignCatalog;
use SiteBuilder\Core\Design\DesignCommands;
use SiteBuilder\Core\Design\DesignTarget;
use SiteBuilder\Core\Design\ThemeIdentity;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Exchange\Exchange;
use SiteBuilder\Core\Exchange\LegacyDocuments;
use SiteBuilder\Core\Exchange\Snapshot;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Rendering\PageResponse;
use SiteBuilder\Core\SavedSections\SavedSectionCommands;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\PortalUser;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;
use SiteBuilder\Core\Tests\Fixtures\TestDesignTheme;

class DesignPersistenceTest extends DatabaseTestCase
{
    private GenericUser $actor;

    private TestDesignTheme $theme;

    protected function setUp(): void
    {
        parent::setUp();
        config(['site-builder.locales' => ['en' => [], 'fr' => []]]);
        foreach (['view', 'create', 'update', 'publish', 'restore', 'preview', 'delete'] as $ability) {
            Gate::define($ability, fn (GenericUser $user): bool => $user->allowed === true);
        }
        $this->actor = new GenericUser(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'allowed' => true]);
        app(ComponentRegistry::class)->register(RenderBlock::class);
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
        $this->theme = new TestDesignTheme;
        app(DesignCatalog::class)->register($this->theme);
        app(DesignCatalog::class)->bindDefault(fn () => new ThemeIdentity('test.theme', 1, 'test'));
    }

    private function document(bool $opaqueProperties = true): Document
    {
        return new Document([
            new Node('stable', 'test.block', props: new JsonObject($opaqueProperties ? ['opaque' => (object) ['list' => [], 'object' => (object) [], 'null' => null, 'order' => ['third', 'first']]] : []), translations: new JsonObject(['en' => (object) ['heading' => 'Published original'], 'fr' => (object) ['heading' => 'Texte français', ...($opaqueProperties ? ['opaque' => (object) ['zero' => '0', 'empty' => []]] : [])]]), extensions: new JsonObject(['host.node' => (object) ['keep' => null]])),
        ], new JsonObject(['host.document' => (object) ['empty' => (object) [], 'list' => [], 'null' => null]]));
    }

    private function assertContentPreserved(Document $original, Document $changed): void
    {
        $this->assertSame($original->nodes[0]->id, $changed->nodes[0]->id);
        foreach (['props', 'translations'] as $property) {
            $this->assertTrue(app(CanonicalJson::class)->equals($original->nodes[0]->{$property}, $changed->nodes[0]->{$property}));
        }
        $this->assertEquals($original->nodes[0]->extensions->get('host.node'), $changed->nodes[0]->extensions->get('host.node'));
        $this->assertEquals($original->extensions->get('host.document'), $changed->extensions->get('host.document'));
    }

    public function test_design_drafts_publish_and_restore_without_changing_metadata_or_translations(): void
    {
        $pages = app(PageCommands::class);
        $design = app(DesignCommands::class);
        $created = $pages->create($this->actor, ['title' => 'Shared title'], ['en' => 'design'], $this->document());
        $id = $created->page->id;
        $lease = app(PageLocks::class)->acquire($this->actor, $id);
        $initial = $pages->publish($this->actor, $id, $lease->token, 0, $created->draftDigest);
        $firstRevision = $initial->page->revisions()->firstOrFail();

        $pageDesign = $design->set($this->actor, $id, $lease->token, 1, DesignTarget::document(), 'width', 'narrow');
        $nodeDesign = $design->set($this->actor, $id, $lease->token, 2, DesignTarget::node('stable', 'compact'), 'align', 'center');
        $this->assertSame(3, $nodeDesign->page->write_version);
        $this->assertContentPreserved($this->document(), $nodeDesign->page->draft_content);
        $this->assertSame($created->draftDigest, app(CanonicalJson::class)->digest($nodeDesign->page->published_content));
        $draftHtml = app(PageResponse::class)->render($nodeDesign->page, 'fr', preview: true)->getContent();
        $publicHtml = app(PageResponse::class)->render($nodeDesign->page, 'en')->getContent();
        $this->assertStringContainsString('test-design-compact-align-center', $draftHtml);
        $this->assertStringContainsString('Texte français', $draftHtml);
        $this->assertStringNotContainsString('test-design-compact-align-center', $publicHtml);
        $this->assertStringNotContainsString('preview.js', $publicHtml);

        $published = $pages->publish($this->actor, $id, $lease->token, 3, $nodeDesign->draftDigest, 'Design release');
        $designRevision = $published->page->revisions()->orderByDesc('id')->firstOrFail();
        $this->assertSame((string) $this->actor->id, $designRevision->created_by);
        $this->assertSame(GenericUser::class, $designRevision->created_by_type);
        $this->assertSame($nodeDesign->draftDigest, app(CanonicalJson::class)->digest($designRevision->content));
        $reset = $design->reset($this->actor, $id, $lease->token, 4, DesignTarget::node('stable', 'compact'), 'align');
        $this->assertContentPreserved($this->document(), $reset->page->draft_content);
        $this->assertSame($nodeDesign->draftDigest, app(CanonicalJson::class)->digest($reset->page->published_content));
        $meta = $pages->saveMetadata($this->actor, $id, $lease->token, 5, ['title' => 'Updated shared title']);
        $restored = $pages->restore($this->actor, $id, $lease->token, $meta->page->write_version, $firstRevision->id);
        $this->assertSame($created->draftDigest, $restored->draftDigest);
        $this->assertSame('Updated shared title', $restored->page->title);
        $this->assertSame('design', $restored->page->slugs()->firstOrFail()->slug);
        $this->assertSame($nodeDesign->draftDigest, app(CanonicalJson::class)->digest($restored->page->published_content));
        $restoredDesign = $pages->restore($this->actor, $id, $lease->token, $restored->page->write_version, $designRevision->id);
        $this->assertSame($nodeDesign->draftDigest, $restoredDesign->draftDigest);
        $this->assertSame('Updated shared title', $restoredDesign->page->title);
        $this->assertNotSame($pageDesign->draftDigest, $restoredDesign->draftDigest);
    }

    public function test_every_design_command_rechecks_policy_actor_model_lease_and_expected_version(): void
    {
        $created = app(PageCommands::class)->create($this->actor, ['title' => 'Guarded'], [], $this->document());
        $id = $created->page->id;
        $lease = app(PageLocks::class)->acquire($this->actor, $id);
        $commands = app(DesignCommands::class);
        $calls = [
            fn ($actor, $token, $version) => $commands->set($actor, $id, $token, $version, DesignTarget::document(), 'space', 'lg'),
            fn ($actor, $token, $version) => $commands->reset($actor, $id, $token, $version, DesignTarget::document(), 'space'),
            fn ($actor, $token, $version) => $commands->setTheme($actor, $id, $token, $version, new ThemeIdentity('test.theme', 1, 'alternate'), confirmation: true),
        ];
        foreach ($calls as $call) {
            $checksBefore = $this->theme->availabilityChecks;
            try {
                $call(new GenericUser(['id' => $this->actor->id, 'allowed' => false]), $lease->token, 0);
                $this->fail('Revoked actor changed design.');
            } catch (AuthorizationException) {
                $this->assertSame(0, Page::findOrFail($id)->write_version);
                $this->assertSame($checksBefore, $this->theme->availabilityChecks, 'Revoked actor reached the host design hook.');
            }
            foreach ([
                [new PortalUser(['id' => $this->actor->id, 'allowed' => true]), $lease->token, 0, 'lease_lost'],
                [$this->actor, 'dfb24825-a2a3-4f1f-a55a-55665ea6909f', 0, 'lease_lost'],
                [$this->actor, $lease->token, 99, 'stale_version'],
            ] as [$actor, $token, $version, $reason]) {
                try {
                    $call($actor, $token, $version);
                    $this->fail('Invalid mutation guard was accepted.');
                } catch (WriteConflict $exception) {
                    $this->assertSame($reason, $exception->reason);
                    $this->assertSame($created->draftDigest, app(CanonicalJson::class)->digest(Page::findOrFail($id)->draft_content));
                    $this->assertSame(0, Page::findOrFail($id)->write_version);
                    $this->assertSame($checksBefore, $this->theme->availabilityChecks, 'Invalid write guard reached the host design hook.');
                }
            }
        }
        $this->travel(301)->seconds();
        foreach ($calls as $call) {
            $checksBefore = $this->theme->availabilityChecks;
            try {
                $call($this->actor, $lease->token, 0);
                $this->fail('Expired design lease was accepted.');
            } catch (WriteConflict $exception) {
                $this->assertSame('lease_lost', $exception->reason);
                $this->assertSame(0, Page::findOrFail($id)->write_version);
                $this->assertSame($checksBefore, $this->theme->availabilityChecks, 'Expired lease reached the host design hook.');
            }
        }
    }

    public function test_invalid_design_values_and_unavailable_themes_never_advance_the_draft(): void
    {
        $created = app(PageCommands::class)->create($this->actor, ['title' => 'Validated'], [], $this->document());
        $id = $created->page->id;
        $lease = app(PageLocks::class)->acquire($this->actor, $id);
        $commands = app(DesignCommands::class);
        $calls = [
            fn () => $commands->set($this->actor, $id, $lease->token, 0, DesignTarget::document(), 'width', 'calc(100vw + 1px)'),
            fn () => $commands->set($this->actor, $id, $lease->token, 0, DesignTarget::document(), 'unknown', 'wide'),
            fn () => $commands->set($this->actor, $id, $lease->token, 0, DesignTarget::node('stable', 'wide'), 'role', 'heading'),
            fn () => $commands->set($this->actor, $id, $lease->token, 0, DesignTarget::document(), 'text', 'on-accent'),
            fn () => $commands->setTheme($this->actor, $id, $lease->token, 0, new ThemeIdentity('unregistered.theme', 1, 'test'), confirmation: true),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Invalid/unavailable design was accepted.');
            } catch (InvalidArgumentException|ValidationException) {
                $this->assertSame(0, Page::findOrFail($id)->write_version);
                $this->assertSame($created->draftDigest, app(CanonicalJson::class)->digest(Page::findOrFail($id)->draft_content));
            }
        }
        $this->theme->isAvailable = false;
        try {
            $commands->set($this->actor, $id, $lease->token, 0, DesignTarget::document(), 'width', 'wide');
            $this->fail('An unavailable theme accepted a design mutation.');
        } catch (InvalidArgumentException|ValidationException) {
            $this->assertSame(0, Page::findOrFail($id)->write_version);
            $this->assertSame($created->draftDigest, app(CanonicalJson::class)->digest(Page::findOrFail($id)->draft_content));
        }
    }

    public function test_unavailable_node_features_cannot_be_stripped_by_design_reset_or_normal_save(): void
    {
        $base = $this->document();
        $node = $base->nodes[0];
        $nodeExtensions = $node->extensions->toObject();
        $nodeExtensions->{'site-builder.render'} = (object) ['version' => 1, 'future-feature' => (object) ['version' => 7, 'opaque' => (object) ['list' => [], 'null' => null]]];
        $unsupported = new Document([new Node($node->id, $node->type, $node->version, $node->props, $node->translations, $node->visibility, $node->children, new JsonObject($nodeExtensions))], $base->extensions);
        $page = Page::factory()->create(['draft_content' => $unsupported]);
        $lease = app(PageLocks::class)->acquire($this->actor, $page->id);
        $commands = app(DesignCommands::class);
        $calls = [
            fn () => $commands->set($this->actor, $page->id, $lease->token, 0, DesignTarget::node('stable'), 'align', 'center'),
            fn () => $commands->resetScope($this->actor, $page->id, $lease->token, 0, DesignTarget::node('stable')),
            fn () => app(PageCommands::class)->saveDraft($this->actor, $page->id, $lease->token, 0, $base),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Unavailable node feature was changed or stripped.');
            } catch (InvalidArgumentException|ValidationException) {
                $this->assertSame(0, $page->fresh()->write_version);
                $this->assertTrue(app(CanonicalJson::class)->equals($unsupported, $page->fresh()->draft_content));
            }
        }
    }

    public function test_unavailable_owner_features_cannot_be_removed_to_enable_new_design(): void
    {
        $base = $this->document();
        $extensions = $base->extensions->toObject();
        $extensions->{'site-builder.render'} = (object) ['version' => 1, 'future-feature' => (object) ['version' => 7, 'opaque' => (object) ['list' => [], 'null' => null]]];
        $unsupported = new Document($base->nodes, new JsonObject($extensions));
        $page = Page::factory()->create(['draft_content' => $unsupported]);
        $lease = app(PageLocks::class)->acquire($this->actor, $page->id);
        $commands = app(DesignCommands::class);
        $calls = [
            fn () => $commands->set($this->actor, $page->id, $lease->token, 0, DesignTarget::document(), 'width', 'wide'),
            fn () => $commands->resetScope($this->actor, $page->id, $lease->token, 0, DesignTarget::document()),
            fn () => $commands->setTheme($this->actor, $page->id, $lease->token, 0, new ThemeIdentity('test.theme', 1, 'alternate'), confirmation: true),
            fn () => app(PageCommands::class)->saveDraft($this->actor, $page->id, $lease->token, 0, $base),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Unavailable owner feature was changed or stripped.');
            } catch (InvalidArgumentException|ValidationException) {
                $this->assertSame(0, $page->fresh()->write_version);
                $this->assertTrue(app(CanonicalJson::class)->equals($unsupported, $page->fresh()->draft_content));
            }
        }
        $metadata = app(PageCommands::class)->saveMetadata($this->actor, $page->id, $lease->token, 0, ['title' => 'Allowed shared metadata']);
        $this->assertSame('Allowed shared metadata', $metadata->page->title);
        $this->assertTrue(app(CanonicalJson::class)->equals($unsupported, $metadata->page->draft_content));
    }

    public function test_native_exchange_preserves_pinned_design_and_refuses_lossy_legacy_reverse(): void
    {
        $original = $this->document(false);
        $created = app(PageCommands::class)->create($this->actor, ['title' => 'Exchange'], ['en' => 'exchange'], $original);
        $lease = app(PageLocks::class)->acquire($this->actor, $created->page->id);
        $changed = app(DesignCommands::class)->set($this->actor, $created->page->id, $lease->token, 0, DesignTarget::node('stable', 'wide'), 'align', 'end');
        $snapshot = app(Exchange::class)->export(DB::connection(), 'design-source');
        $decoded = Snapshot::decode($snapshot->encode());
        $this->assertSame(3, $decoded->version());
        config(['database.connections.design_copy' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        try {
            $this->artisan('migrate', $this->migrationOptions() + ['--database' => 'design_copy'])->assertSuccessful();
            $copy = DB::connection('design_copy');
            $report = app(Exchange::class)->import($decoded, $copy);
            $this->assertSame('imported', $report['status'], json_encode($report['diagnostics']));
            $document = app(DocumentCodec::class)->decode($copy->table('site_builder_pages')->value('draft_content'));
            $this->assertSame($changed->draftDigest, app(CanonicalJson::class)->digest($document));
            $this->assertContentPreserved($original, $document);
            $this->assertFalse($copy->getSchemaBuilder()->hasTable('users'));
            $this->assertSame('unchanged', app(Exchange::class)->import($decoded, $copy)['status']);
        } finally {
            DB::purge('design_copy');
        }
        $node = $changed->page->draft_content->nodes[0];
        $designOnly = new Document([
            new Node($node->id, $node->type, $node->version, $node->props, $node->translations, $node->visibility, $node->children, new JsonObject(['site-builder.render' => $node->extensions->get('site-builder.render')])),
        ], new JsonObject(['site-builder.render' => $changed->page->draft_content->extensions->get('site-builder.render')]));
        app(LegacyDocuments::class)->allowNativeTypes(['test.block']);
        $this->expectException(InvalidArgumentException::class);
        app(LegacyDocuments::class)->encode($designOnly);
    }

    public function test_unknown_property_design_exports_preserve_exact_data_and_hold_import_for_schema_review(): void
    {
        $created = app(PageCommands::class)->create($this->actor, ['title' => 'Opaque exchange'], ['en' => 'opaque-exchange'], $this->document());
        $lease = app(PageLocks::class)->acquire($this->actor, $created->page->id);
        $changed = app(DesignCommands::class)->set($this->actor, $created->page->id, $lease->token, 0, DesignTarget::document(), 'width', 'wide');
        $snapshot = Snapshot::decode(app(Exchange::class)->export(DB::connection(), 'opaque-design-source')->encode());
        $roundTrip = app(DocumentCodec::class)->decode($snapshot->tables()['pages'][0]['draft_content']);
        $this->assertSame($changed->draftDigest, app(CanonicalJson::class)->digest($roundTrip));
        $this->assertContentPreserved($this->document(), $roundTrip);
        config(['database.connections.opaque_design_copy' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        try {
            $this->artisan('migrate', $this->migrationOptions() + ['--database' => 'opaque_design_copy'])->assertSuccessful();
            $copy = DB::connection('opaque_design_copy');
            $report = app(Exchange::class)->plan($snapshot, $copy);
            $this->assertFalse($report['cutover_eligible']);
            $this->assertContains('unknown_property', array_column($report['diagnostics'], 'code'));
            $this->assertSame(0, $copy->table('site_builder_pages')->count());
        } finally {
            DB::purge('opaque_design_copy');
        }
    }

    public function test_saved_design_sources_preview_and_apply_in_destination_context_without_activating_source_page_defaults(): void
    {
        $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/saved-sections-migrations'), '--realpath' => true, '--force' => true])->assertSuccessful();
        foreach (['viewAny', 'use', 'share'] as $ability) {
            Gate::define($ability, fn (GenericUser $user): bool => $user->allowed === true);
        }
        $pages = app(PageCommands::class);
        $locks = app(PageLocks::class);
        $design = app(DesignCommands::class);
        $source = $pages->create($this->actor, ['title' => 'Styled source'], [], $this->document());
        $sourceLease = $locks->acquire($this->actor, $source->page->id);
        $design->set($this->actor, $source->page->id, $sourceLease->token, 0, DesignTarget::document(), 'width', 'wide');
        $design->setTheme($this->actor, $source->page->id, $sourceLease->token, 1, new ThemeIdentity('test.theme', 1, 'alternate'), confirmation: true);
        $styled = $design->set($this->actor, $source->page->id, $sourceLease->token, 2, DesignTarget::node('stable'), 'align', 'center');
        $saved = app(SavedSectionCommands::class)->capture($this->actor, $source->page->id, $sourceLease->token, 3, 'stable', ['name' => 'Styled reusable source']);
        $snapshot = app(SavedSectionCommands::class)->snapshot($this->actor, $saved->id, 1);
        $this->assertSame('alternate', $snapshot->document->extensions->get('site-builder.render')->design->theme->preset);

        $destination = $pages->create($this->actor, ['title' => 'Destination'], [], $this->document(false));
        $destinationLease = $locks->acquire($this->actor, $destination->page->id);
        $pages->publish($this->actor, $destination->page->id, $destinationLease->token, 0, $destination->draftDigest);
        $design->set($this->actor, $destination->page->id, $destinationLease->token, 1, DesignTarget::document(), 'width', 'narrow');
        $selection = new SourceSelection($snapshot->kind, $snapshot->id, $snapshot->version, $snapshot->digest, 'en');
        $composition = app(CompositionCommands::class);
        $preview = $composition->previewSource($this->actor, $destination->page->id, $selection);
        $this->assertCount(1, $preview->nodes);
        $this->assertNotSame('stable', $preview->nodes[0]->id);
        $this->assertSame('test', $preview->extensions->get('site-builder.render')->design->theme->preset);
        $this->assertSame('narrow', $preview->extensions->get('site-builder.render')->design->base->width);
        $this->assertSame('center', $preview->nodes[0]->extensions->get('site-builder.render')->design->base->align);
        $html = app(PageResponse::class)->previewDocument($this->actor, $destination->page->id, $preview, 'fr')->getContent();
        $this->assertStringContainsString('test-design-base-align-center', $html);
        $this->assertStringContainsString('test-design-base-width-narrow', $html);
        $this->assertStringNotContainsString('test-design-base-width-wide', $html);
        $this->assertSame(2, $destination->page->fresh()->write_version);
        $this->assertSame(3, $source->page->fresh()->write_version);
        $this->assertSame(2, Page::query()->count());
        $this->assertFalse($composition->review($this->actor, $destination->page->id, $destinationLease->token, 2, $selection)->blocking);
        $applied = $composition->insert($this->actor, $destination->page->id, $destinationLease->token, 2, $selection, InsertPosition::end());
        $draft = $applied->mutation->page->draft_content;
        $this->assertCount(2, $draft->nodes);
        $this->assertNotSame('stable', $draft->nodes[1]->id);
        $this->assertSame('narrow', $draft->extensions->get('site-builder.render')->design->base->width);
        $this->assertSame('test', $draft->extensions->get('site-builder.render')->design->theme->preset);
        $this->assertSame('center', $draft->nodes[1]->extensions->get('site-builder.render')->design->base->align);
        $inactiveSource = $draft->extensions->get('site-builder.copy')->entries[0]->sourceDocumentExtensions->{'site-builder.render'}->design;
        $this->assertSame('alternate', $inactiveSource->theme->preset);
        $this->assertSame('wide', $inactiveSource->base->width);
        $this->assertSame($destination->draftDigest, app(CanonicalJson::class)->digest($applied->mutation->page->published_content));
        $this->assertSame($styled->draftDigest, app(CanonicalJson::class)->digest($source->page->fresh()->draft_content));
    }
}
