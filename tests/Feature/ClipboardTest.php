<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use SiteBuilder\Core\Clipboard\ClipboardCommands;
use SiteBuilder\Core\Clipboard\ClipboardDomainResolver;
use SiteBuilder\Core\Clipboard\ClipboardEnvelope;
use SiteBuilder\Core\Clipboard\ClipboardExportPolicy;
use SiteBuilder\Core\Clipboard\ClipboardLimits;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\Reference;
use SiteBuilder\Core\Composition\CopyContext;
use SiteBuilder\Core\Composition\InsertPosition;
use SiteBuilder\Core\Composition\ReferenceCheck;
use SiteBuilder\Core\Composition\ReferenceMap;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Contracts\CopyReferenceInspector;
use SiteBuilder\Core\Design\DesignCatalog;
use SiteBuilder\Core\Design\DesignOperations;
use SiteBuilder\Core\Design\DesignResolver;
use SiteBuilder\Core\Design\DesignTarget;
use SiteBuilder\Core\Design\ThemeIdentity;
use SiteBuilder\Core\DraftHistory\DraftHistory;
use SiteBuilder\Core\DraftHistory\HistoryContext;
use SiteBuilder\Core\DraftHistory\HistoryRecord;
use SiteBuilder\Core\Exceptions\InvalidDocument;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Tests\ClipboardTestCase;
use SiteBuilder\Core\Tests\Fixtures\CompositionBlock;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;
use SiteBuilder\Core\Tests\Fixtures\TestDesignTheme;

class ClipboardTest extends ClipboardTestCase
{
    private GenericUser $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['site-builder.locales' => ['en' => [], 'de' => []]]);
        app(ComponentRegistry::class)->register(new CompositionBlock);
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
        Gate::before(function (Authenticatable $actor, string $ability, array $arguments): bool {
            if ($ability === 'preview' && in_array($arguments[0]->id, $actor->deniedPreview ?? [], true)) {
                return false;
            }

            return ! in_array($ability, $actor->denied ?? [], true);
        });
        $this->actor = new GenericUser(['id' => 7, 'denied' => [], 'deniedPreview' => [], 'runtimeCredential' => 'never-export-this']);
    }

    private function page(?Document $document = null, ?Authenticatable $actor = null): array
    {
        $page = Page::factory()->create(['draft_content' => $document ?? new Document([new Node('root', 'test.composition', props: new JsonObject(['heading' => 'Captured draft']))])]);
        $lease = app(PageLocks::class)->acquire($actor ?? $this->actor, $page->id);

        return [$page->fresh(), $lease];
    }

    private function capture(Page $page, $lease, string $root = 'root'): ClipboardEnvelope
    {
        return app(ClipboardCommands::class)->capture($this->actor, $page->id, $lease->token, $page->write_version, $root, (string) Str::uuid(), 'en');
    }

    private function paste(Page $page, $lease, string $text, ?ReferenceMap $map = null)
    {
        return app(ClipboardCommands::class)->paste($this->actor, $page->id, $lease->token, $page->write_version, $text, InsertPosition::end(), 'en', $map);
    }

    public function test_content_only_capture_review_and_paste_are_immutable_draft_operations_with_fresh_descendant_ids(): void
    {
        $opaque = new JsonObject(['empty' => (object) [], 'list' => [], 'null' => null, 'looksLikePage' => 918, 'looksLikeId' => 'child']);
        $document = new Document([new Node('root', 'test.composition', props: new JsonObject(['heading' => 'Captured draft', 'opaque' => $opaque->toObject()]), translations: new JsonObject(['de' => (object) ['heading' => 'Entwurf']]), children: [new Node('child', 'test.composition', props: new JsonObject(['heading' => 'Nested']))], extensions: $opaque)], $opaque);
        [$source, $sourceLease] = $this->page($document);
        [$destination, $lease] = $this->page(new Document);
        $published = new Document([new Node('published', 'test.composition', props: new JsonObject(['heading' => 'Public content']))]);
        $destination->forceFill(['published_content' => $published])->save();
        $envelope = $this->capture($source, $sourceLease);
        $payload = json_decode($envelope->toJson());
        $this->assertSame(ClipboardEnvelope::FORMAT, $payload->format);
        $this->assertEquals((object) ['document' => 1], $payload->requiredCapabilities);
        $this->assertSame(2, $envelope->summary()['nodeCount']);
        $this->assertSame(['root'], $envelope->summary()['rootIds']);
        foreach ([$sourceLease->token, $lease->token, 'never-export-this', 'source_tab_id', 'token_hash', 'csrf', 'history', 'preview_url', 'APP_KEY'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $envelope->toJson());
        }
        $clipboard = app(ClipboardCommands::class);
        $review = $clipboard->review($this->actor, $destination->id, $lease->token, 0, $envelope->toJson(), 'en');
        $this->assertFalse($review->references->blocking);
        $this->assertSame(hash('sha256', $envelope->toJson()), $review->textDigest);
        $this->assertSame(0, $review->destinationVersion);
        $this->assertSame(0, $destination->fresh()->write_version);
        $this->assertSame(1, DB::table('site_builder_clipboard_snapshots')->count());
        // Editing the original later does not change the retained copied snapshot.
        $source->forceFill(['draft_content' => new Document, 'write_version' => 1])->save();
        $result = $this->paste($destination, $lease, $envelope->toJson());
        $node = $result->mutation->page->draft_content->nodes[0];
        $this->assertNotSame('root', $node->id);
        $this->assertNotSame('child', $node->children[0]->id);
        $this->assertSame('Captured draft', $node->props->get('heading'));
        $this->assertTrue(app(CanonicalJson::class)->equals($document->nodes[0]->translations, $node->translations));
        $this->assertTrue(app(CanonicalJson::class)->equals($opaque, $node->props->get('opaque')));
        $this->assertTrue(app(CanonicalJson::class)->equals($opaque, $node->extensions));
        $this->assertTrue(app(CanonicalJson::class)->equals($published, $destination->fresh()->published_content));
        $this->assertSame(0, $destination->revisions()->count());
        $this->assertSame(1, $source->fresh()->write_version);
        $destination->refresh();
        $again = $this->paste($destination, $lease, $envelope->toJson());
        $this->assertNotSame($result->insertedIds, $again->insertedIds);
        $this->assertSame(2, $destination->fresh()->write_version);
    }

    public function test_nested_selection_copies_only_that_subtree(): void
    {
        [$source, $sourceLease] = $this->page(new Document([new Node('parent', 'test.composition', children: [new Node('root', 'test.composition', children: [new Node('grandchild', 'test.composition')]), new Node('sibling', 'test.composition')])]));
        $envelope = $this->capture($source, $sourceLease);
        $this->assertSame(2, $envelope->nodeCount);
        $this->assertStringNotContainsString('"id":"parent"', $envelope->toJson());
        $this->assertStringNotContainsString('"id":"sibling"', $envelope->toJson());
    }

    public function test_source_preview_revocation_blocks_private_draft_paste_even_when_public_view_stays_allowed(): void
    {
        [$source, $sourceLease] = $this->page();
        [$destination, $lease] = $this->page(new Document);
        $text = $this->capture($source, $sourceLease)->toJson();
        $this->actor->deniedPreview = [$source->id];
        $this->assertTrue(Gate::forUser($this->actor)->allows('view', $source));
        foreach ([fn () => app(ClipboardCommands::class)->review($this->actor, $destination->id, $lease->token, 0, $text, 'en'), fn () => $this->paste($destination, $lease, $text)] as $operation) {
            try {
                $operation();
                $this->fail('Public view access cannot reveal retained private draft content.');
            } catch (AuthorizationException) {
                $this->assertSame(0, $destination->fresh()->write_version);
                $this->assertCount(0, $destination->fresh()->draft_content->nodes);
            }
        }
    }

    public function test_source_and_destination_guards_refuse_wrong_leases_stale_versions_and_permissions_without_writes(): void
    {
        [$source, $sourceLease] = $this->page();
        [$destination, $lease] = $this->page(new Document);
        $clipboard = app(ClipboardCommands::class);
        foreach ([[(string) Str::uuid(), 0], [$sourceLease->token, 2]] as [$token, $version]) {
            try {
                $clipboard->capture($this->actor, $source->id, $token, $version, 'root', (string) Str::uuid(), 'en');
                $this->fail('Capture requires current source lease/version.');
            } catch (WriteConflict) {
                $this->assertSame(0, DB::table('site_builder_clipboard_snapshots')->count());
            }
        }
        $text = $this->capture($source, $sourceLease)->toJson();
        foreach ([[(string) Str::uuid(), 0], [$lease->token, 1]] as [$token, $version]) {
            try {
                $clipboard->paste($this->actor, $destination->id, $token, $version, $text, InsertPosition::end(), 'en');
                $this->fail('Paste requires current destination lease/version.');
            } catch (WriteConflict) {
                $this->assertSame(0, $destination->fresh()->write_version);
            }
        }
        $this->actor->denied = ['update'];
        try {
            $this->paste($destination, $lease, $text);
            $this->fail('Paste requires destination update.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $destination->fresh()->write_version);
        }
        $this->expectException(AuthorizationException::class);
        $this->capture($source, $sourceLease);
    }

    public function test_altered_nodes_provenance_capabilities_reference_manifest_and_foreign_namespace_never_gain_admission(): void
    {
        [$source, $sourceLease] = $this->page();
        [$destination, $lease] = $this->page(new Document);
        $text = $this->capture($source, $sourceLease)->toJson();
        $mutators = [
            function ($payload): void {
                $payload->nodes[0]->props->heading = 'Forged';
            },
            function ($payload): void {
                $payload->source->digest = str_repeat('0', 64);
            },
            function ($payload): void {
                $payload->source->pageId = 983;
            },
            function ($payload): void {
                $payload->source->snapshotId = (string) Str::uuid();
            },
            function ($payload): void {
                $payload->source->namespace = str_repeat('0', 64);
            },
            function ($payload): void {
                $payload->requiredCapabilities->document = 42;
            },
            function ($payload): void {
                $payload->requiredCapabilities->{'render.execute'} = 1;
            },
            function ($payload): void {
                $payload->references->pages[] = (object) ['value' => 2];
            },
            function ($payload): void {
                $payload->extensions->authorization = 'forged';
            },
        ];
        foreach ($mutators as $mutate) {
            $payload = json_decode($text);
            $mutate($payload);
            try {
                $this->paste($destination, $lease, json_encode($payload, JSON_THROW_ON_ERROR));
                $this->fail('Client content cannot replace the retained immutable source.');
            } catch (ValidationException) {
                $this->assertSame(0, $destination->fresh()->write_version);
                $this->assertCount(0, $destination->fresh()->draft_content->nodes);
            }
        }
        foreach (['<script>bad()</script>', '{', '{"format":"laravel-site-builder/clipboard","version":2}', str_repeat(' ', 1024 * 1024 + 1)] as $invalid) {
            try {
                $this->paste($destination, $lease, $invalid);
                $this->fail('Malformed/unsupported/oversized external text is not an admission source.');
            } catch (ValidationException) {
                $this->assertSame(0, $destination->fresh()->write_version);
            }
        }
    }

    public function test_exact_actor_type_ownership_and_source_deletion_cannot_be_bypassed_by_numeric_id_reuse(): void
    {
        [$source, $sourceLease] = $this->page();
        $envelope = $this->capture($source, $sourceLease);
        $other = new class(['id' => 7, 'denied' => [], 'deniedPreview' => []]) extends GenericUser {};
        [$otherPage, $otherLease] = $this->page(new Document, $other);
        try {
            app(ClipboardCommands::class)->paste($other, $otherPage->id, $otherLease->token, 0, $envelope->toJson(), InsertPosition::end(), 'en');
            $this->fail('An equal ID in another actor model is not the clipboard owner.');
        } catch (ValidationException) {
            $this->assertSame(0, $otherPage->fresh()->write_version);
        }
        $id = $source->id;
        $source->delete();
        $this->assertNull(DB::table('site_builder_clipboard_snapshots')->where('id', $envelope->snapshotId)->value('source_page_id'));
        Page::factory()->create(['id' => $id, 'draft_content' => new Document]);
        [$destination, $lease] = $this->page(new Document);
        $this->expectException(ValidationException::class);
        $this->paste($destination, $lease, $envelope->toJson());
    }

    public function test_expiry_count_and_byte_quotas_evict_whole_oldest_snapshots_and_pruning_never_changes_pages(): void
    {
        config(['site-builder.clipboard.snapshots_per_actor' => 2, 'site-builder.clipboard.ttl_seconds' => 10]);
        [$source, $sourceLease] = $this->page();
        [$destination, $lease] = $this->page(new Document);
        $first = $this->capture($source, $sourceLease);
        $second = $this->capture($source, $sourceLease);
        $third = $this->capture($source, $sourceLease);
        $this->assertFalse(DB::table('site_builder_clipboard_snapshots')->where('id', $first->snapshotId)->exists());
        $this->assertTrue(DB::table('site_builder_clipboard_snapshots')->where('id', $second->snapshotId)->exists());
        $this->assertTrue(DB::table('site_builder_clipboard_snapshots')->where('id', $third->snapshotId)->exists());
        $this->assertSame(2, DB::table('site_builder_clipboard_snapshots')->count());
        $charge = (int) DB::table('site_builder_clipboard_snapshots')->where('id', $third->snapshotId)->value('storage_bytes');
        config(['site-builder.clipboard.bytes_per_actor' => $charge + 100]);
        $fourth = $this->capture($source, $sourceLease);
        $this->assertSame(1, DB::table('site_builder_clipboard_snapshots')->count());
        $this->assertSame($fourth->snapshotId, DB::table('site_builder_clipboard_snapshots')->value('id'));
        $this->travel(11)->seconds();
        try {
            $this->paste($destination, $lease, $fourth->toJson());
            $this->fail('Expired content cannot be pasted before pruning.');
        } catch (ValidationException) {
            $this->assertSame(0, $destination->fresh()->write_version);
        }
        $this->assertSame(1, app(ClipboardCommands::class)->prune());
        $this->assertSame(0, DB::table('site_builder_clipboard_snapshots')->count());
        $this->assertSame(0, DB::table('site_builder_clipboard_owners')->count());
        $this->assertSame(0, $source->fresh()->write_version);
        $this->assertSame(0, $destination->fresh()->write_version);
        $this->travelBack();
    }

    public function test_hard_limits_and_capture_failures_leave_existing_snapshots_untouched(): void
    {
        config(['site-builder.clipboard.ttl_seconds' => 999999, 'site-builder.clipboard.snapshots_per_actor' => 999999, 'site-builder.clipboard.bytes_per_actor' => 999999999, 'site-builder.clipboard.max_envelope_bytes' => 999999999]);
        $limits = new ClipboardLimits;
        $this->assertSame([7200, 50, 32 * 1024 * 1024, 2 * 1024 * 1024], [$limits->ttlSeconds, $limits->snapshots, $limits->bytes, $limits->envelopeBytes]);
        [$source, $sourceLease] = $this->page();
        $retained = $this->capture($source, $sourceLease);
        config(['site-builder.clipboard.max_envelope_bytes' => 512]);
        try {
            $this->capture($source, $sourceLease);
            $this->fail('Oversized capture must not evict previous usable snapshots.');
        } catch (ValidationException) {
            $this->assertSame([$retained->snapshotId], DB::table('site_builder_clipboard_snapshots')->pluck('id')->all());
        }
    }

    public function test_trusted_unknown_content_preserves_opaque_shapes_and_new_ids_but_generic_save_and_publication_remain_strict(): void
    {
        $opaque = new JsonObject(['object' => (object) [], 'list' => [], 'null' => null, 'unchangedNumber' => 123]);
        [$source, $sourceLease] = $this->page(new Document([new Node('root', 'future.block', 9, $opaque, new JsonObject(['de' => (object) ['opaque' => $opaque->toObject()]]), 'hidden', [new Node('child', 'future.child', 2, $opaque)], $opaque)], $opaque));
        [$destination, $lease] = $this->page(new Document);
        $result = $this->paste($destination, $lease, $this->capture($source, $sourceLease)->toJson());
        $node = $result->mutation->page->draft_content->nodes[0];
        $this->assertNotSame('root', $node->id);
        $this->assertNotSame('child', $node->children[0]->id);
        $this->assertSame('hidden', $node->visibility);
        $this->assertTrue(app(CanonicalJson::class)->equals($opaque, $node->props));
        $this->assertTrue(app(CanonicalJson::class)->equals($opaque, $node->extensions));
        $this->assertContains('opaque_reference_schema', array_column($result->references->diagnostics, 'code'));
        try {
            app(PageCommands::class)->saveDraft($this->actor, $destination->id, $lease->token, 1, new Document([new Node($node->id, 'future.block', 9, new JsonObject(['forged' => true]))]));
            $this->fail('Trusted clipboard admission does not weaken generic unknown preservation.');
        } catch (ValidationException) {
            $this->assertSame(1, $destination->fresh()->write_version);
        }
        $this->expectException(InvalidDocument::class);
        app(PageCommands::class)->publish($this->actor, $destination->id, $lease->token, 1, $result->mutation->draftDigest);
    }

    public function test_unavailable_required_root_or_node_render_capabilities_cannot_be_captured(): void
    {
        foreach ([true, false] as $rootFeature) {
            $feature = new JsonObject(['site-builder.render' => (object) ['version' => 1, 'future' => (object) ['version' => 3, 'required' => true]]]);
            [$page, $lease] = $this->page(new Document([new Node('root', 'test.composition', extensions: $rootFeature ? new JsonObject : $feature)], $rootFeature ? $feature : new JsonObject));
            try {
                $this->capture($page, $lease);
                $this->fail('Unknown required features cannot be stripped or exported as supported content.');
            } catch (ValidationException) {
                $this->assertSame(0, DB::table('site_builder_clipboard_snapshots')->count());
                $this->assertSame(0, $page->fresh()->write_version);
            }
        }
    }

    public function test_typed_references_are_explicitly_mapped_across_domains_and_unverified_media_cannot_be_acknowledged(): void
    {
        app()->bind(ClipboardDomainResolver::class, fn () => new class implements ClipboardDomainResolver
        {
            public function domain(Authenticatable $actor, Page $page): string
            {
                return $page->title === 'Other domain' ? 'other' : 'main';
            }
        });
        [$target] = $this->page();
        [$otherTarget] = $this->page();
        $otherTarget->forceFill(['title' => 'Other domain'])->save();
        $doc = new Document([new Node('root', 'test.composition', props: new JsonObject(['target' => $target->id, 'image' => '/host/unverified.jpg', 'opaquePage' => $target->id]), translations: new JsonObject(['de' => (object) ['items' => [(object) ['label' => 'Nested', 'target' => $target->id]]]]))]);
        [$source, $sourceLease] = $this->page($doc);
        [$destination, $lease] = $this->page(new Document);
        $destination->forceFill(['title' => 'Other domain'])->save();
        $text = $this->capture($source, $sourceLease)->toJson();
        $clipboard = app(ClipboardCommands::class);
        $review = $clipboard->review($this->actor, $destination->id, $lease->token, 0, $text, 'en');
        $this->assertTrue($review->references->blocking);
        $map = array_map(fn ($ref): array => ['nodeId' => $ref['nodeId'], 'path' => $ref['path'], 'kind' => $ref['kind'], 'original' => $ref['original'], 'value' => $ref['kind'] === 'page' ? $target->id : $ref['original'], 'keepUnverified' => true], $review->references->occurrences);
        $stillBlocked = $clipboard->review($this->actor, $destination->id, $lease->token, 0, $text, 'en', ReferenceMap::fromArray($map));
        $this->assertTrue($stillBlocked->references->blocking, 'Wrong-domain pages and acknowledged unverified media must remain blocked.');
        foreach ($map as &$entry) {
            $entry['value'] = $entry['kind'] === 'page' ? $otherTarget->id : null;
        }
        unset($entry);
        $resolved = ReferenceMap::fromArray($map);
        $this->assertFalse($clipboard->review($this->actor, $destination->id, $lease->token, 0, $text, 'en', $resolved)->references->blocking);
        $result = $this->paste($destination, $lease, $text, $resolved);
        $node = $result->mutation->page->draft_content->nodes[0];
        $this->assertSame($otherTarget->id, $node->props->get('target'));
        $this->assertNull($node->props->get('image'));
        $this->assertSame($target->id, $node->props->get('opaquePage'), 'Never guess a reference in undeclared data.');
        $this->assertSame($otherTarget->id, $node->translations->get('de')->items[0]->target);
    }

    public function test_cross_domain_unknown_schemas_are_refused_and_trusted_media_inspector_can_authorize_public_assets(): void
    {
        app()->bind(ClipboardDomainResolver::class, fn () => new class implements ClipboardDomainResolver
        {
            public function domain(Authenticatable $actor, Page $page): string
            {
                return (string) $page->id;
            }
        });
        app()->bind('clipboard-test-media', fn () => new class implements CopyReferenceInspector
        {
            public function inspect(Authenticatable $actor, Reference $reference, CopyContext $destination): ?ReferenceCheck
            {
                return $reference->kind === 'media' && $reference->value === '/public/owned.jpg' ? ReferenceCheck::valid('Trusted public shared asset.') : null;
            }
        });
        app()->tag('clipboard-test-media', CopyReferenceInspector::class);
        [$source, $sourceLease] = $this->page(new Document([new Node('root', 'future.block')]));
        [$destination, $lease] = $this->page(new Document);
        $clipboard = app(ClipboardCommands::class);
        $blocked = $clipboard->review($this->actor, $destination->id, $lease->token, 0, $this->capture($source, $sourceLease)->toJson(), 'en');
        $this->assertTrue($blocked->references->blocking);
        $this->assertContains('opaque_cross_domain_source', array_column($blocked->references->diagnostics, 'code'));
        [$known, $knownLease] = $this->page(new Document([new Node('root', 'test.composition', props: new JsonObject(['image' => '/public/owned.jpg']))]));
        $text = $this->capture($known, $knownLease)->toJson();
        $map = ReferenceMap::fromArray([['nodeId' => 'root', 'path' => '/props/image', 'kind' => 'media', 'original' => '/public/owned.jpg', 'value' => '/public/owned.jpg']]);
        $this->assertFalse($clipboard->review($this->actor, $destination->id, $lease->token, 0, $text, 'en', $map)->references->blocking);
    }

    public function test_host_export_policy_can_refuse_opaque_protected_data_without_silently_stripping_it(): void
    {
        app()->bind(ClipboardExportPolicy::class, fn () => new class implements ClipboardExportPolicy
        {
            public function assertExportable(Authenticatable $actor, Page $source, Document $document): void
            {
                if ($document->nodes[0]->props->get('protected') !== null) {
                    throw new AuthorizationException('Host protected component data cannot leave this draft.');
                }
            }

            public function assertTransferable(Authenticatable $actor, Page $source, Page $destination, Document $document): void {}
        });
        [$source, $lease] = $this->page(new Document([new Node('root', 'future.block', props: new JsonObject(['protected' => 'opaque-host-secret']))]));
        try {
            $this->capture($source, $lease);
            $this->fail('A host can explicitly refuse clipboard export of protected fields.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('site_builder_clipboard_snapshots')->count());
            $this->assertSame('opaque-host-secret', $source->fresh()->draft_content->nodes[0]->props->get('protected'));
        }
    }

    public function test_full_destination_limits_and_missing_insert_anchors_fail_atomically(): void
    {
        [$source, $sourceLease] = $this->page();
        [$destination, $lease] = $this->page();
        $text = $this->capture($source, $sourceLease)->toJson();
        try {
            app(ClipboardCommands::class)->paste($this->actor, $destination->id, $lease->token, 0, $text, InsertPosition::before('removed'), 'en');
            $this->fail('A stale insertion anchor cannot silently become append.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, $destination->fresh()->write_version);
        }
        app()->instance(DocumentCodec::class, new DocumentCodec(maxNodes: 1));
        try {
            $this->paste($destination, $lease, $text);
            $this->fail('The entire destination document must obey node limits.');
        } catch (InvalidDocument) {
            $this->assertSame(0, $destination->fresh()->write_version);
            $this->assertCount(1, $destination->fresh()->draft_content->nodes);
        }
    }

    public function test_pasted_node_design_stays_active_while_source_page_design_remains_inactive_provenance(): void
    {
        app(ComponentRegistry::class)->register(RenderBlock::class);
        app(DesignCatalog::class)->register(new TestDesignTheme);
        app(DesignCatalog::class)->bindDefault(fn () => new ThemeIdentity('test.theme', 1, 'test'));
        $operations = app(DesignOperations::class);
        $context = new RenderContext('en', 'preview');
        $sourceDocument = $operations->set(new Document([new Node('root', 'test.block')]), DesignTarget::document(), 'width', 'narrow', $context);
        $sourceDocument = $operations->set($sourceDocument, DesignTarget::node('root', 'compact'), 'align', 'center', $context);
        $destinationDocument = $operations->setTheme(new Document, new ThemeIdentity('test.theme', 1, 'alternate'), $context);
        $destinationDocument = $operations->set($destinationDocument, DesignTarget::document(), 'width', 'wide', $context);
        [$source, $sourceLease] = $this->page($sourceDocument);
        [$destination, $lease] = $this->page($destinationDocument);
        $envelope = $this->capture($source, $sourceLease);
        $this->assertSame(1, json_decode($envelope->toJson())->requiredCapabilities->{'render.design'});
        $result = $this->paste($destination, $lease, $envelope->toJson());
        $document = $result->mutation->page->draft_content;
        $node = $document->nodes[0];
        $design = $document->extensions->get('site-builder.render')->design;
        $this->assertSame('alternate', $design->theme->preset);
        $this->assertSame('wide', $design->base->width);
        $this->assertTrue(app(CanonicalJson::class)->equals($sourceDocument->nodes[0]->extensions, $node->extensions));
        $state = app(DesignResolver::class)->resolve($document, $node, $context);
        $this->assertSame('wide', $state->states['base']['values']['width']);
        $this->assertSame('center', $state->states['compact']['values']['align']);
        $provenance = $document->extensions->get('site-builder.copy')->entries[0]->sourceDocumentExtensions;
        $this->assertSame('narrow', $provenance->{'site-builder.render'}->design->base->width);
        $this->assertSame('test', $provenance->{'site-builder.render'}->design->theme->preset);
    }

    public function test_paste_is_one_atomic_history_transition_and_redo_restores_the_same_ids_without_reimporting(): void
    {
        $this->installHistory();
        [$source, $sourceLease] = $this->page();
        $envelope = $this->capture($source, $sourceLease);
        foreach ([null, new Document] as $initial) {
            $destination = Page::factory()->create(['draft_content' => $initial])->fresh();
            $lease = app(PageLocks::class)->acquire($this->actor, $destination->id);
            $history = app(DraftHistory::class);
            $session = $history->open($this->actor, $destination->id, $lease->token, (string) Str::uuid());
            $result = app(ClipboardCommands::class)->paste($this->actor, $destination->id, $lease->token, 0, $envelope->toJson(), InsertPosition::end(), 'en', history: $session->context);
            $accepted = $history->status($this->actor, $destination->id, $lease->token, $session->context);
            $this->assertSame(1, $accepted->status['undoCount']);
            $this->assertSame('Paste section', $accepted->status['undoLabel']);
            $this->assertSame(1, $result->mutation->page->write_version);
            $undone = $history->undo($this->actor, $destination->id, $lease->token, 1, $accepted->context);
            $this->assertTrue(app(CanonicalJson::class)->equals($initial, $undone->mutation->page->draft_content));
            $redone = $history->redo($this->actor, $destination->id, $lease->token, 2, $undone->history->context);
            $this->assertSame($result->insertedIds[0], $redone->mutation->page->draft_content->nodes[0]->id);
            $this->assertSame($result->mutation->draftDigest, $redone->mutation->draftDigest);
            $this->assertSame(3, $redone->mutation->page->write_version);
        }
    }

    public function test_history_persistence_failure_rolls_back_the_entire_clipboard_paste(): void
    {
        $this->installHistory();
        [$source, $sourceLease] = $this->page();
        [$destination, $lease] = $this->page(new Document);
        $envelope = $this->capture($source, $sourceLease);
        $session = app(DraftHistory::class)->open($this->actor, $destination->id, $lease->token, (string) Str::uuid());
        HistoryRecord::saving(function (HistoryRecord $row): void {
            if ($row->sequence > 0) {
                throw new \RuntimeException('Simulated history persistence failure.');
            }
        });
        try {
            app(ClipboardCommands::class)->paste($this->actor, $destination->id, $lease->token, 0, $envelope->toJson(), InsertPosition::end(), 'en', history: $session->context);
            $this->fail('History failure cannot leave an unjournalled accepted paste.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated history persistence failure.', $exception->getMessage());
            $this->assertSame(0, $destination->fresh()->write_version);
            $this->assertCount(0, $destination->fresh()->draft_content->nodes);
            $this->assertSame(0, HistoryRecord::findOrFail($session->context->id)->sequence);
            $this->assertSame(0, HistoryRecord::findOrFail($session->context->id)->cursor);
        } finally {
            HistoryRecord::flushEventListeners();
        }
    }

    public function test_stale_history_coordinates_are_refused_before_host_export_and_copy_hooks(): void
    {
        $this->installHistory();
        [$source, $sourceLease] = $this->page();
        [$destination, $lease] = $this->page(new Document);
        $text = $this->capture($source, $sourceLease)->toJson();
        $session = app(DraftHistory::class)->open($this->actor, $destination->id, $lease->token, (string) Str::uuid());
        $policy = new class implements ClipboardExportPolicy
        {
            public int $calls = 0;

            public function assertExportable(Authenticatable $actor, Page $source, Document $document): void
            {
                $this->calls++;
            }

            public function assertTransferable(Authenticatable $actor, Page $source, Page $destination, Document $document): void
            {
                $this->calls++;
            }
        };
        app()->instance(ClipboardExportPolicy::class, $policy);
        $forged = new HistoryContext($session->context->id, $session->context->epoch, 1, 0);
        try {
            app(ClipboardCommands::class)->paste($this->actor, $destination->id, $lease->token, 0, $text, InsertPosition::end(), 'en', history: $forged);
            $this->fail('A stale history cursor cannot invoke export/copy hooks.');
        } catch (WriteConflict $exception) {
            $this->assertSame('history_changed', $exception->reason);
            $this->assertSame(0, $policy->calls);
            $this->assertSame(0, $destination->fresh()->write_version);
        }
    }

    public function test_a_genuine_envelope_from_another_application_origin_is_not_admitted_by_equal_page_ids(): void
    {
        [$source, $sourceLease] = $this->page();
        [$destination, $lease] = $this->page(new Document);
        $text = $this->capture($source, $sourceLease)->toJson();
        config(['app.url' => 'https://another-application.example']);
        try {
            $this->paste($destination, $lease, $text);
            $this->fail('Equal database IDs are not a foreign application admission proof.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('another installation', $exception->getMessage());
            $this->assertSame(0, $destination->fresh()->write_version);
        }
    }

    public function test_missing_private_and_unverified_media_have_distinct_same_domain_resolution_rules(): void
    {
        app()->bind('clipboard-private-media', fn () => new class implements CopyReferenceInspector
        {
            public function inspect(Authenticatable $actor, Reference $reference, CopyContext $destination): ?ReferenceCheck
            {
                return match ($reference->value) {
                    '/host/private.jpg' => ReferenceCheck::unauthorized('Private media cannot be exported.'),
                    '/host/missing.jpg' => ReferenceCheck::missing('Media is missing.'),
                    default => null,
                };
            }
        });
        app()->tag('clipboard-private-media', CopyReferenceInspector::class);
        [$source, $sourceLease] = $this->page(new Document([new Node('root', 'test.composition', props: new JsonObject(['image' => '/host/private.jpg', 'items' => [(object) ['image' => '/host/missing.jpg'], (object) ['image' => '/host/unverified.jpg']]]))]));
        [$destination, $lease] = $this->page(new Document);
        $text = $this->capture($source, $sourceLease)->toJson();
        $clipboard = app(ClipboardCommands::class);
        $review = $clipboard->review($this->actor, $destination->id, $lease->token, 0, $text, 'en');
        $this->assertSame(['unauthorized', 'missing', 'unverified'], array_column($review->references->occurrences, 'status'));
        $map = array_map(fn ($reference): array => ['nodeId' => $reference['nodeId'], 'path' => $reference['path'], 'kind' => $reference['kind'], 'original' => $reference['original'], 'value' => $reference['original'], 'keepUnverified' => true], $review->references->occurrences);
        $this->assertTrue($clipboard->review($this->actor, $destination->id, $lease->token, 0, $text, 'en', ReferenceMap::fromArray($map))->references->blocking, 'Acknowledgement cannot approve missing/private assets.');
        foreach ($map as &$entry) {
            if ($entry['original'] !== '/host/unverified.jpg') {
                $entry['value'] = null;
            }
        }
        unset($entry);
        $result = $this->paste($destination, $lease, $text, ReferenceMap::fromArray($map));
        $this->assertNull($result->mutation->page->draft_content->nodes[0]->props->get('image'));
        $this->assertSame('/host/unverified.jpg', $result->mutation->page->draft_content->nodes[0]->props->get('items')[1]->image);
    }

    public function test_transfer_policy_and_source_domain_are_rechecked_after_capture(): void
    {
        [$source, $sourceLease] = $this->page();
        [$destination, $lease] = $this->page(new Document);
        $text = $this->capture($source, $sourceLease)->toJson();
        app()->instance(ClipboardExportPolicy::class, new class implements ClipboardExportPolicy
        {
            public function assertExportable(Authenticatable $actor, Page $source, Document $document): void {}

            public function assertTransferable(Authenticatable $actor, Page $source, Page $destination, Document $document): void
            {
                throw new AuthorizationException('Host transfer permission revoked.');
            }
        });
        try {
            $this->paste($destination, $lease, $text);
            $this->fail('Capture never grants an enduring host transfer permission.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $destination->fresh()->write_version);
        }
        app()->bind(ClipboardDomainResolver::class, fn () => new class implements ClipboardDomainResolver
        {
            public function domain(Authenticatable $actor, Page $page): string
            {
                return 'changed-domain';
            }
        });
        try {
            $this->paste($destination, $lease, $text);
            $this->fail('Moving the original source between content domains invalidates its old clipboard.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('changed content domain', $exception->getMessage());
            $this->assertSame(0, $destination->fresh()->write_version);
            $this->assertSame(1, DB::table('site_builder_clipboard_snapshots')->count());
        }
    }

    private function installHistory(): void
    {
        $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/history-migrations'), '--realpath' => true, '--force' => true])->assertExitCode(0);
    }
}
