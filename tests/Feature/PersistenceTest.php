<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Contracts\PageDeletionGuard;
use SiteBuilder\Core\Exceptions\InvalidDocument;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Exchange\Exchange;
use SiteBuilder\Core\Exchange\ExchangeSchema;
use SiteBuilder\Core\Exchange\Snapshot;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageLock;
use SiteBuilder\Core\Models\PageRevision;
use SiteBuilder\Core\Models\PageSlug;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageHistory;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;

class PersistenceTest extends DatabaseTestCase
{
    public function test_affected_numeric_draft_is_refused_without_changing_content_revision_or_version(): void
    {
        $page = $this->commands->create($this->actor, ['title' => 'Numeric policy'], ['en' => 'numeric-policy'], $this->document('Original'))->page;
        $lease = $this->locks->acquire($this->actor, $page->id);
        $before = Page::query()->findOrFail($page->id)->getRawOriginal();
        $revisions = PageRevision::query()->count();
        $document = new Document($this->document('Changed')->nodes, new JsonObject(['opaque' => (object) ['amount' => 1e20]]));
        try {
            $this->commands->saveDraft($this->actor, $page->id, $lease->token, $page->write_version, $document);
            $this->fail('A colliding draft was saved.');
        } catch (InvalidDocument $exception) {
            $this->assertSame('non_interoperable_number', $exception->diagnostics[0]->code);
        }
        $this->assertSame($before, Page::query()->findOrFail($page->id)->getRawOriginal());
        $this->assertSame($revisions, PageRevision::query()->count());
    }

    public function test_historical_affected_json_can_be_read_and_exported_without_rehash_or_mutation(): void
    {
        $page = $this->commands->create($this->actor, ['title' => 'Historical numbers'])->page;
        $raw = '{"format":"laravel-site-builder/document","version":1,"nodes":[],"extensions":{"historical":1e20}}';
        DB::table($page->getTable())->where('id', $page->id)->update(['draft_content' => $raw]);
        $raw = $page->fresh()->getRawOriginal('draft_content');
        $this->assertSame(1e20, $page->fresh()->draft_content->extensions->get('historical'));
        $snapshot = app(Exchange::class)->export(DB::connection(), 'numeric-history');
        $decoded = Snapshot::decode($snapshot->encode());
        $row = array_find($decoded->tables()['pages'], fn ($row) => (string) $row['id'] === (string) $page->id);
        $this->assertSame($raw, $row['draft_content']);
        $this->assertSame($raw, $page->fresh()->getRawOriginal('draft_content'));
        $this->assertSame(1, Artisan::call('site-builder:canonical-check', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('requires-review', $report['status']);
        $this->assertSame('draft_content', $report['issues'][0]['column']);
        $this->assertStringNotContainsString('1e20', json_encode($report));
        $this->assertSame($raw, $page->fresh()->getRawOriginal('draft_content'));
        $this->expectException(\InvalidArgumentException::class);
        $decoded->fingerprint(app(ExchangeSchema::class), app(CanonicalJson::class));
    }

    private GenericUser $actor;

    private PageCommands $commands;

    private PageLocks $locks;

    protected function setUp(): void
    {
        parent::setUp();
        config(['site-builder.locales' => ['en' => [], 'de' => []]]);
        app(ComponentRegistry::class)->register(RenderBlock::class);
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
        foreach (['view', 'create', 'update', 'publish', 'restore', 'delete', 'preview'] as $ability) {
            Gate::define($ability, fn (GenericUser $user): bool => $user->allowed === true);
        }
        $this->actor = new GenericUser(['id' => 7, 'allowed' => true]);
        $this->commands = app(PageCommands::class);
        $this->locks = app(PageLocks::class);
    }

    private function document(string $heading): Document
    {
        return new Document([new Node('stable', 'test.block', translations: new JsonObject(['en' => (object) ['heading' => $heading], 'de' => (object) ['heading' => 'Deutsch']])), new Node('other', 'test.block', props: new JsonObject(['heading' => 'Other']))], new JsonObject(['provenance' => (object) ['empty' => [], 'null' => null]]));
    }

    private function conflict(callable $action, string $reason): void
    {
        try {
            $action();
            $this->fail('Expected a write conflict.');
        } catch (WriteConflict $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame($reason, $exception->reason);
        }
    }

    public function test_create_draft_metadata_publish_restore_and_unpublish_preserve_their_separate_scopes(): void
    {
        $created = $this->commands->create($this->actor, ['title' => 'Original'], ['en' => 'home', 'de' => 'start']);
        $page = $created->page;
        $this->assertSame(0, $page->write_version);
        $this->assertNull($page->published_content);
        $lease = $this->locks->acquire($this->actor, $page->id);
        $first = $this->commands->saveDraft($this->actor, $page->id, $lease->token, 0, $this->document('First'));
        $this->assertSame(1, $first->page->write_version);
        $published = $this->commands->publish($this->actor, $page->id, $lease->token, 1, $first->draftDigest, 'First release');
        $this->assertSame('published', $published->page->status);
        $this->assertSame(2, $published->page->write_version);
        $this->assertSame(1, $published->page->published_from_version);
        $revision = $published->page->revisions()->firstOrFail();
        $this->assertSame('7', $revision->created_by);
        $this->assertSame('content', $revision->scope);
        $this->assertSame('First release', $revision->label);
        $this->assertNotNull($revision->created_at);
        $this->assertSame(1, $revision->published_from_version);
        $this->assertSame('native', $revision->origin);
        $timestamp = $published->page->published_at;
        $draft = $this->commands->saveDraft($this->actor, $page->id, $lease->token, 2, $this->document('Unpublished changes'));
        $this->assertTrue(app(CanonicalJson::class)->equals($this->document('First'), $draft->page->published_content));
        $meta = $this->commands->saveMetadata($this->actor, $page->id, $lease->token, 3, ['title' => 'Live shared metadata', 'seo' => (object) ['en' => (object) ['nofollow' => true]], 'data' => (object) ['list' => [], 'empty' => (object) []]], ['en' => 'new-home']);
        $this->assertSame(4, $meta->page->write_version);
        $this->assertSame('home', $meta->page->redirects()->first()->from_slug);
        $this->assertSame('Live shared metadata', $meta->page->title);
        $restored = $this->commands->restore($this->actor, $page->id, $lease->token, 4, $revision->id);
        $this->assertSame(5, $restored->page->write_version);
        $this->assertSame('Live shared metadata', $restored->page->title);
        $this->assertEquals($timestamp, $restored->page->published_at);
        $this->assertSame($first->draftDigest, $restored->draftDigest);
        $this->assertCount(1, $restored->page->revisions);
        $unpublished = $this->commands->unpublish($this->actor, $page->id, $lease->token, 5);
        $this->assertSame(6, $unpublished->page->write_version);
        $this->assertSame('draft', $unpublished->page->status);
        $this->assertNull($unpublished->page->published_at);
        $this->assertTrue(app(CanonicalJson::class)->equals($unpublished->page->draft_content, $unpublished->page->published_content));
        $this->assertCount(1, $unpublished->page->revisions);
    }

    public function test_out_of_order_requests_and_wrong_publish_digest_do_not_partially_write(): void
    {
        $page = Page::factory()->create();
        $lease = $this->locks->acquire($this->actor, $page->id);
        $accepted = $this->commands->saveDraft($this->actor, $page->id, $lease->token, 0, $this->document('Accepted'));
        $this->conflict(fn () => $this->commands->saveDraft($this->actor, $page->id, $lease->token, 0, $this->document('Late')), 'stale_version');
        $this->conflict(fn () => $this->commands->saveMetadata($this->actor, $page->id, $lease->token, 0, ['title' => 'Late']), 'stale_version');
        $this->conflict(fn () => $this->commands->publish($this->actor, $page->id, $lease->token, 0, $accepted->draftDigest), 'stale_version');
        $this->conflict(fn () => $this->commands->publish($this->actor, $page->id, $lease->token, 1, str_repeat('0', 64)), 'draft_digest_changed');
        $this->assertSame('draft', $page->fresh()->status);
        $this->assertSame(0, $page->revisions()->count());
        $noop = $this->commands->saveDraft($this->actor, $page->id, $lease->token, 1, $this->document('Accepted'));
        $this->assertSame(1, $noop->page->write_version);
        $this->assertSame($accepted->draftDigest, $noop->draftDigest);
    }

    public function test_lease_is_per_page_and_tab_stores_only_hash_and_expired_tokens_cannot_mutate_renew_or_release_replacements(): void
    {
        $page = Page::factory()->create();
        $lease = $this->locks->acquire($this->actor, $page->id);
        $record = $page->editLock()->firstOrFail();
        $this->assertSame(hash('sha256', $lease->token), $record->token_hash);
        $this->assertStringNotContainsString($lease->token, $record->toJson());
        $this->assertSame($lease->token, $this->locks->acquire($this->actor, $page->id, $lease->token)->token);
        $this->conflict(fn () => $this->locks->acquire($this->actor, $page->id), 'lease_held');
        $this->travel(301)->seconds();
        $this->conflict(fn () => $this->locks->renew($this->actor, $page->id, $lease->token), 'lease_lost');
        $this->conflict(fn () => $this->locks->acquire($this->actor, $page->id, $lease->token), 'lease_expired_use_new_token');
        $newLease = $this->locks->acquire($this->actor, $page->id);
        $this->conflict(fn () => $this->commands->saveDraft($this->actor, $page->id, $lease->token, 0, $this->document('Expired')), 'lease_lost');
        $this->conflict(fn () => $this->locks->release($this->actor, $page->id, $lease->token), 'lease_lost');
        $this->assertSame(hash('sha256', $newLease->token), $page->editLock()->first()->token_hash);
        $this->travel(31)->seconds();
        $this->assertTrue($this->locks->renew($this->actor, $page->id, $newLease->token)->expiresAt->greaterThan($newLease->expiresAt));
        $this->assertSame(0, $page->fresh()->write_version);
        $this->locks->release($this->actor, $page->id, $newLease->token);
        $this->assertSame(0, PageLock::query()->count());
    }

    public function test_every_command_checks_authorization_and_existing_page_lease_and_version(): void
    {
        $page = Page::factory()->create(['draft_content' => $this->document('Draft')]);
        $lease = $this->locks->acquire($this->actor, $page->id);
        $revision = PageRevision::factory()->create(['page_id' => $page->id]);
        $denied = new GenericUser(['id' => 8, 'allowed' => false]);
        $calls = [
            fn ($user, $token, $version) => $this->commands->saveDraft($user, $page->id, $token, $version, $this->document('Changed')),
            fn ($user, $token, $version) => $this->commands->saveMetadata($user, $page->id, $token, $version, ['title' => 'Changed']),
            fn ($user, $token, $version) => $this->commands->publish($user, $page->id, $token, $version, app(CanonicalJson::class)->digest($page->draft_content)),
            fn ($user, $token, $version) => $this->commands->unpublish($user, $page->id, $token, $version),
            fn ($user, $token, $version) => $this->commands->restore($user, $page->id, $token, $version, $revision->id),
            fn ($user, $token, $version) => $this->commands->delete($user, $page->id, $token, $version),
        ];
        foreach ($calls as $call) {
            try {
                $call($denied, $lease->token, 0);
                $this->fail('Unauthorized command accepted.');
            } catch (AuthorizationException) {
                $this->assertSame(0, $page->fresh()->write_version);
            }
            $this->conflict(fn () => $call($this->actor, (string) Str::uuid(), 0), 'lease_lost');
            $this->conflict(fn () => $call($this->actor, $lease->token, 99), 'stale_version');
        }
        foreach (['acquire', 'renew', 'release'] as $method) {
            try {
                $this->locks->{$method}($denied, $page->id, $lease->token);
                $this->fail('Unauthorized lease action accepted.');
            } catch (AuthorizationException) {
                $this->assertSame(0, $page->fresh()->write_version);
            }
        }
        $this->expectException(AuthorizationException::class);
        $this->commands->create($denied, ['title' => 'Forbidden']);
    }

    public function test_publication_failure_rolls_back_revision_retention_content_status_and_timestamp(): void
    {
        config(['site-builder.revisions.keep' => 1]);
        $page = Page::factory()->create(['draft_content' => $this->document('New'), 'published_content' => $this->document('Old')]);
        $lease = $this->locks->acquire($this->actor, $page->id);
        $old = PageRevision::factory()->create(['page_id' => $page->id, 'content' => $this->document('Old')]);
        Page::saving(function (Page $saving): void {
            if ($saving->status === 'published') {
                throw new RuntimeException('Injected final page save failure');
            }
        });
        try {
            $this->commands->publish($this->actor, $page->id, $lease->token, 0, app(CanonicalJson::class)->digest($page->draft_content));
            $this->fail('Publication failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected final page save failure', $exception->getMessage());
        } finally {
            Page::flushEventListeners();
        }
        $page = $page->fresh();
        $this->assertSame('draft', $page->status);
        $this->assertSame(0, $page->write_version);
        $this->assertNull($page->published_at);
        $this->assertTrue(app(CanonicalJson::class)->equals($this->document('Old'), $page->published_content));
        $this->assertSame([$old->id], $page->revisions()->pluck('id')->all());
    }

    public function test_native_retention_preserves_nullable_legacy_history_and_can_be_disabled(): void
    {
        config(['site-builder.revisions.keep' => 1]);
        $page = Page::factory()->create(['draft_content' => $this->document('Ready')]);
        $lease = $this->locks->acquire($this->actor, $page->id);
        $legacy = PageRevision::factory()->create(['page_id' => $page->id, 'origin' => 'legacy', 'created_at' => null, 'created_by' => null, 'content' => null, 'published_from_version' => null]);
        for ($version = 0; $version < 3; $version++) {
            $this->commands->publish($this->actor, $page->id, $lease->token, $version, app(CanonicalJson::class)->digest($page->draft_content));
        }
        $this->assertSame(2, $page->revisions()->count());
        $this->assertNull($legacy->fresh()->created_by);
        $this->assertNull($legacy->fresh()->created_at);
        $this->assertNull($legacy->fresh()->published_from_version);
        config(['site-builder.revisions.keep' => 0]);
        $this->commands->publish($this->actor, $page->id, $lease->token, 3, app(CanonicalJson::class)->digest($page->draft_content));
        $this->assertSame(3, $page->revisions()->count());
        $restored = $this->commands->restore($this->actor, $page->id, $lease->token, 4, $legacy->id);
        $this->assertNull($restored->page->draft_content);
        $this->assertNotNull($restored->page->published_content);
    }

    public function test_duplicate_slugs_fail_atomically_and_a_revision_from_another_page_cannot_be_restored(): void
    {
        $one = $this->commands->create($this->actor, ['title' => 'One'], ['en' => 'one'])->page;
        $two = $this->commands->create($this->actor, ['title' => 'Two'], ['en' => 'two'])->page;
        $lease = $this->locks->acquire($this->actor, $one->id);
        try {
            $this->commands->saveMetadata($this->actor, $one->id, $lease->token, 0, ['title' => 'Changed'], ['de' => 'eins', 'en' => 'two']);
            $this->fail('Duplicate slug accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slugs', $exception->errors());
            $this->assertSame('One', $one->fresh()->title);
            $this->assertSame(0, $one->fresh()->write_version);
            $this->assertSame(['one'], $one->slugs()->pluck('slug')->all());
            $this->assertSame(0, $one->redirects()->count());
        }
        $revision = PageRevision::factory()->create(['page_id' => $two->id]);
        $this->expectException(ModelNotFoundException::class);
        $this->commands->restore($this->actor, $one->id, $lease->token, 0, $revision->id);
    }

    public function test_duplicate_creation_rolls_back_and_paths_are_scoped_to_their_locale(): void
    {
        $page = $this->commands->create($this->actor, ['title' => 'Original'], ['en' => 'shared'])->page;
        try {
            $this->commands->create($this->actor, ['title' => 'Rejected'], ['de' => 'unique', 'en' => 'shared']);
            $this->fail('A duplicate URL was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slugs', $exception->errors());
            $this->assertSame(1, Page::query()->count());
            $this->assertSame(1, DB::table('site_builder_page_slugs')->count());
        }
        $other = $this->commands->create($this->actor, ['title' => 'German'], ['de' => 'shared'])->page;
        $lease = $this->locks->acquire($this->actor, $page->id);
        $saved = $this->commands->saveMetadata($this->actor, $page->id, $lease->token, 0, [], ['en' => 'shared']);
        $this->assertSame(0, $saved->page->write_version);
        $this->assertSame('shared', $other->slugs()->sole()->slug);
    }

    public function test_unrelated_database_constraints_are_not_reported_as_duplicate_urls(): void
    {
        $page = $this->commands->create($this->actor, ['title' => 'Original'])->page;
        PageSlug::creating(function () use ($page): void {
            DB::table('site_builder_pages')->insert(['id' => $page->id, 'title' => 'Duplicate primary key']);
        });
        $this->expectException(UniqueConstraintViolationException::class);
        $this->commands->create($this->actor, ['title' => 'New page'], ['en' => 'valid-path']);
    }

    public function test_unknown_nodes_can_survive_sibling_edits_but_cannot_be_changed_added_or_published(): void
    {
        $unknown = new Node('unknown', 'future.block', props: new JsonObject(['payload' => (object) ['list' => [], 'null' => null]]));
        $page = Page::factory()->create(['draft_content' => new Document([$unknown])]);
        $lease = $this->locks->acquire($this->actor, $page->id);
        $draft = new Document([$unknown, ...$this->document('Supported')->nodes]);
        $accepted = $this->commands->saveDraft($this->actor, $page->id, $lease->token, 0, $draft);
        $this->assertSame('unknown_component', $accepted->diagnostics[0]->code);
        $this->assertTrue(app(CanonicalJson::class)->equals($unknown, $accepted->page->draft_content->nodes[0]));
        try {
            $this->commands->saveDraft($this->actor, $page->id, $lease->token, 1, new Document([new Node('unknown', 'future.block')]));
            $this->fail('Unsupported node was changed.');
        } catch (ValidationException) {
            $this->assertSame(1, $page->fresh()->write_version);
        }
        $this->expectException(InvalidDocument::class);
        $this->commands->publish($this->actor, $page->id, $lease->token, 1, $accepted->draftDigest);
    }

    public function test_delete_runs_host_guard_and_cascades_only_after_acceptance(): void
    {
        $page = $this->commands->create($this->actor, ['title' => 'Delete me'], ['en' => 'delete'])->page;
        $lease = $this->locks->acquire($this->actor, $page->id);
        PageRevision::factory()->create(['page_id' => $page->id]);
        app()->instance('test.delete-guard', new class implements PageDeletionGuard
        {
            public bool $veto = true;

            public function check(Page $page, Authenticatable $actor): void
            {
                if ($this->veto) {
                    throw new RuntimeException('Inbound host reference needs review');
                }
            }
        });
        app()->tag('test.delete-guard', PageDeletionGuard::class);
        try {
            $this->commands->delete($this->actor, $page->id, $lease->token, 0);
            $this->fail('Guard was ignored.');
        } catch (RuntimeException) {
            $this->assertNotNull($page->fresh());
        }
        app('test.delete-guard')->veto = false;
        $this->commands->delete($this->actor, $page->id, $lease->token, 0);
        $this->assertNull($page->fresh());
        $this->assertSame(0, PageRevision::query()->count());
        $this->assertSame(0, PageLock::query()->count());
        $this->assertSame(0, $page->slugs()->count());
    }

    public function test_history_is_authorized_page_scoped_and_cursor_ordered(): void
    {
        $page = Page::factory()->create();
        $old = PageRevision::factory()->create(['page_id' => $page->id]);
        $new = PageRevision::factory()->create(['page_id' => $page->id]);
        PageRevision::factory()->create();
        $history = app(PageHistory::class);
        $this->assertSame([$new->id], $history->revisions($this->actor, $page->id, 1)->modelKeys());
        $this->assertSame([$old->id], $history->revisions($this->actor, $page->id, 20, $new->id)->modelKeys());
        $this->expectException(AuthorizationException::class);
        $history->revisions(new GenericUser(['id' => 8, 'allowed' => false]), $page->id);
    }

    public function test_invalid_metadata_and_unsafe_values_in_another_locale_leave_all_rows_unchanged(): void
    {
        $page = Page::factory()->create();
        $lease = $this->locks->acquire($this->actor, $page->id);
        foreach ([['status' => 'published'], ['write_version' => 900], ['layout' => 'unregistered-view'], ['seo' => (object) ['en' => (object) ['canonical' => 'javascript:bad()']]]] as $metadata) {
            try {
                $this->commands->saveMetadata($this->actor, $page->id, $lease->token, 0, $metadata);
                $this->fail('Invalid metadata was accepted.');
            } catch (ValidationException|\InvalidArgumentException) {
                $this->assertSame(0, $page->fresh()->write_version);
            }
        }
        $bad = new Document([new Node('bad', 'test.block', translations: new JsonObject(['en' => (object) ['heading' => 'Fine'], 'de' => (object) ['link' => 'javascript:bad()']]))]);
        try {
            $this->commands->saveDraft($this->actor, $page->id, $lease->token, 0, $bad);
            $this->fail('Unsafe hidden-locale data was accepted.');
        } catch (InvalidDocument) {
            $this->assertSame(0, $page->fresh()->write_version);
        }
        $this->actor->allowed = false;
        $this->expectException(AuthorizationException::class);
        $this->locks->renew($this->actor, $page->id, $lease->token);
    }
}
