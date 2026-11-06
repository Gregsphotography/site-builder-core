<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\DraftHistory\DraftHistory;
use SiteBuilder\Core\DraftHistory\HistoryContext;
use SiteBuilder\Core\DraftHistory\HistoryRecord;
use SiteBuilder\Core\DraftHistory\HistorySession;
use SiteBuilder\Core\DraftHistory\LogicalAction;
use SiteBuilder\Core\Exceptions\InvalidDocument;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Exchange\ExchangeSchema;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;

class DraftHistoryTest extends DatabaseTestCase
{
    private GenericUser $actor;

    private DraftHistory $history;

    private PageCommands $commands;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/history-migrations'), '--realpath' => true, '--force' => true])->assertExitCode(0);
        app(ComponentRegistry::class)->register(RenderBlock::class);
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
        config(['site-builder.locales' => ['en' => [], 'de' => []]]);
        foreach (['create', 'update', 'publish', 'restore', 'preview', 'delete'] as $ability) {
            Gate::define($ability, fn ($actor): bool => $actor->allowed === true);
        }
        $this->actor = new GenericUser(['id' => (string) Str::ulid(), 'allowed' => true]);
        $this->history = app(DraftHistory::class);
        $this->commands = app(PageCommands::class);
    }

    private function doc(string $heading): Document
    {
        return new Document([new Node('stable', 'test.block', props: new JsonObject(['heading' => $heading]), translations: new JsonObject(['de' => (object) ['heading' => 'Deutsch']]))], new JsonObject(['opaque' => (object) ['empty' => (object) [], 'list' => [], 'nil' => null]]));
    }

    private function opened(?Document $document = null): array
    {
        $page = Page::factory()->create(['draft_content' => $document, 'published_content' => $this->doc('Public')]);
        $lease = app(PageLocks::class)->acquire($this->actor, $page->id);
        $session = $this->history->open($this->actor, $page->id, $lease->token, (string) Str::uuid());

        return [$page, $lease->token, $session];
    }

    private function edit(Page $page, string $token, HistorySession $session, Document $document, ?LogicalAction $action = null): HistorySession
    {
        $this->commands->saveDraft($this->actor, $page->id, $token, $page->fresh()->write_version, $document, $session->context, $action);

        return $this->history->status($this->actor, $page->id, $token, $session->context);
    }

    private function conflict(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Expected '.$reason);
        } catch (WriteConflict $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    public function test_adjacent_undo_redo_branch_and_shared_metadata_have_independent_monotonic_versions(): void
    {
        [$page, $token, $session] = $this->opened();
        $first = $this->edit($page, $token, $session, $this->doc('One'));
        $second = $this->edit($page, $token, $first, $this->doc('Two'));
        $this->assertSame(2, $second->status['undoCount']);
        $metadata = $this->commands->saveMetadata($this->actor, $page->id, $token, 2, ['title' => 'Shared title', 'seo' => (object) ['en' => (object) ['meta_title' => 'SEO']]], ['en' => 'new-url'], $second->context);
        $metaSession = $this->history->status($this->actor, $page->id, $token, $second->context);
        $this->assertSame(3, $metadata->page->write_version);
        $this->assertSame(2, $metaSession->status['undoCount']);
        $undo = $this->history->undo($this->actor, $page->id, $token, 3, $metaSession->context);
        $this->assertSame(4, $undo->mutation->page->write_version);
        $this->assertSame('Shared title', $undo->mutation->page->title);
        $this->assertSame('new-url', $page->slugs()->firstOrFail()->slug);
        $this->assertTrue(app(CanonicalJson::class)->equals($this->doc('One'), $undo->mutation->page->draft_content));
        $this->assertTrue(app(CanonicalJson::class)->equals($this->doc('Public'), $page->fresh()->published_content));
        $redo = $this->history->redo($this->actor, $page->id, $token, 4, $undo->history->context);
        $this->assertSame(5, $redo->mutation->page->write_version);
        $this->assertTrue(app(CanonicalJson::class)->equals($this->doc('Two'), $redo->mutation->page->draft_content));
        $undo = $this->history->undo($this->actor, $page->id, $token, 5, $redo->history->context);
        $branch = $this->edit($page, $token, $undo->history, $this->doc('Branch'));
        $this->assertSame(0, $branch->status['redoCount']);
        $this->conflict(fn () => $this->history->redo($this->actor, $page->id, $token, 7, $branch->context), 'history_unavailable');
        $this->assertSame(7, $page->fresh()->write_version);
    }

    public function test_undo_restores_exact_deleted_unsupported_subtree_without_weakening_generic_save(): void
    {
        $unknown = new Node('original', 'future.container', version: 99, props: new JsonObject(['secretlyOpaque' => (object) ['empty' => (object) [], 'array' => [], 'zero' => 0.0]]), translations: new JsonObject(['fr' => (object) ['text' => 'Français']]), visibility: 'hidden', children: [new Node('original-child', 'future.leaf', extensions: new JsonObject(['future' => (object) ['v' => 2]]))], extensions: new JsonObject(['site-builder.render' => (object) ['version' => 300, 'unknown' => true]]));
        $original = new Document([$unknown, ...$this->doc('Sibling')->nodes]);
        [$page, $token, $session] = $this->opened($original);
        $removed = $this->edit($page, $token, $session, $this->doc('Sibling'), new LogicalAction('remove', 'Remove section'));
        try {
            $this->commands->saveDraft($this->actor, $page->id, $token, 1, $original);
            $this->fail('Generic save admitted unknown removed content.');
        } catch (ValidationException) {
            $this->assertSame(1, $page->fresh()->write_version);
        }
        $undo = $this->history->undo($this->actor, $page->id, $token, 1, $removed->context);
        $this->assertTrue(app(CanonicalJson::class)->equals($original, $undo->mutation->page->draft_content));
        $this->assertSame('original-child', $undo->mutation->page->draft_content->nodes[0]->children[0]->id);
        $this->assertContains('unknown_component', array_column($undo->mutation->diagnostics, 'code'));
        try {
            $this->commands->publish($this->actor, $page->id, $token, 2, $undo->mutation->draftDigest);
            $this->fail('Unsupported content was published.');
        } catch (InvalidDocument) {
            $this->assertSame(1, $this->history->status($this->actor, $page->id, $token, $undo->history->context)->status['redoCount']);
        }
    }

    public function test_null_draft_exact_restoration_and_noop_do_not_create_fake_transitions(): void
    {
        [$page, $token, $session] = $this->opened();
        $saved = $this->edit($page, $token, $session, $this->doc('One'));
        $noop = $this->edit($page, $token, $saved, $this->doc('One'));
        $this->assertSame($saved->toArray(), $noop->toArray());
        $undo = $this->history->undo($this->actor, $page->id, $token, 1, $noop->context);
        $this->assertNull($undo->mutation->page->draft_content);
        $this->assertSame(2, $undo->mutation->page->write_version);
        $this->assertSame(1, $undo->history->status['redoCount']);
    }

    public function test_grouping_is_bounded_by_target_locale_properties_time_and_explicit_boundaries(): void
    {
        [$page, $token, $session] = $this->opened($this->doc('Start'));
        $action = new LogicalAction('typing', 'Edit heading', (string) Str::uuid(), 'stable', 'en', ['heading']);
        $one = $this->edit($page, $token, $session, $this->doc('One'), $action);
        $this->travel(2)->seconds();
        $two = $this->edit($page, $token, $one, $this->doc('Two'), $action);
        $this->assertSame(1, $two->status['undoCount']);
        $closed = $this->history->closeGroup($this->actor, $page->id, $token, $two->context);
        $three = $this->edit($page, $token, $closed, $this->doc('Three'), $action);
        $this->assertSame(2, $three->status['undoCount']);
        $this->travel(4)->seconds();
        $four = $this->edit($page, $token, $three, $this->doc('Four'), $action);
        $this->assertSame(3, $four->status['undoCount']);
        for ($i = 0; $i < 6; $i++) {
            $this->travel(3)->seconds();
            $four = $this->edit($page, $token, $four, $this->doc('Continuous '.$i), $action);
        }
        $this->assertSame(4, $four->status['undoCount']);
        $otherLocale = new LogicalAction('typing', 'Edit heading', $action->group, 'stable', 'de', ['heading']);
        $next = $this->edit($page, $token, $four, $this->doc('Different context'), $otherLocale);
        $this->assertSame(5, $next->status['undoCount']);
    }

    public function test_stale_cursor_epoch_external_writes_wrong_actor_model_and_lease_are_rejected_without_partial_save(): void
    {
        [$page, $token, $session] = $this->opened($this->doc('Start'));
        $next = $this->edit($page, $token, $session, $this->doc('Next'));
        $this->conflict(fn () => $this->history->undo($this->actor, $page->id, $token, 1, $session->context), 'history_changed');
        $this->conflict(fn () => $this->commands->saveDraft($this->actor, $page->id, $token, 1, $this->doc('Unacknowledged'), $session->context), 'history_changed');
        $this->assertSame(1, $page->fresh()->write_version);
        $this->conflict(fn () => $this->history->undo($this->actor, $page->id, $token, 0, $next->context), 'stale_version');
        $this->conflict(fn () => $this->history->undo($this->actor, $page->id, (string) Str::uuid(), 1, $next->context), 'lease_lost');
        $other = new class(['id' => $this->actor->id, 'allowed' => true]) extends GenericUser {};
        $this->conflict(fn () => $this->history->undo($other, $page->id, $token, 1, $next->context), 'lease_lost');
        $this->actor->allowed = false;
        try {
            $this->history->undo($this->actor, $page->id, $token, 1, $next->context);
            $this->fail('Policy revocation was ignored.');
        } catch (AuthorizationException) {
            $this->assertSame(1, $page->fresh()->write_version);
        }
        $this->actor->allowed = true;
        $this->commands->saveMetadata($this->actor, $page->id, $token, 1, ['title' => 'External']);
        $this->conflict(fn () => $this->history->status($this->actor, $page->id, $token, $next->context), 'history_page_changed');
        $this->conflict(fn () => $this->history->undo($this->actor, $page->id, $token, 2, $next->context), 'history_page_changed');
        $renewed = $this->history->open($this->actor, $page->id, $token, HistoryRecord::findOrFail($session->context->id)->tab_id, true);
        $this->assertNotSame($session->context->epoch, $renewed->context->epoch);
        $this->conflict(fn () => $this->history->undo($this->actor, $page->id, $token, 2, $next->context), 'history_unavailable');
    }

    public function test_failed_history_persistence_rolls_back_page_save_and_undo_cursor(): void
    {
        [$page, $token, $session] = $this->opened($this->doc('Start'));
        HistoryRecord::saving(fn () => throw new RuntimeException('Injected history failure'));
        try {
            $this->commands->saveDraft($this->actor, $page->id, $token, 0, $this->doc('Attempt'), $session->context);
            $this->fail('History save failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected history failure', $exception->getMessage());
            $this->assertSame(0, $page->fresh()->write_version);
            $this->assertTrue(app(CanonicalJson::class)->equals($this->doc('Start'), $page->fresh()->draft_content));
        } finally {
            HistoryRecord::flushEventListeners();
        }
        $next = $this->edit($page, $token, $session, $this->doc('Accepted'));
        HistoryRecord::saving(fn () => throw new RuntimeException('Injected undo failure'));
        try {
            $this->history->undo($this->actor, $page->id, $token, 1, $next->context);
            $this->fail('Undo save failure was swallowed.');
        } catch (RuntimeException) {
            $this->assertSame(1, $page->fresh()->write_version);
            $this->assertSame($next->context->cursor, HistoryRecord::findOrFail($next->context->id)->cursor);
        } finally {
            HistoryRecord::flushEventListeners();
        }
    }

    public function test_limits_expiry_pruning_and_publication_boundaries_only_remove_operational_history(): void
    {
        config(['site-builder.history.transitions' => 2, 'site-builder.history.ttl_seconds' => 10]);
        [$page, $token, $session] = $this->opened($this->doc('Start'));
        for ($i = 1; $i <= 4; $i++) {
            $session = $this->edit($page, $token, $session, $this->doc('Edit '.$i));
        }
        $this->assertSame(2, $session->status['undoCount']);
        $this->assertSame(2, $session->status['evicted']);
        $published = $this->commands->publish($this->actor, $page->id, $token, 4, app(CanonicalJson::class)->digest($page->fresh()->draft_content));
        $this->assertSame(0, HistoryRecord::count());
        $session = $this->history->open($this->actor, $page->id, $token, (string) Str::uuid());
        $this->commands->unpublish($this->actor, $page->id, $token, 5);
        $this->assertSame(0, HistoryRecord::count());
        $session = $this->history->open($this->actor, $page->id, $token, (string) Str::uuid());
        $this->commands->restore($this->actor, $page->id, $token, 6, $page->revisions()->firstOrFail()->id);
        $this->assertSame(0, HistoryRecord::count());
        $session = $this->history->open($this->actor, $page->id, $token, (string) Str::uuid());
        $this->travel(11)->seconds();
        $this->conflict(fn () => $this->history->undo($this->actor, $page->id, $token, 7, $session->context), 'history_unavailable');
        $this->artisan('site-builder:history-prune')->assertExitCode(0);
        $this->assertSame(0, HistoryRecord::count());
        $this->assertSame(7, $page->fresh()->write_version);
        $this->assertNotNull($page->fresh()->published_content);
        $this->assertSame(1, $page->revisions()->count());
    }

    public function test_snapshot_byte_budget_eviction_and_oversized_write_are_atomic(): void
    {
        config(['site-builder.history.max_bytes' => 1400]);
        [$page, $token, $session] = $this->opened($this->doc('Start'));
        $next = $this->edit($page, $token, $session, new Document($this->doc('Accepted')->nodes, new JsonObject(['large' => str_repeat('x', 600)])));
        $this->assertLessThanOrEqual(1, $next->status['undoCount']);
        $before = $page->fresh()->draft_content;
        $beforeRow = HistoryRecord::findOrFail($next->context->id)->getRawOriginal();
        try {
            $this->commands->saveDraft($this->actor, $page->id, $token, 1, new Document($this->doc('Too big')->nodes, new JsonObject(['large' => str_repeat('y', 1600)])), $next->context);
            $this->fail('An oversized history write was accepted.');
        } catch (ValidationException) {
            $this->assertSame(1, $page->fresh()->write_version);
            $this->assertTrue(app(CanonicalJson::class)->equals($before, $page->fresh()->draft_content));
            $this->assertSame($beforeRow, HistoryRecord::findOrFail($next->context->id)->getRawOriginal());
        }
    }

    public function test_forged_typing_context_cannot_group_structure_design_or_other_locale_changes(): void
    {
        [$page, $token, $session] = $this->opened($this->doc('Start'));
        $action = new LogicalAction('typing', 'Edit heading', (string) Str::uuid(), 'stable', 'en', ['heading']);
        $session = $this->edit($page, $token, $session, $this->doc('One'), $action);
        $otherLocale = new Document([new Node('stable', 'test.block', props: new JsonObject(['heading' => 'Two']), translations: new JsonObject(['de' => (object) ['heading' => 'Changed German']]))], $this->doc('')->extensions);
        $session = $this->edit($page, $token, $session, $otherLocale, $action);
        $this->assertSame(2, $session->status['undoCount']);
        $structural = new Document([...$otherLocale->nodes, new Node('extra', 'test.block', props: new JsonObject(['heading' => 'Extra']))], $otherLocale->extensions);
        $session = $this->edit($page, $token, $session, $structural, $action);
        $this->assertSame(3, $session->status['undoCount']);
        $session = $this->edit($page, $token, $session, new Document($structural->nodes, new JsonObject(['other' => true])), $action);
        $this->assertSame(4, $session->status['undoCount']);
    }

    public function test_each_tab_has_its_own_checkpoint_chain_and_cannot_rebase_after_another_tab_write(): void
    {
        [$page, $token, $first] = $this->opened($this->doc('Start'));
        $second = $this->history->open($this->actor, $page->id, $token, (string) Str::uuid());
        $this->assertNotSame($first->context->id, $second->context->id);
        $next = $this->edit($page, $token, $first, $this->doc('First tab'));
        $this->conflict(fn () => $this->history->undo($this->actor, $page->id, $token, 1, $second->context), 'history_page_changed');
        $this->assertSame(0, HistoryRecord::findOrFail($second->context->id)->cursor);
        $this->conflict(fn () => $this->history->undo($this->actor, $page->id, $token, 1, new HistoryContext($next->context->id, (string) Str::uuid(), $next->context->sequence, $next->context->cursor)), 'history_changed');
        $this->assertSame(1, $page->fresh()->write_version);
        $this->travel(301)->seconds();
        $this->conflict(fn () => $this->history->undo($this->actor, $page->id, $token, 1, $next->context), 'lease_lost');
    }

    public function test_noop_urls_do_not_advance_the_page_or_create_a_checkpoint(): void
    {
        [$page, $token, $session] = $this->opened($this->doc('Start'));
        $result = $this->commands->saveMetadata($this->actor, $page->id, $token, 0, [], ['en' => 'home'], $session->context);
        $session = $this->history->status($this->actor, $page->id, $token, $session->context);
        $this->assertSame(1, $result->page->write_version);
        $result = $this->commands->saveMetadata($this->actor, $page->id, $token, 1, [], ['en' => 'home'], $session->context);
        $this->assertSame(1, $result->page->write_version);
        $this->assertSame(0, $this->history->status($this->actor, $page->id, $token, $session->context)->status['undoCount']);
    }

    public function test_history_status_contains_no_checkpoints_tokens_or_private_content_and_installation_is_optional(): void
    {
        [$page, $token, $session] = $this->opened($this->doc('Private marker'));
        $next = $this->edit($page, $token, $session, $this->doc('Another private marker'));
        $public = json_encode($next->toArray(), JSON_THROW_ON_ERROR);
        foreach (['Private marker', 'Another private marker', $token, 'checkpoints', 'lease_hash', 'actor_id', 'published_content'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $public);
        }
        $this->assertSame(['context', 'status'], array_keys($next->toArray()));
        $this->assertNotContains('site_builder_draft_histories', app(ExchangeSchema::class)->tables());
        Schema::drop('site_builder_draft_histories');
        $this->assertFalse($this->history->available());
        $this->commands->saveDraft($this->actor, $page->id, $token, 1, $this->doc('Host without history'));
        $this->assertSame(2, $page->fresh()->write_version);
        $this->conflict(fn () => $this->history->open($this->actor, $page->id, $token, (string) Str::uuid()), 'history_unavailable');
        $this->assertFalse(Schema::hasTable('site_builder_draft_histories'));
    }
}
