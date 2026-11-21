<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Publishing\PageReview;
use SiteBuilder\Core\Publishing\PageReviews;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;

class PageReviewTest extends DatabaseTestCase
{
    private ReviewPublisher $actor;

    private PageCommands $pages;

    private PageReviews $reviews;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'site-builder.locales' => ['en' => [], 'de' => []],
            'auth.providers.reviewers' => ['driver' => 'eloquent', 'model' => ReviewPublisher::class],
            'auth.guards.reviewers' => ['driver' => 'session', 'provider' => 'reviewers']]);
        Schema::create('review_publishers', function (Blueprint $table): void {
            $table->id();
            $table->boolean('can_publish');
        });
        $this->actor = ReviewPublisher::create(['can_publish' => true]);
        foreach (['create', 'update', 'preview', 'delete'] as $ability) {
            Gate::define($ability, fn ($actor) => $actor instanceof ReviewPublisher);
        }
        Gate::define('publish', fn (ReviewPublisher $actor): bool => (bool) $actor->can_publish);
        app(ComponentRegistry::class)->register(RenderBlock::class);
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
        $this->pages = app(PageCommands::class);
        $this->reviews = app(PageReviews::class);
    }

    private function content(string $text): Document
    {
        return new Document([new Node('section', 'test.block', props: new JsonObject(['heading' => $text]))]);
    }

    private function page(): array
    {
        $created = $this->pages->create($this->actor, ['title' => 'Live page'], ['en' => 'page', 'de' => 'seite'], $this->content('Live text'));
        $page = $created->page;
        $lease = app(PageLocks::class)->acquire($this->actor, $page->id)->token;
        $this->pages->publish($this->actor, $page->id, $lease, 0, $created->draftDigest);
        $this->pages->saveDraft($this->actor, $page->id, $lease, 1, $this->content('Reviewed text'));
        $this->pages->saveMetadata($this->actor, $page->id, $lease, 2, ['title' => 'Review title', 'seo' => (object) ['en' => (object) ['meta_title' => 'Reviewed search title', 'meta_description' => 'Reviewed description']]], ['en' => 'approved-url']);
        $review = $this->reviews->request($this->actor, $page->id, $lease, 3);

        return [$page, $lease, $review];
    }

    private function approve(PageReview $review): void
    {
        $this->post($this->reviews->link($review), ['decision' => 'approved', 'reviewer' => 'Alex'])->assertOk()->assertSee('Approved by Alex');
    }

    public function test_signed_review_freezes_content_urls_and_seo_and_records_one_scoped_decision(): void
    {
        [$page, $lease, $review] = $this->page();
        $link = $this->reviews->link($review);
        $preview = $this->reviews->link($review, 'en');
        $this->pages->saveDraft($this->actor, $page->id, $lease, 3, $this->content('Later confidential text'));
        $this->pages->saveMetadata($this->actor, $page->id, $lease, 4, ['title' => 'Later title'], ['en' => 'later-url']);
        $this->get($link)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('Reviewed search title')->assertSee('approved-url')->assertSee('Reviewed description')->assertSee('Publication changes')->assertSee('Live: Live page')->assertSee('Proposed: Review title')->assertSee('Compare the live EN page')->assertDontSee('later-url');
        $this->get($preview)->assertOk()->assertSee('Reviewed text')->assertDontSee('Later confidential text');
        $this->get(str_replace('/en?', '/de?', $preview))->assertForbidden();
        $this->get(parse_url($link, PHP_URL_PATH))->assertForbidden();
        $this->get('/page')->assertOk()->assertSee('Live text');
        $this->post($link, ['decision' => 'changes_requested', 'reviewer' => 'Alex'])->assertStatus(422);
        $this->post($link, ['decision' => 'changes_requested', 'reviewer' => 'Alex', 'feedback' => 'Clarify hours'])
            ->assertOk()->assertSee('Changes requested by Alex')->assertSee('Clarify hours')->assertDontSee('Reviewed search title')
            ->assertDontSee('<iframe', false)->assertDontSee('Open EN preview');
        $this->assertSame('changes_requested', $review->fresh()->status);
        $this->get($link)->assertGone()->assertDontSee('Clarify hours');
        $this->get($preview)->assertGone()->assertDontSee('Reviewed text');
        $this->post($link, ['decision' => 'approved', 'reviewer' => 'Another'])->assertGone();
        $this->pages->unpublish($this->actor, $page->id, $lease, 5);
        $this->get($link)->assertGone();
        $this->get($preview)->assertGone();
        $this->expectException(ValidationException::class);
        $this->pages->publishReview($this->actor, $page->id, $review->id);
    }

    public function test_scheduled_approved_version_publishes_once_preserving_later_draft_and_new_review_request(): void
    {
        [$page, $lease, $review] = $this->page();
        $this->approve($review);
        $this->reviews->schedule($this->actor, $page->id, $review->id, CarbonImmutable::now()->addMinutes(2), 'reviewers');
        $this->pages->saveDraft($this->actor, $page->id, $lease, 3, $this->content('Later text'));
        $this->pages->saveMetadata($this->actor, $page->id, $lease, 4, ['title' => 'Later title'], ['en' => 'later-url']);
        $newer = $this->reviews->request($this->actor, $page->id, $lease, 5);
        $this->assertSame('scheduled', $review->fresh()->status);
        $this->artisan('site-builder:publish-due')->assertSuccessful();
        $this->get('/approved-url')->assertNotFound();
        $this->travel(3)->minutes();
        $this->artisan('site-builder:publish-due')->assertSuccessful();
        $this->artisan('site-builder:publish-due')->assertSuccessful();
        $this->get('/approved-url')->assertOk()->assertSee('Reviewed text')->assertSee('Reviewed search title')->assertDontSee('Later text');
        $this->get('/later-url')->assertNotFound();
        $this->get('/page')->assertRedirect('/approved-url');
        $current = $page->fresh();
        $this->assertSame('Review title', $current->title);
        $this->assertSame('Later title', $current->draftView()->title);
        $this->assertSame('later-url', $current->draft_settings->slugs->en);
        $this->assertSame('Later text', $current->draft_content->nodes[0]->props->get('heading'));
        $this->assertSame(2, $current->revisions()->count());
        $this->assertSame(3, $current->revisions()->first()->published_from_version);
        $this->assertSame('published', $review->fresh()->status);
        $this->assertSame('pending', $newer->fresh()->status);
        $this->get($this->reviews->link($review))->assertGone();
    }

    public function test_a_disabled_epoch_refuses_new_requests_and_links_for_existing_requests(): void
    {
        [$page, $lease, $review] = $this->page();
        $link = $this->reviews->link($review);
        config(['site-builder.preview.epoch' => '']);
        $this->pages->saveDraft($this->actor, $page->id, $lease, 3, $this->content('New draft'));
        foreach ([
            fn () => $this->reviews->request($this->actor, $page->id, $lease, 4),
            fn () => $this->reviews->link($review),
            fn () => $this->reviews->link($review, 'en'),
        ] as $issueLink) {
            try {
                $issueLink();
                $this->fail('A disabled site preview epoch allowed a review link to be issued.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('review', $error->errors());
            }
        }
        $this->assertSame(1, PageReview::query()->count());
        $this->get($link)->assertForbidden();
    }

    public function test_expired_or_revoked_review_links_cannot_record_approval(): void
    {
        [$page, $lease, $review] = $this->page();
        $oldLink = $this->reviews->link($review);
        config(['site-builder.preview.epoch' => 'revoked']);
        $this->get($oldLink)->assertForbidden();
        $this->post($oldLink, ['reviewer' => 'Alex', 'decision' => 'approved'])->assertForbidden();
        $link = $this->reviews->link($review);
        $this->reviews->cancel($this->actor, $page->id, $review->id);
        $this->get($link)->assertGone();
        $new = $this->reviews->request($this->actor, $page->id, $lease, 3);
        $latest = $this->reviews->link($new);
        $this->travel(8)->days();
        $this->get($latest)->assertForbidden();
        $this->artisan('site-builder:publish-due')->assertSuccessful();
        $this->assertSame('expired', $new->fresh()->status);
        $this->assertNull($new->fresh()->reviewer);
    }

    public function test_losing_publish_permission_and_contested_urls_fail_without_partial_publication(): void
    {
        [$page, $lease, $review] = $this->page();
        $this->approve($review);
        $this->reviews->schedule($this->actor, $page->id, $review->id, CarbonImmutable::now()->addMinute(), 'reviewers');
        $this->actor->update(['can_publish' => false]);
        $this->travel(2)->minutes();
        $this->artisan('site-builder:publish-due')->assertFailed();
        $this->assertSame('failed', $review->fresh()->status);
        $this->actor->update(['can_publish' => true]);
        $second = $this->reviews->request($this->actor, $page->id, $lease, 3);
        $this->approve($second);
        $this->reviews->schedule($this->actor, $page->id, $second->id, CarbonImmutable::now()->addMinute(), 'reviewers');
        $this->pages->create($this->actor, ['title' => 'Another page'], ['en' => 'approved-url']);
        $this->travel(2)->minutes();
        $this->artisan('site-builder:publish-due')->assertFailed();
        $this->assertSame('failed', $second->fresh()->status);
        $this->assertSame(1, $page->revisions()->count());
        $this->get('/page')->assertOk()->assertSee('Live text');
    }

    public function test_a_newer_publication_withdrawal_and_trash_cancel_pending_launches(): void
    {
        foreach (['publish', 'unpublish', 'delete'] as $operation) {
            [$page, $lease, $review] = $this->page();
            $this->approve($review);
            $this->reviews->schedule($this->actor, $page->id, $review->id, CarbonImmutable::now()->addMinute(), 'reviewers');
            if ($operation === 'publish') {
                $draft = $this->pages->saveDraft($this->actor, $page->id, $lease, 3, $this->content('Newer publication'));
                $this->pages->publish($this->actor, $page->id, $lease, 4, $draft->draftDigest);
            } else {
                $this->pages->{$operation}($this->actor, $page->id, $lease, 3);
            }
            $this->assertSame('cancelled', $review->fresh()->status);
            $this->travel(2)->minutes();
            $this->artisan('site-builder:publish-due')->assertSuccessful();
            if ($operation === 'publish') {
                $this->get('/approved-url')->assertOk()->assertSee('Newer publication');
            } else {
                $this->get('/page')->assertNotFound();
            }
            Page::withoutGlobalScope('active')->whereKey($page->id)->delete();
        }
    }
}

class ReviewPublisher extends User
{
    public $timestamps = false;

    protected $guarded = [];
}
