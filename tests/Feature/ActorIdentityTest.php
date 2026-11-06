<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageHistory;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Routing\PreviewLinks;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\PortalUser;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;

class ActorIdentityTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(ComponentRegistry::class)->register(RenderBlock::class);
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('g', 32))]);
    }

    public static function identities(): array
    {
        return [
            'bigint' => [7],
            'lossless unsigned bigint string' => ['18446744073709551615'],
            'UUID' => ['715f202e-74f0-4ea7-9255-3ade68307741'],
            'ULID' => ['01JBDR03XETB5V4C2625GJ44JD'],
        ];
    }

    #[DataProvider('identities')]
    public function test_custom_guard_principals_keep_exact_leases_authorship_history_and_preview(int|string $id): void
    {
        $actor = new PortalUser(['id' => $id]);
        Auth::viaRequest('portal-request', fn () => $actor);
        config(['auth.guards.portal' => ['driver' => 'portal-request']]);
        $this->actingAs(new GenericUser(['id' => 999]));
        foreach (['create', 'update', 'publish', 'view', 'preview'] as $ability) {
            Gate::define($ability, fn (Authenticatable $user): bool => $user === $actor);
        }
        $principal = Auth::guard('portal')->user();
        $this->assertSame($actor, $principal);
        $this->assertNotSame($actor, Auth::user());
        $commands = app(PageCommands::class);
        $initial = new Document([new Node('initial', 'test.block', props: new JsonObject(['heading' => 'Template draft']))]);
        $created = $commands->create($principal, ['title' => 'Portal page'], ['en' => 'portal'], $initial);
        $this->assertSame(0, $created->page->write_version);
        $this->assertSame('Template draft', $created->page->draft_content->nodes[0]->props->get('heading'));
        $lease = app(PageLocks::class)->acquire($principal, $created->page->id);
        $lock = $created->page->editLock()->firstOrFail();
        $this->assertSame((string) $id, $lock->user_id);
        $this->assertSame(PortalUser::class, $lock->user_type);
        $published = $commands->publish($principal, $created->page->id, $lease->token, 0, $created->draftDigest);
        $revision = app(PageHistory::class)->revisions($principal, $published->page->id)->sole();
        $this->assertSame((string) $id, $revision->created_by);
        $this->assertSame(PortalUser::class, $revision->created_by_type);
        $this->get(app(PreviewLinks::class)->create($principal, $created->page->id, 'en'))->assertOk()->assertSee('Template draft');
        app(PageLocks::class)->renew($principal, $created->page->id, $lease->token);
        app(PageLocks::class)->release($principal, $created->page->id, $lease->token);
        $this->assertNull($created->page->editLock()->first());
    }

    public function test_two_actor_models_with_the_same_id_cannot_share_a_lease_even_with_the_token(): void
    {
        Gate::before(fn () => true);
        $owner = new PortalUser(['id' => '1']);
        $other = new GenericUser(['id' => 1]);
        $page = Page::factory()->create();
        $locks = app(PageLocks::class);
        $lease = $locks->acquire($owner, $page->id);
        foreach ([
            fn () => $locks->acquire($other, $page->id, $lease->token),
            fn () => $locks->renew($other, $page->id, $lease->token),
            fn () => $locks->release($other, $page->id, $lease->token),
            fn () => app(PageCommands::class)->saveDraft($other, $page->id, $lease->token, 0, new Document),
        ] as $action) {
            try {
                $action();
                $this->fail('Distinct actor domains must never share a lease.');
            } catch (WriteConflict) {
                $this->assertSame(0, $page->fresh()->write_version);
            }
        }
        $this->assertSame(PortalUser::class, $page->editLock()->firstOrFail()->user_type);
        $locks->renew($owner, $page->id, $lease->token);
    }

    public function test_initial_template_creation_is_authorized_validated_and_atomic(): void
    {
        $actor = new PortalUser(['id' => '01JBDR03XETB5V4C2625GJ44JD']);
        Gate::define('create', fn () => false);
        try {
            app(PageCommands::class)->create($actor, ['title' => 'Denied'], [], new Document);
            $this->fail('Template selection cannot bypass create authorization.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Page::count());
        }
        Gate::define('create', fn () => true);
        try {
            app(PageCommands::class)->create($actor, ['title' => 'Unsupported'], [], new Document([new Node('future', 'future.block')]));
            $this->fail('Templates cannot introduce unsupported nodes.');
        } catch (ValidationException) {
            $this->assertSame(0, Page::count());
        }
        try {
            app(PageCommands::class)->create($actor, ['title' => 'Invalid path'], ['missing-locale' => 'home'], new Document);
            $this->fail('An invalid translation must roll back the whole template creation.');
        } catch (ValidationException) {
            $this->assertSame(0, Page::count());
        }
    }

    public static function invalidIdentities(): array
    {
        return [[0], ['0'], [null], [true], [7.0], [''], [str_repeat('a', 192)], ["user\n7"], [['id' => 7]]];
    }

    #[DataProvider('invalidIdentities')]
    public function test_actor_identities_are_never_coerced_or_truncated(mixed $id): void
    {
        Gate::before(fn () => true);
        try {
            app(PageCommands::class)->create(new PortalUser(['id' => $id]), ['title' => 'Invalid principal']);
            $this->fail('Invalid identity must never be coerced into another principal.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Page::count());
        }
    }
}
