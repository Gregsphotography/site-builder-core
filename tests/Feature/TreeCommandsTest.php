<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use SiteBuilder\Core\Clipboard\ClipboardCommands;
use SiteBuilder\Core\Components\ChildPolicy;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\PlacementPolicy;
use SiteBuilder\Core\Composition\CompositionCommands;
use SiteBuilder\Core\Composition\InsertPosition;
use SiteBuilder\Core\Composition\SourceSelection;
use SiteBuilder\Core\Composition\StartingPointDescriptor;
use SiteBuilder\Core\Composition\StartingPointRegistry;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\DraftHistory\DraftHistory;
use SiteBuilder\Core\DraftHistory\HistoryRecord;
use SiteBuilder\Core\Editing\TreeCommands;
use SiteBuilder\Core\Editing\TreeOperations;
use SiteBuilder\Core\Editing\TreePosition;
use SiteBuilder\Core\Editing\TreeResolver;
use SiteBuilder\Core\Editing\TreeSlot;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\PortalUser;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;
use SiteBuilder\Core\Tests\Fixtures\TreeLayout;

class TreeCommandsTest extends DatabaseTestCase
{
    private GenericUser $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/history-migrations'), '--realpath' => true, '--force' => true])->assertExitCode(0);
        config(['site-builder.locales' => ['en' => [], 'de' => []]]);
        foreach (['create', 'update', 'publish', 'restore', 'preview', 'delete', 'view'] as $ability) {
            Gate::define($ability, fn ($actor): bool => $actor->allowed === true);
        }
        $this->actor = new GenericUser(['id' => (string) Str::ulid(), 'allowed' => true]);
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
        $registry = app(ComponentRegistry::class);
        $registry->register(RenderBlock::class);
        $types = ['tree.section', 'tree.columns', 'tree.column', 'test.block', 'future.block'];
        $registry->register(new TreeLayout('tree.section', ChildPolicy::types($types), PlacementPolicy::anywhere(structural: true)));
        $registry->register(new TreeLayout('tree.column', ChildPolicy::types($types), PlacementPolicy::childOnly(structural: true)));
        $registry->register(new TreeLayout('tree.columns', ChildPolicy::types(['tree.column'], 1, 4), PlacementPolicy::anywhere(structural: true), [new Node('default-column', 'tree.column', children: [new Node('default-text', 'test.block')])]));
        TreeLayout::$defaultCalls = 0;
    }

    private function document(bool $unknown = false): Document
    {
        $opaque = new JsonObject(['host' => (object) ['empty' => (object) [], 'list' => [], 'nil' => null, 'order' => ['third', 'first']]]);
        $text = new Node('leaf', 'test.block', props: $opaque, translations: new JsonObject(['en' => (object) ['heading' => 'Original'], 'de' => (object) ['heading' => 'Deutsch']]), extensions: $opaque);

        return new Document([
            new Node('section', 'tree.section', children: [new Node('columns', 'tree.columns', children: [new Node('column-a', 'tree.column', children: [$text, ...($unknown ? [new Node('unknown', 'future.block', props: $opaque, children: [new Node('unknown-child', 'future.block')])] : [])]), new Node('column-b', 'tree.column')])]),
            new Node('outside', 'test.block'),
        ], $opaque);
    }

    private function opened(?Document $document = null): array
    {
        $document ??= $this->document();
        $page = Page::factory()->create(['title' => 'Immediate metadata', 'draft_content' => $document, 'published_content' => $document]);
        $lease = app(PageLocks::class)->acquire($this->actor, $page->id);
        $history = app(DraftHistory::class)->open($this->actor, $page->id, $lease->token, (string) Str::uuid());

        return [$page, $lease, $history];
    }

    public function test_nested_operations_preserve_moved_identity_and_opaque_locales_and_duplicate_all_descendants(): void
    {
        [$page, $lease, $history] = $this->opened();
        $old = $page->draft_content;
        $tree = app(TreeResolver::class);
        $leaf = $tree->locate($old, 'leaf');
        $this->assertSame([0, 0, 0, 0], $leaf->path);
        $this->assertSame(['section', 'columns', 'column-a'], array_map(fn ($node): string => $node->id, $leaf->ancestors));
        $commands = app(TreeCommands::class);
        $moved = $commands->move($this->actor, $page->id, $lease->token, 0, 'leaf', TreeSlot::children('column-a'), TreeSlot::children('column-b'), TreePosition::start(), $history->context);
        $this->assertSame(['leaf'], $moved->selectedIds);
        $this->assertSame('column-b', $tree->locate($moved->mutation->page->draft_content, 'leaf')->parentId);
        $this->assertTrue(app(CanonicalJson::class)->equals($leaf->node, $tree->locate($moved->mutation->page->draft_content, 'leaf')->node));
        $head = app(DraftHistory::class)->status($this->actor, $page->id, $lease->token, $history->context);
        $copied = $commands->duplicate($this->actor, $page->id, $lease->token, 1, 'columns', TreeSlot::children('section'), $head->context);
        $clone = $tree->locate($copied->mutation->page->draft_content, $copied->selectedIds[0])->node;
        $this->assertNotSame('columns', $clone->id);
        $oldIds = array_keys($tree->nodes($old));
        foreach ($tree->nodes(new Document([$clone])) as $id => $location) {
            $this->assertNotContains($id, $oldIds);
            $this->assertTrue(Str::isUuid($id));
        }
        $clonedLeaf = $clone->children[1]->children[0];
        $this->assertSame('Deutsch', $clonedLeaf->translations->get('de')->heading);
        $this->assertTrue(app(CanonicalJson::class)->equals($leaf->node->props, $clonedLeaf->props));
        $this->assertSame('Immediate metadata', $page->fresh()->title);
        $this->assertTrue(app(CanonicalJson::class)->equals($old, $page->fresh()->published_content));
        $this->assertSame(2, $page->fresh()->write_version);
    }

    public function test_wrong_parents_anchors_cycles_counts_and_child_only_roots_reject_without_partial_writes(): void
    {
        [$page, $lease, $history] = $this->opened();
        $commands = app(TreeCommands::class);
        $actions = [
            fn () => $commands->move($this->actor, $page->id, $lease->token, 0, 'leaf', TreeSlot::root(), TreeSlot::children('column-b'), TreePosition::end(), $history->context),
            fn () => $commands->move($this->actor, $page->id, $lease->token, 0, 'section', TreeSlot::root(), TreeSlot::children('column-a'), TreePosition::end(), $history->context),
            fn () => $commands->move($this->actor, $page->id, $lease->token, 0, 'outside', TreeSlot::root(), TreeSlot::children('column-a'), TreePosition::before('column-b'), $history->context),
            fn () => $commands->add($this->actor, $page->id, $lease->token, 0, 'tree.column', TreeSlot::root(), TreePosition::end(), $history->context),
            fn () => $commands->add($this->actor, $page->id, $lease->token, 0, 'test.block', TreeSlot::children('columns'), TreePosition::end(), $history->context),
            fn () => $commands->reorder($this->actor, $page->id, $lease->token, 0, TreeSlot::children('columns'), ['column-a', 'column-a'], $history->context),
        ];
        $digest = app(CanonicalJson::class)->digest($page->draft_content);
        foreach ($actions as $action) {
            try {
                $action();
                $this->fail('Invalid tree coordinates/policy were accepted.');
            } catch (InvalidArgumentException|ValidationException) {
                $this->assertSame(0, $page->fresh()->write_version);
                $this->assertSame($digest, app(CanonicalJson::class)->digest($page->fresh()->draft_content));
                $this->assertSame(0, HistoryRecord::query()->sole()->cursor);
            }
        }
        $only = new Document([new Node('only-columns', 'tree.columns', children: [new Node('only-column', 'tree.column')])]);
        [$required, $requiredLease] = $this->opened($only);
        try {
            $commands->remove($this->actor, $required->id, $requiredLease->token, 0, 'only-column', TreeSlot::children('only-columns'));
            $this->fail('The final required column was removed.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, $required->fresh()->write_version);
            $this->assertCount(1, $required->fresh()->draft_content->nodes[0]->children);
        }
        $removedGroup = $commands->remove($this->actor, $required->id, $requiredLease->token, 0, 'only-columns', TreeSlot::root());
        $this->assertCount(0, $removedGroup->mutation->page->draft_content->nodes);
    }

    public function test_nested_unknown_move_remove_and_trusted_undo_keep_exact_bytes_but_duplication_and_descendant_edits_refuse(): void
    {
        [$page, $lease, $history] = $this->opened($this->document(true));
        $commands = app(TreeCommands::class);
        $moved = $commands->move($this->actor, $page->id, $lease->token, 0, 'unknown', TreeSlot::children('column-a'), TreeSlot::children('column-b'), TreePosition::end(), $history->context);
        $head = app(DraftHistory::class)->status($this->actor, $page->id, $lease->token, $history->context);
        foreach ([
            fn () => $commands->duplicate($this->actor, $page->id, $lease->token, 1, 'unknown', TreeSlot::children('column-b'), $head->context),
            fn () => $commands->remove($this->actor, $page->id, $lease->token, 1, 'unknown-child', TreeSlot::children('unknown'), $head->context),
            fn () => $commands->visibility($this->actor, $page->id, $lease->token, 1, 'unknown', TreeSlot::children('column-b'), false, $head->context),
        ] as $action) {
            try {
                $action();
                $this->fail('An unsupported subtree was patched or duplicated.');
            } catch (InvalidArgumentException) {
                $this->assertSame(1, $page->fresh()->write_version);
            }
        }
        $removed = $commands->remove($this->actor, $page->id, $lease->token, 1, 'unknown', TreeSlot::children('column-b'), $head->context);
        $this->assertSame(['column-b'], $removed->selectedIds);
        $current = app(DraftHistory::class)->status($this->actor, $page->id, $lease->token, $head->context);
        $restored = app(DraftHistory::class)->undo($this->actor, $page->id, $lease->token, 2, $current->context);
        $this->assertSame(3, $restored->mutation->page->write_version);
        $this->assertTrue(app(CanonicalJson::class)->equals($moved->mutation->page->draft_content, $restored->mutation->page->draft_content));
    }

    public function test_default_subtrees_are_fresh_and_guards_precede_host_defaults_and_failed_history_rolls_back(): void
    {
        [$page, $lease, $history] = $this->opened(new Document);
        $commands = app(TreeCommands::class);
        foreach ([
            [$this->actor, $lease->token, 9],
            [new GenericUser(['id' => $this->actor->id, 'allowed' => false]), $lease->token, 0],
            [new PortalUser(['id' => $this->actor->id, 'allowed' => true]), $lease->token, 0],
            [$this->actor, (string) Str::uuid(), 0],
        ] as [$actor, $token, $version]) {
            try {
                $commands->add($actor, $page->id, $token, $version, 'tree.columns', TreeSlot::root(), TreePosition::end(), $history->context);
                $this->fail('A tree write bypassed actor/lease/CAS admission.');
            } catch (AuthorizationException|WriteConflict) {
                $this->assertSame(0, TreeLayout::$defaultCalls);
                $this->assertSame(0, $page->fresh()->write_version);
            }
        }
        HistoryRecord::updating(fn () => throw new RuntimeException('Forced journal storage failure'));
        try {
            $commands->add($this->actor, $page->id, $lease->token, 0, 'tree.columns', TreeSlot::root(), TreePosition::end(), $history->context);
            $this->fail('A journal failure partially committed the page.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced journal storage failure', $exception->getMessage());
            $this->assertSame(0, $page->fresh()->write_version);
            $this->assertCount(0, $page->fresh()->draft_content->nodes);
        } finally {
            HistoryRecord::flushEventListeners();
        }
        $added = $commands->add($this->actor, $page->id, $lease->token, 0, 'tree.columns', TreeSlot::root(), TreePosition::end(), $history->context);
        $ids = array_keys(app(TreeResolver::class)->nodes($added->mutation->page->draft_content));
        $this->assertCount(3, $ids);
        $this->assertNotContains('default-column', $ids);
        $this->assertNotContains('default-text', $ids);
        $this->assertTrue(array_all($ids, fn ($id): bool => Str::isUuid($id)));
    }

    public function test_structural_physical_node_depth_and_byte_limits_count_all_hidden_opaque_content(): void
    {
        $ops = app(TreeOperations::class);
        $four = new Document([new Node('a', 'tree.section', children: [new Node('b', 'tree.section', children: [new Node('c', 'tree.section', children: [new Node('d', 'tree.section', children: [new Node('text', 'test.block')])])])])]);
        $deep = new Node('deep-32', 'test.block');
        for ($i = 31; $i > 0; $i--) {
            $deep = new Node('deep-'.$i, 'test.block', children: [$deep]);
        }
        $many = new Document(array_map(fn ($i): Node => new Node('hidden-'.$i, 'future.block', visibility: 'hidden'), range(1, 1000)));
        $large = new Document([new Node('large', 'test.block', props: new JsonObject(['opaque' => str_repeat('x', 1049000)]), visibility: 'hidden')]);
        foreach ([
            fn () => $ops->add($four, 'tree.section', TreeSlot::children('d'), TreePosition::end()),
            fn () => $ops->add(new Document([$deep]), 'test.block', TreeSlot::children('deep-32'), TreePosition::end()),
            fn () => $ops->add($many, 'test.block', TreeSlot::root(), TreePosition::end()),
            fn () => $ops->duplicate($large, 'large', TreeSlot::root()),
        ] as $operation) {
            try {
                $operation();
                $this->fail('A complete physical limit was bypassed.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_starting_point_preview_and_insertion_use_the_same_exact_nested_destination_and_new_ids(): void
    {
        [$page, $lease, $history] = $this->opened();
        $source = new Document([new Node('source', 'test.block', translations: new JsonObject(['en' => (object) ['heading' => 'Source'], 'de' => (object) ['heading' => 'Quelle']]))]);
        $registry = app(StartingPointRegistry::class);
        $registry->register(new StartingPointDescriptor('tree-source', 1, 'Tree source'), fn () => $source);
        $snapshot = $registry->snapshot('tree-source', 1, 'en');
        $selection = new SourceSelection($snapshot->kind, $snapshot->id, $snapshot->version, $snapshot->digest, 'en');
        $position = InsertPosition::in(TreeSlot::children('column-b'), TreePosition::start());
        $commands = app(CompositionCommands::class);
        $preview = $commands->previewContext($this->actor, $page->id, $selection, position: $position);
        $tree = app(TreeResolver::class);
        $this->assertSame('column-b', $tree->locate($preview->document, $preview->rootIds[0])->parentId);
        $this->assertSame(0, $page->fresh()->write_version);
        $this->assertFalse($commands->review($this->actor, $page->id, $lease->token, 0, $selection, position: $position)->blocking);
        $applied = $commands->insert($this->actor, $page->id, $lease->token, 0, $selection, $position, history: $history->context);
        $this->assertSame('column-b', $tree->locate($applied->mutation->page->draft_content, $applied->insertedIds[0])->parentId);
        $this->assertNotSame('source', $applied->insertedIds[0]);
        $this->assertSame('Quelle', $tree->locate($applied->mutation->page->draft_content, $applied->insertedIds[0])->node->translations->get('de')->heading);
        $this->assertTrue(app(CanonicalJson::class)->equals($page->published_content, $applied->mutation->page->published_content));
        $this->assertFalse(app(TreeOperations::class)->canInsertType($page->draft_content, 'test.block', TreeSlot::children('columns')));
        $this->assertTrue(app(TreeOperations::class)->canInsertNodes($page->draft_content, $source->nodes, TreeSlot::children('column-b')));
    }

    public function test_nested_clipboard_paste_preserves_context_and_undo_then_rejects_a_stale_destination(): void
    {
        $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/clipboard-migrations'), '--realpath' => true, '--force' => true])->assertExitCode(0);
        [$source, $sourceLease] = $this->opened();
        [$destination, $lease, $history] = $this->opened();
        $clipboard = app(ClipboardCommands::class);
        $envelope = $clipboard->capture($this->actor, $source->id, $sourceLease->token, 0, 'leaf', (string) Str::uuid(), 'en');
        $position = InsertPosition::in(TreeSlot::children('column-b'), TreePosition::end());
        $review = $clipboard->review($this->actor, $destination->id, $lease->token, 0, $envelope->toJson(), 'en', position: $position);
        $this->assertFalse($review->references->blocking);
        $result = $clipboard->paste($this->actor, $destination->id, $lease->token, 0, $envelope->toJson(), $position, 'en', history: $history->context);
        $copied = app(TreeResolver::class)->locate($result->mutation->page->draft_content, $result->insertedIds[0]);
        $this->assertSame('column-b', $copied->parentId);
        $this->assertNotSame('leaf', $copied->node->id);
        $this->assertSame('Deutsch', $copied->node->translations->get('de')->heading);
        $this->assertTrue(app(CanonicalJson::class)->equals(app(TreeResolver::class)->locate($source->draft_content, 'leaf')->node->props, $copied->node->props));
        $head = app(DraftHistory::class)->status($this->actor, $destination->id, $lease->token, $history->context);
        $undone = app(DraftHistory::class)->undo($this->actor, $destination->id, $lease->token, 1, $head->context);
        $this->assertTrue(app(CanonicalJson::class)->equals($destination->draft_content, $undone->mutation->page->draft_content));
        try {
            $clipboard->paste($this->actor, $destination->id, $lease->token, $review->destinationVersion, $envelope->toJson(), $position, 'en');
            $this->fail('An old clipboard review bypassed the destination version.');
        } catch (WriteConflict) {
            $this->assertSame(2, $destination->fresh()->write_version);
            $this->assertTrue(app(CanonicalJson::class)->equals($destination->published_content, $destination->fresh()->published_content));
        }
    }

    public function test_successful_nested_start_before_after_reorder_visibility_and_noop_keep_exact_siblings(): void
    {
        [$page, $lease, $history] = $this->opened();
        $commands = app(TreeCommands::class);
        $moved = $commands->move($this->actor, $page->id, $lease->token, 0, 'outside', TreeSlot::root(), TreeSlot::children('column-a'), TreePosition::after('leaf'), $history->context);
        $this->assertSame(['leaf', 'outside'], array_map(fn ($n) => $n->id, app(TreeResolver::class)->children($moved->mutation->page->draft_content, TreeSlot::children('column-a'))));
        $reordered = $commands->reorder($this->actor, $page->id, $lease->token, 1, TreeSlot::children('column-a'), ['outside', 'leaf']);
        $this->assertSame(['outside', 'leaf'], $reordered->selectedIds);
        $hidden = $commands->visibility($this->actor, $page->id, $lease->token, 2, 'leaf', TreeSlot::children('column-a'), false);
        $this->assertSame('hidden', app(TreeResolver::class)->locate($hidden->mutation->page->draft_content, 'leaf')->node->visibility);
        $noOp = $commands->move($this->actor, $page->id, $lease->token, 3, 'leaf', TreeSlot::children('column-a'), TreeSlot::children('column-a'), TreePosition::before('leaf'));
        $this->assertSame(3, $noOp->mutation->page->write_version);
        $added = $commands->add($this->actor, $page->id, $lease->token, 3, 'test.block', TreeSlot::children('column-a'), TreePosition::start());
        $this->assertSame($added->selectedIds[0], app(TreeResolver::class)->children($added->mutation->page->draft_content, TreeSlot::children('column-a'))[0]->id);
    }

    public function test_detached_child_only_sources_defer_only_root_placement_until_the_destination_is_validated(): void
    {
        [$page, $lease] = $this->opened();
        $registry = app(StartingPointRegistry::class);
        $registry->register(new StartingPointDescriptor('column-source', 1, 'Column source'), fn () => new Document([new Node('source-column', 'tree.column', children: [new Node('source-leaf', 'test.block')])]));
        $source = $registry->snapshot('column-source', 1, 'en');
        $selection = new SourceSelection($source->kind, $source->id, $source->version, $source->digest, 'en');
        $commands = app(CompositionCommands::class);
        $this->assertTrue($commands->review($this->actor, $page->id, $lease->token, 0, $selection)->blocking);
        $position = InsertPosition::in(TreeSlot::children('columns'), TreePosition::end());
        $this->assertFalse($commands->review($this->actor, $page->id, $lease->token, 0, $selection, position: $position)->blocking);
        $preview = $commands->previewContext($this->actor, $page->id, $selection, position: $position);
        $this->assertSame('columns', app(TreeResolver::class)->locate($preview->document, $preview->rootIds[0])->parentId);
        $applied = $commands->insert($this->actor, $page->id, $lease->token, 0, $selection, $position);
        $this->assertCount(3, app(TreeResolver::class)->locate($applied->mutation->page->draft_content, 'columns')->node->children);
        $registry->register(new StartingPointDescriptor('bad-column-source', 1, 'Invalid column'), fn () => new Document([new Node('bad-source-column', 'tree.column', children: [new Node('bad-source-leaf', 'test.block', props: new JsonObject(['tone' => 'invalid']))])]));
        $this->expectException(InvalidArgumentException::class);
        $registry->snapshot('bad-column-source', 1, 'en');
    }
}
