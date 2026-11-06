<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use SiteBuilder\Core\Clipboard\ClipboardCommands;
use SiteBuilder\Core\Clipboard\ClipboardDomainResolver;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\Reference;
use SiteBuilder\Core\Composition\CompositionData;
use SiteBuilder\Core\Composition\CompositionRenderFeature;
use SiteBuilder\Core\Composition\CopyContext;
use SiteBuilder\Core\Composition\GlobalReferenceDefinition;
use SiteBuilder\Core\Composition\InsertPosition;
use SiteBuilder\Core\Composition\ReferenceCheck;
use SiteBuilder\Core\Composition\ReferenceMap;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Contracts\CopyReferenceInspector;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;
use SiteBuilder\Core\SiteBuilderServiceProvider;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Core\SiteParts\SitePartPayload;
use SiteBuilder\Core\SiteParts\SitePartRevision;
use SiteBuilder\Core\Tests\ClipboardTestCase;
use SiteBuilder\Core\Tests\Fixtures\CompositionBlock;

class ClipboardRevisionTest extends ClipboardTestCase
{
    private GenericUser $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $path = dirname((new ReflectionClass(SiteBuilderServiceProvider::class))->getFileName(), 2).'/database/site-parts-migrations';
        $this->artisan('migrate', ['--path' => $path, '--realpath' => true, '--force' => true])->assertExitCode(0);
        app(ComponentRegistry::class)->register(new CompositionBlock);
        if (app(ComponentRegistry::class)->resolve('core.global-reference') === null) {
            app(ComponentRegistry::class)->register(GlobalReferenceDefinition::class);
        }
        $probe = new Document(extensions: new JsonObject(['site-builder.render' => (object) ['version' => 1, 'composition' => (object) ['version' => 1]]]));
        if (app(RenderFeatureRegistry::class)->readOnly($probe)) {
            app(RenderFeatureRegistry::class)->register(app(CompositionRenderFeature::class));
        }
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
        $this->actor = new GenericUser(['id' => 7, 'deniedUse' => []]);
        Gate::before(fn (Authenticatable $actor, string $ability, array $arguments): bool => $ability !== 'use' || ! in_array($arguments[0]->id, $actor->deniedUse ?? [], true));
    }

    /** Immutable database fixture; SitePartCommands have separate acceptance tests. */
    private function revision(?Document $document = null): SitePartRevision
    {
        $document ??= new Document([new Node('part-text', 'test.composition', props: new JsonObject(['heading' => 'Published immutable content']))]);
        $payload = SitePartPayload::document('section', $document);
        $part = new SitePart;
        $part->forceFill(['id' => (string) Str::uuid(), 'kind' => 'section', 'name' => 'Published fixture', 'draft_payload' => $payload, 'write_version' => 1, 'created_by' => '7', 'created_by_type' => GenericUser::class, 'updated_by' => '7', 'updated_by_type' => GenericUser::class])->save();
        $revision = new SitePartRevision;
        $revision->forceFill(['id' => (string) Str::uuid(), 'resource_id' => $part->id, 'kind' => 'section', 'sequence' => 1, 'payload' => $payload, 'digest' => app(CanonicalJson::class)->digest($payload), 'published_from_version' => 0, 'created_by' => '7', 'created_by_type' => GenericUser::class, 'published_at' => now()])->save();
        $part->forceFill(['current_revision_id' => $revision->id])->save();

        return $revision;
    }

    private function referenceDocument(SitePartRevision $revision): Document
    {
        return CompositionData::promote(new Document([new Node('ref', 'core.global-reference', props: new JsonObject(['revision' => $revision->reference()->jsonSerialize()]))]));
    }

    private function page(Document $document): array
    {
        $page = Page::factory()->create(['draft_content' => $document])->fresh();

        return [$page, app(PageLocks::class)->acquire($this->actor, $page->id)];
    }

    private function captured(SitePartRevision $revision): string
    {
        [$source, $lease] = $this->page($this->referenceDocument($revision));

        return app(ClipboardCommands::class)->capture($this->actor, $source->id, $lease->token, 0, 'ref', (string) Str::uuid(), 'en')->toJson();
    }

    public function test_immutable_revision_pins_have_a_distinct_typed_collection_and_copy_without_following_latest(): void
    {
        $revision = $this->revision();
        $text = $this->captured($revision);
        $payload = json_decode($text);
        $this->assertCount(1, $payload->references->revisions);
        $this->assertSame('revision', $payload->references->revisions[0]->kind);
        $this->assertSame($revision->id, $payload->references->revisions[0]->value->revisionId);
        $this->assertSame([], $payload->references->media);
        $this->assertSame([], $payload->references->pages);
        $this->assertSame(1, $payload->requiredCapabilities->{'render.composition'});
        [$destination, $lease] = $this->page(new Document);
        $clipboard = app(ClipboardCommands::class);
        $review = $clipboard->review($this->actor, $destination->id, $lease->token, 0, $text, 'en');
        $this->assertFalse($review->references->blocking);
        $this->assertSame('valid', $review->references->occurrences[0]['status']);
        $result = $clipboard->paste($this->actor, $destination->id, $lease->token, 0, $text, InsertPosition::end(), 'en');
        $node = $result->mutation->page->draft_content->nodes[0];
        $this->assertNotSame('ref', $node->id);
        $this->assertTrue(app(CanonicalJson::class)->equals($revision->reference(), $node->props->get('revision')));
        $this->assertNotNull(CompositionData::payload($result->mutation->page->draft_content));
        $this->assertSame(1, $destination->fresh()->write_version);
        $this->assertNull($destination->fresh()->published_content);
    }

    public function test_revoked_use_on_a_nested_published_revision_blocks_the_entire_retained_clipboard(): void
    {
        $nested = $this->revision();
        $outer = $this->revision($this->referenceDocument($nested));
        $text = $this->captured($outer);
        [$destination, $lease] = $this->page(new Document);
        $this->actor->deniedUse = [$nested->resource_id];
        $clipboard = app(ClipboardCommands::class);
        $this->assertTrue($clipboard->review($this->actor, $destination->id, $lease->token, 0, $text, 'en')->references->blocking);
        try {
            $clipboard->paste($this->actor, $destination->id, $lease->token, 0, $text, InsertPosition::end(), 'en');
            $this->fail('Access to an outer published part cannot grant access to its private pinned closure.');
        } catch (ValidationException) {
            $this->assertSame(0, $destination->fresh()->write_version);
            $this->assertCount(0, $destination->fresh()->draft_content->nodes);
        }
    }

    public function test_cross_domain_revision_mapping_also_requires_a_destination_aware_trusted_inspector(): void
    {
        app()->bind(ClipboardDomainResolver::class, fn () => new class implements ClipboardDomainResolver
        {
            public function domain(Authenticatable $actor, Page $page): string
            {
                return (string) $page->id;
            }
        });
        $revision = $this->revision();
        $text = $this->captured($revision);
        [$destination, $lease] = $this->page(new Document);
        $pin = $revision->reference()->jsonSerialize();
        $map = ReferenceMap::fromArray([['nodeId' => 'ref', 'path' => '/props/revision', 'kind' => 'revision', 'original' => $pin, 'value' => $pin, 'keepUnverified' => true]]);
        $clipboard = app(ClipboardCommands::class);
        $this->assertTrue($clipboard->review($this->actor, $destination->id, $lease->token, 0, $text, 'en', $map)->references->blocking);
        app()->instance('clipboard-revision-domain-adapter', new class($destination->id) implements CopyReferenceInspector
        {
            public function __construct(private int $destinationId) {}

            public function inspect(Authenticatable $actor, Reference $reference, CopyContext $destination): ?ReferenceCheck
            {
                return $reference->kind === 'revision' && $destination->pageId === $this->destinationId ? ReferenceCheck::valid('Trusted shared published resource in this destination domain.') : null;
            }
        });
        app()->tag('clipboard-revision-domain-adapter', CopyReferenceInspector::class);
        $clipboard = app(ClipboardCommands::class);
        $this->assertFalse($clipboard->review($this->actor, $destination->id, $lease->token, 0, $text, 'en', $map)->references->blocking);
        $this->actor->deniedUse = [$revision->resource_id];
        $this->assertTrue($clipboard->review($this->actor, $destination->id, $lease->token, 0, $text, 'en', $map)->references->blocking, 'The trusted domain adapter cannot bypass fresh core use authorization.');
    }

    public function test_withdrawn_revisions_cannot_be_revived_through_retained_clipboard_or_numeric_identity_hints(): void
    {
        $revision = $this->revision();
        $text = $this->captured($revision);
        [$destination, $lease] = $this->page(new Document);
        DB::table('site_builder_site_part_revisions')->where('id', $revision->id)->update(['withdrawn_at' => now(), 'withdrawal_reason' => 'Test withdrawal']);
        $review = app(ClipboardCommands::class)->review($this->actor, $destination->id, $lease->token, 0, $text, 'en');
        $this->assertTrue($review->references->blocking);
        $this->assertSame(0, $destination->fresh()->write_version);
    }
}
