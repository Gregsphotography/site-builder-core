<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Opis\JsonSchema\Validator;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Composition\CompositionResolver;
use SiteBuilder\Core\Composition\InsertPosition;
use SiteBuilder\Core\Composition\PageCompositionCommands;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Design\DesignCatalog;
use SiteBuilder\Core\Design\ThemeIdentity;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Rendering\LayoutRegistry;
use SiteBuilder\Core\Rendering\PageResponse;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Core\SiteParts\SitePartCatalog;
use SiteBuilder\Core\SiteParts\SitePartCommands;
use SiteBuilder\Core\SiteParts\SitePartLocks;
use SiteBuilder\Core\SiteParts\SitePartPayload;
use SiteBuilder\Core\SiteParts\SitePartRevision;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\PortalUser;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;
use SiteBuilder\Core\Tests\Fixtures\SitePartTestPolicy;
use SiteBuilder\Core\Tests\Fixtures\TestDesignTheme;

class SitePartPersistenceTest extends DatabaseTestCase
{
    private GenericUser $actor;

    private TestDesignTheme $theme;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['history-migrations', 'site-parts-migrations'] as $path) {
            $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/'.$path), '--realpath' => true, '--force' => true])->assertExitCode(0);
        }
        config(['site-builder.locales' => ['en' => [], 'de' => []], 'site-builder.site_parts.preview_layout' => 'parts']);
        foreach (['create', 'update', 'publish', 'restore', 'preview', 'view', 'delete'] as $ability) {
            Gate::define($ability, fn ($actor): bool => $actor->allowed === true);
        }
        Gate::policy(SitePart::class, SitePartTestPolicy::class);
        $this->actor = new GenericUser(['id' => (string) Str::ulid(), 'allowed' => true, 'chrome' => true, 'emergency' => false]);
        app(ComponentRegistry::class)->register(RenderBlock::class);
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
        app(LayoutRegistry::class)->register('parts', 'test-blocks::parts-layout', ['header', 'body', 'footer']);
        app(LayoutRegistry::class)->register('body-only', 'test-blocks::layout');
        $this->theme = new TestDesignTheme;
        app(DesignCatalog::class)->register($this->theme);
        app(DesignCatalog::class)->bindDefault(fn () => new ThemeIdentity('test.theme', 1, 'test'));
    }

    private function payload(string $heading, string $kind = 'section'): SitePartPayload
    {
        return SitePartPayload::document($kind, new Document([new Node('stable', 'test.block', props: new JsonObject(['future' => (object) ['object' => (object) [], 'list' => [], 'nil' => null, 'order' => ['third', 'first']]]), translations: new JsonObject(['en' => (object) ['heading' => $heading], 'de' => (object) ['heading' => 'Deutsch']]), extensions: new JsonObject(['host.node' => (object) ['keep' => []]]))], new JsonObject(['host.document' => (object) ['keep' => (object) []]])));
    }

    private function published(string $kind = 'section'): array
    {
        $commands = app(SitePartCommands::class);
        $created = $commands->create($this->actor, $kind, ['name' => 'Reusable '.$kind], $this->payload('Immutable original', $kind));
        $lease = app(SitePartLocks::class)->acquire($this->actor, $created->part->id);
        $published = $commands->publish($this->actor, $created->part->id, $lease->token, 0, $created->draftDigest, 'Original');

        return [$published, $lease, $published->part->revisions()->firstOrFail()];
    }

    public function test_drafts_new_publications_restore_unpublish_and_archive_never_change_existing_pinned_public_history(): void
    {
        [$published, $lease, $original] = $this->published();
        $this->assertTrue(Str::isUuid($published->part->id));
        $this->assertTrue(Str::isUuid($original->id));
        $this->assertSame((string) $this->actor->id, $original->created_by);
        $this->assertSame(GenericUser::class, $original->created_by_type);
        $pages = app(PageCommands::class);
        $page = $pages->create($this->actor, ['title' => 'Pinned public page', 'layout' => 'parts'], [], new Document);
        $pageLease = app(PageLocks::class)->acquire($this->actor, $page->page->id);
        $attached = app(PageCompositionCommands::class)->insertReference($this->actor, $page->page->id, $pageLease->token, 0, $original->reference(), InsertPosition::end());
        $public = $pages->publish($this->actor, $page->page->id, $pageLease->token, 1, $attached->draftDigest);
        $publicDigest = app(CanonicalJson::class)->digest($public->page->published_content);
        $partCommands = app(SitePartCommands::class);
        $draft = $partCommands->saveDraft($this->actor, $published->part->id, $lease->token, 1, $this->payload('Private replacement'));
        $this->assertSame(2, $draft->part->write_version);
        $this->assertStringNotContainsString('Private replacement', app(PageResponse::class)->render($public->page->fresh(), 'en')->getContent());
        $next = $partCommands->publish($this->actor, $draft->part->id, $lease->token, 2, $draft->draftDigest, 'Next');
        $this->assertCount(2, $next->part->revisions);
        $this->assertNotSame($original->id, $next->part->current_revision_id);
        $restored = $partCommands->restore($this->actor, $draft->part->id, $lease->token, 3, $original->id);
        $this->assertSame($next->part->current_revision_id, $restored->part->current_revision_id);
        $this->assertTrue(app(CanonicalJson::class)->equals($original->payload, $restored->part->draft_payload));
        $unpublished = $partCommands->unpublish($this->actor, $draft->part->id, $lease->token, 4);
        $this->assertNull($unpublished->part->current_revision_id);
        $archived = $partCommands->archive($this->actor, $draft->part->id, $lease->token, 5);
        $this->assertNotNull($archived->part->archived_at);
        $html = app(PageResponse::class)->render($public->page->fresh(), 'en')->getContent();
        $this->assertStringContainsString('Immutable original', $html);
        $this->assertStringNotContainsString('Private replacement', $html);
        $this->assertStringNotContainsString('preview.js', $html);
        $this->assertSame($publicDigest, app(CanonicalJson::class)->digest($public->page->fresh()->published_content));
        $this->assertSame($publicDigest, app(CanonicalJson::class)->digest($public->page->revisions()->firstOrFail()->content));
        $this->assertTrue(app(CanonicalJson::class)->equals($this->payload('Immutable original'), $original->fresh()->payload));
        $this->expectException(ValidationException::class);
        app(SitePartCatalog::class)->reference($this->actor, $original->id);
    }

    public function test_site_part_policy_kind_actor_model_token_expiry_and_versions_precede_theme_hooks(): void
    {
        [$published, $lease] = $this->published();
        $commands = app(SitePartCommands::class);
        $styled = SitePartPayload::design(new ThemeIdentity('test.theme', 1, 'test'), ['align' => 'center']);
        foreach ([
            [$this->actor, $lease->token, 9],
            [new GenericUser(['id' => $this->actor->id, 'allowed' => false]), $lease->token, 1],
            [new PortalUser(['id' => $this->actor->id, 'allowed' => true]), $lease->token, 1],
            [$this->actor, (string) Str::uuid(), 1],
        ] as [$actor, $token, $version]) {
            $this->theme->availabilityChecks = 0;
            try {
                $commands->saveDraft($actor, $published->part->id, $token, $version, $styled);
                $this->fail('A site-part guard was bypassed.');
            } catch (AuthorizationException|WriteConflict) {
                $this->assertSame(0, $this->theme->availabilityChecks);
                $this->assertSame(1, $published->part->fresh()->write_version);
            }
        }
        $ordinary = new GenericUser(['id' => (string) Str::uuid(), 'allowed' => true, 'chrome' => false]);
        try {
            $commands->create($ordinary, 'header', ['name' => 'Forbidden header'], $this->payload('Header', 'header'));
            $this->fail('Section authority implicitly granted header authority.');
        } catch (AuthorizationException) {
            $this->assertSame(1, SitePart::query()->count());
        }
        $this->travel(301)->seconds();
        try {
            $commands->saveDraft($this->actor, $published->part->id, $lease->token, 1, $this->payload('Expired'));
            $this->fail('An expired resource lease was reused.');
        } catch (WriteConflict) {
            $this->assertSame(1, $published->part->fresh()->write_version);
        }
    }

    public function test_header_footer_bindings_require_layout_regions_and_immediate_layout_changes_cannot_hide_published_parts(): void
    {
        [$header, , $headerRevision] = $this->published('header');
        [, , $footerRevision] = $this->published('footer');
        $pages = app(PageCommands::class);
        $page = $pages->create($this->actor, ['title' => 'Regions', 'layout' => 'parts'], [], new Document);
        $lease = app(PageLocks::class)->acquire($this->actor, $page->page->id);
        $bindings = app(PageCompositionCommands::class);
        $boundHeader = $bindings->bind($this->actor, $page->page->id, $lease->token, 0, 'header', $headerRevision->reference());
        $boundBoth = $bindings->bind($this->actor, $page->page->id, $lease->token, 1, 'footer', $footerRevision->reference());
        $published = $pages->publish($this->actor, $page->page->id, $lease->token, 2, $boundBoth->draftDigest);
        $html = app(PageResponse::class)->render($published->page, 'de')->getContent();
        $this->assertStringContainsString('<header>', $html);
        $this->assertStringContainsString('<footer>', $html);
        $this->assertSame(2, substr_count($html, '>Deutsch<'));
        $unboundDraft = $bindings->bind($this->actor, $page->page->id, $lease->token, 3, 'header', null);
        $this->assertSame(4, $unboundDraft->page->write_version);
        try {
            $pages->saveMetadata($this->actor, $page->page->id, $lease->token, 4, ['layout' => 'body-only']);
            $this->fail('An immediate wrapper change hid a published region.');
        } catch (InvalidArgumentException|ValidationException) {
            $this->assertSame('parts', $page->page->fresh()->layout);
            $this->assertSame(4, $page->page->fresh()->write_version);
        }
        $this->assertSame($header->part->id, app(CompositionResolver::class)->revision($headerRevision->reference(), 'header')->resource_id);
        $this->assertNotSame($boundHeader->draftDigest, $boundBoth->draftDigest);
    }

    public function test_tampered_immutable_rows_fail_and_emergency_withdrawal_is_an_independent_ability(): void
    {
        [$published, $lease, $revision] = $this->published();
        try {
            app(SitePartCommands::class)->withdraw($this->actor, $published->part->id, $lease->token, 1, $revision->id, 'Local controlled test');
            $this->fail('Publication authority implicitly granted emergency withdrawal.');
        } catch (AuthorizationException) {
            $this->assertNull($revision->fresh()->withdrawn_at);
        }
        DB::table('site_builder_site_part_revisions')->where('id', $revision->id)->update(['payload' => json_encode($this->payload('Tampered'), JSON_THROW_ON_ERROR)]);
        try {
            app(CompositionResolver::class)->revision($revision->reference());
            $this->fail('The pinned digest was replaced by the current mutable row.');
        } catch (ValidationException) {
            $this->assertSame($revision->digest, $revision->fresh()->digest);
        }
        DB::table('site_builder_site_part_revisions')->where('id', $revision->id)->update(['payload' => json_encode($revision->payload, JSON_THROW_ON_ERROR)]);
        $this->actor->emergency = true;
        $withdrawn = app(SitePartCommands::class)->withdraw($this->actor, $published->part->id, $lease->token, 1, $revision->id, 'Local controlled test');
        $this->assertSame(2, $withdrawn->part->write_version);
        $this->assertNotNull(SitePartRevision::query()->findOrFail($revision->id)->withdrawn_at);
        $this->expectException(ValidationException::class);
        app(CompositionResolver::class)->revision($revision->reference());
    }

    public function test_payload_codecs_preserve_opaque_shapes_and_reject_a_second_even_null_typed_value(): void
    {
        $payload = $this->payload('Shapes');
        $schema = json_decode(file_get_contents(__DIR__.'/../../resources/schema/site-part-v1.schema.json'), flags: JSON_THROW_ON_ERROR);
        $validator = new Validator;
        $this->assertTrue($validator->validate(json_decode(json_encode($payload, JSON_THROW_ON_ERROR)), $schema)->isValid());
        $this->assertTrue($validator->validate(json_decode(json_encode(SitePartPayload::design(new ThemeIdentity('test.theme', 1, 'test')), JSON_THROW_ON_ERROR)), $schema)->isValid());
        $this->assertTrue(app(CanonicalJson::class)->equals($payload, SitePartPayload::decode(json_encode($payload, JSON_THROW_ON_ERROR))));
        foreach ([
            (object) ['format' => SitePartPayload::FORMAT, 'version' => 1, 'kind' => 'section', 'document' => $payload->document, 'design' => null],
            (object) ['format' => SitePartPayload::FORMAT, 'version' => 1, 'kind' => 'design', 'design' => SitePartPayload::design(new ThemeIdentity('test.theme', 1, 'test'))->design->toObject(), 'document' => null],
        ] as $invalid) {
            $this->assertFalse($validator->validate($invalid, $schema)->isValid());
            try {
                SitePartPayload::decode(json_encode($invalid, JSON_THROW_ON_ERROR));
                $this->fail('A second typed payload was silently discarded.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_restore_checks_resource_authority_lease_and_version_before_reading_private_revision_payloads(): void
    {
        [$published, $lease, $revision] = $this->published();
        DB::table('site_builder_site_part_revisions')->where('id', $revision->id)->update(['payload' => '{invalid private future payload']);
        $denied = new GenericUser(['id' => $this->actor->id, 'allowed' => false, 'chrome' => false]);
        foreach ([$revision->id, (string) Str::uuid()] as $revisionId) {
            try {
                app(SitePartCommands::class)->restore($denied, $published->part->id, $lease->token, 1, $revisionId);
                $this->fail('A denied actor read private revision data.');
            } catch (AuthorizationException) {
                $this->assertSame(1, $published->part->fresh()->write_version);
            }
        }
        foreach ([[1, (string) Str::uuid()], [9, $lease->token]] as [$version, $token]) {
            try {
                app(SitePartCommands::class)->restore($this->actor, $published->part->id, $token, $version, $revision->id);
                $this->fail('An invalid lease/version decoded a private revision.');
            } catch (WriteConflict) {
                $this->assertSame(1, $published->part->fresh()->write_version);
            }
        }
    }

    public function test_inline_parts_cannot_hide_nested_region_requirements_from_a_body_only_wrapper(): void
    {
        [, , $header] = $this->published('header');
        $pin = $header->reference();
        $source = new Document($this->payload('Contained body')->document->nodes, new JsonObject(['site-builder.render' => (object) ['version' => 1, 'composition' => (object) ['version' => 1, 'header' => (object) ['resourceId' => $pin->resourceId, 'revisionId' => $pin->revisionId, 'digest' => $pin->digest]]]]));
        $parts = app(SitePartCommands::class);
        $part = $parts->create($this->actor, 'section', ['name' => 'Contained header'], SitePartPayload::document('section', $source));
        $partLease = app(SitePartLocks::class)->acquire($this->actor, $part->part->id);
        $published = $parts->publish($this->actor, $part->part->id, $partLease->token, 0, $part->draftDigest);
        $reference = $published->part->revisions()->firstOrFail()->reference();
        $page = app(PageCommands::class)->create($this->actor, ['title' => 'Legacy body wrapper', 'layout' => 'body-only'], [], new Document);
        $lease = app(PageLocks::class)->acquire($this->actor, $page->page->id);
        try {
            app(PageCompositionCommands::class)->insertReference($this->actor, $page->page->id, $lease->token, 0, $reference, InsertPosition::end());
            $this->fail('A nested global header bypassed the destination region capability.');
        } catch (ValidationException) {
            $this->assertSame(0, $page->page->fresh()->write_version);
            $this->assertCount(0, $page->page->fresh()->draft_content->nodes);
        }
    }
}
