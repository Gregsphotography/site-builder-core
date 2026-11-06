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
use LogicException;
use RuntimeException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Composition\CompositionCommands;
use SiteBuilder\Core\Composition\InsertPosition;
use SiteBuilder\Core\Composition\SourceSelection;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Exceptions\InvalidDocument;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\SavedSections\SavedSection;
use SiteBuilder\Core\SavedSections\SavedSectionCommands;
use SiteBuilder\Core\SavedSections\SavedSectionVersion;
use SiteBuilder\Core\Tests\Fixtures\CompositionBlock;
use SiteBuilder\Core\Tests\Fixtures\PortalUser;
use SiteBuilder\Core\Tests\SavedSectionsTestCase;

class SavedSectionsTest extends SavedSectionsTestCase
{
    private PortalUser $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('i', 32)), 'site-builder.locales' => ['en' => [], 'de' => []]]);
        app(ComponentRegistry::class)->register(new CompositionBlock);
        $this->actor = new PortalUser(['id' => '01JBDR03XETB5V4C2625GJ44JD', 'allowed' => true, 'canShare' => true]);
        foreach (['viewAny', 'view', 'create', 'update', 'use', 'share', 'delete', 'publish', 'preview'] as $ability) {
            Gate::define($ability, function (Authenticatable $actor, mixed $subject = null) use ($ability): bool {
                if (! $actor->allowed) {
                    return false;
                }
                if (! $subject instanceof SavedSection) {
                    return true;
                }
                $owner = $subject->owner_id === (string) $actor->getAuthIdentifier() && $subject->owner_type === $actor::class;

                return match ($ability) {
                    'view', 'use' => $owner || $subject->visibility === 'shared',
                    'share' => $owner && $actor->canShare === true,
                    default => $owner,
                };
            });
        }
    }

    private function source(?Document $document = null, ?Authenticatable $actor = null): array
    {
        $actor ??= $this->actor;
        $document ??= new Document([new Node('root', 'test.composition', props: new JsonObject(['heading' => 'Saved text']))]);
        $page = Page::factory()->create(['draft_content' => $document]);
        $lease = app(PageLocks::class)->acquire($actor, $page->id);

        return [$page, $lease];
    }

    private function selection(SavedSection $section): SourceSelection
    {
        $source = app(SavedSectionCommands::class)->snapshot($this->actor, $section->id, $section->current_version);

        return new SourceSelection($source->kind, $source->id, $source->version, $source->digest, 'en');
    }

    public function test_capture_recapture_metadata_archive_are_cas_and_do_not_update_existing_copies(): void
    {
        [$page, $lease] = $this->source();
        $saved = app(SavedSectionCommands::class);
        $section = $saved->capture($this->actor, $page->id, $lease->token, 0, 'root', ['name' => 'My section']);
        $this->assertSame(0, $section->write_version);
        $this->assertSame(1, $section->current_version);
        $this->assertSame('private', $section->visibility);
        $this->assertSame($this->actor->id, $section->owner_id);
        $this->assertSame(PortalUser::class, $section->owner_type);
        $this->assertSame(0, $page->fresh()->write_version);
        $first = $section->versions()->firstOrFail();
        [$destination, $destinationLease] = $this->source(new Document);
        $inserted = app(CompositionCommands::class)->insert($this->actor, $destination->id, $destinationLease->token, 0, $this->selection($section), InsertPosition::end());
        $selection = $this->selection($section);
        $updated = $saved->update($this->actor, $section->id, 0, ['name' => 'Renamed', 'description' => 'Current metadata']);
        $this->assertSame(1, $updated->write_version);
        $this->assertSame(2, $updated->current_version);
        $this->assertSame('My section', $first->fresh()->metadata->name);
        try {
            $saved->update($this->actor, $section->id, 0, ['name' => 'Late']);
            $this->fail('Late metadata must conflict.');
        } catch (WriteConflict $exception) {
            $this->assertSame('stale_saved_section_version', $exception->reason);
        }
        try {
            app(CompositionCommands::class)->insert($this->actor, $destination->id, $destinationLease->token, 1, $selection, InsertPosition::end());
            $this->fail('A dialog must not follow a changed source silently.');
        } catch (WriteConflict) {
            $this->assertSame(1, $destination->fresh()->write_version);
        }
        $changed = new Document([new Node('root', 'test.composition', props: new JsonObject(['heading' => 'New captured text']))]);
        app(PageCommands::class)->saveDraft($this->actor, $page->id, $lease->token, 0, $changed);
        $recaptured = $saved->recapture($this->actor, $section->id, 1, $page->id, $lease->token, 1, 'root');
        $this->assertSame(3, $recaptured->current_version);
        $this->assertSame('Saved text', $destination->fresh()->draft_content->nodes[0]->props->get('heading'));
        $this->assertSame($inserted->insertedIds[0], $destination->fresh()->draft_content->nodes[0]->id);
        $archived = $saved->archive($this->actor, $section->id, 2);
        $this->assertNotNull($archived->archived_at);
        $this->assertSame(4, $archived->current_version);
        $this->assertSame(4, $archived->versions()->count());
        $this->assertCount(0, $saved->entries($this->actor));
        $this->assertSame('Saved text', $destination->fresh()->draft_content->nodes[0]->props->get('heading'));
        $this->expectException(WriteConflict::class);
        $saved->snapshot($this->actor, $section->id, 4);
    }

    public function test_private_model_identity_and_sharing_changes_are_separately_authorized(): void
    {
        [$page, $lease] = $this->source();
        $saved = app(SavedSectionCommands::class);
        $section = $saved->capture($this->actor, $page->id, $lease->token, 0, 'root', ['name' => 'Private']);
        $otherDomain = new GenericUser(['id' => $this->actor->id, 'allowed' => true, 'canShare' => true]);
        $this->assertCount(0, $saved->entries($otherDomain));
        $this->assertNull($saved->find($otherDomain, $section->id));
        try {
            $saved->snapshot($otherDomain, $section->id, 1);
            $this->fail('Same ID in another model does not own the source.');
        } catch (AuthorizationException) {
            $this->assertSame('private', $section->fresh()->visibility);
        }
        $section = $saved->update($this->actor, $section->id, 0, ['visibility' => 'shared']);
        $this->assertCount(1, $saved->entries($otherDomain));
        $this->actor->canShare = false;
        foreach ([
            fn () => $saved->update($this->actor, $section->id, 1, ['visibility' => 'private']),
            fn () => $saved->recapture($this->actor, $section->id, 1, $page->id, $lease->token, 0, 'root', ['visibility' => 'private']),
        ] as $action) {
            try {
                $action();
                $this->fail('Unsharing also requires the share ability.');
            } catch (AuthorizationException) {
                $this->assertSame('shared', $section->fresh()->visibility);
                $this->assertSame(1, $section->fresh()->write_version);
            }
        }
    }

    public function test_unknown_saved_nodes_have_narrow_trusted_copy_admission_and_survive_original_page_deletion(): void
    {
        $opaque = new JsonObject(['empty' => (object) [], 'list' => [], 'null' => null, 'opaqueId' => 'unknown']);
        $original = new Document([new Node('unknown', 'future.block', 9, $opaque, new JsonObject(['de' => (object) ['opaque' => []]]), children: [new Node('child', 'future.child', props: $opaque)], extensions: $opaque)], $opaque);
        [$page, $lease] = $this->source($original);
        $saved = app(SavedSectionCommands::class);
        $section = $saved->capture($this->actor, $page->id, $lease->token, 0, 'unknown', ['name' => 'Unknown historical section']);
        $selection = $this->selection($section);
        $page->delete();
        [$destination, $destinationLease] = $this->source(new Document);
        $result = app(CompositionCommands::class)->insert($this->actor, $destination->id, $destinationLease->token, 0, $selection, InsertPosition::end());
        $node = $result->mutation->page->draft_content->nodes[0];
        $this->assertNotSame('unknown', $node->id);
        $this->assertNotSame('child', $node->children[0]->id);
        $this->assertTrue(app(CanonicalJson::class)->equals($opaque, $node->props));
        $this->assertTrue(app(CanonicalJson::class)->equals($opaque, $node->extensions));
        $this->assertTrue(app(CanonicalJson::class)->equals($opaque, $result->mutation->page->draft_content->extensions->get('site-builder.copy')->entries[0]->sourceDocumentExtensions));
        try {
            app(PageCommands::class)->saveDraft($this->actor, $destination->id, $destinationLease->token, 1, new Document([new Node($node->id, 'future.block', 9, new JsonObject(['changed' => true]))]));
            $this->fail('Generic saves cannot mutate admitted unsupported content.');
        } catch (ValidationException) {
            $this->assertSame(1, $destination->fresh()->write_version);
        }
        $this->expectException(InvalidDocument::class);
        app(PageCommands::class)->publish($this->actor, $destination->id, $destinationLease->token, 1, $result->mutation->draftDigest);
    }

    public function test_capture_requires_current_source_lease_version_and_safe_partial_document_scope(): void
    {
        [$page, $lease] = $this->source();
        $saved = app(SavedSectionCommands::class);
        foreach ([fn () => $saved->capture($this->actor, $page->id, (string) Str::uuid(), 0, 'root', ['name' => 'Wrong tab']), fn () => $saved->capture($this->actor, $page->id, $lease->token, 9, 'root', ['name' => 'Stale'])] as $action) {
            try {
                $action();
                $this->fail('Capture cannot bypass source lease/version.');
            } catch (WriteConflict) {
                $this->assertSame(0, SavedSection::count());
            }
        }
        $page->forceFill(['draft_content' => new Document([new Node('root', 'test.composition')], new JsonObject(['site-builder.render' => (object) ['version' => 999]]))])->save();
        try {
            $saved->capture($this->actor, $page->id, $lease->token, 0, 'root', ['name' => 'Unsafe scope']);
            $this->fail('Required root render features cannot be stripped by section capture.');
        } catch (ValidationException) {
            $this->assertSame(0, SavedSection::count());
            $this->assertNotNull($page->fresh()->draft_content->extensions->get('site-builder.render'));
        }
    }

    public function test_versions_and_optional_downgrade_never_erase_retained_source_content(): void
    {
        [$page, $lease] = $this->source();
        $section = app(SavedSectionCommands::class)->capture($this->actor, $page->id, $lease->token, 0, 'root', ['name' => 'Retained']);
        $version = $section->versions()->firstOrFail();
        try {
            $version->forceFill(['digest' => str_repeat('0', 64)])->save();
            $this->fail('Version mutation must be refused.');
        } catch (LogicException) {
            $this->assertNotSame(str_repeat('0', 64), $version->fresh()->digest);
        }
        try {
            (require __DIR__.'/../../database/saved-sections-migrations/0001_01_01_000100_create_site_builder_saved_sections_tables.php')->down();
            $this->fail('An in-use library cannot be silently dropped.');
        } catch (RuntimeException) {
            $this->assertSame(1, SavedSection::count());
            $this->assertSame(1, SavedSectionVersion::count());
        }
    }

    public function test_policy_filtered_keyset_pages_reach_all_entries_without_private_counts_or_utf8_damage(): void
    {
        [$page, $lease] = $this->source();
        $saved = app(SavedSectionCommands::class);
        $visible = $saved->capture($this->actor, $page->id, $lease->token, 0, 'root', ['name' => 'Visible 000']);
        $other = new GenericUser(['id' => 8, 'allowed' => true, 'canShare' => true]);
        [$otherPage, $otherLease] = $this->source(actor: $other);
        $private = $saved->capture($other, $otherPage->id, $otherLease->token, 0, 'root', ['name' => 'A secret 000']);
        foreach ([[$visible, 'Visible', 70], [$private, 'A secret', 501]] as [$template, $prefix, $total]) {
            $resource = (array) DB::table('site_builder_saved_sections')->where('id', $template->id)->first();
            $version = (array) DB::table('site_builder_saved_section_versions')->where('section_id', $template->id)->first();
            $resources = $versions = [];
            for ($index = 1; $index < $total; $index++) {
                $row = array_replace($resource, ['id' => (string) Str::uuid(), 'name' => $prefix.' '.str_pad((string) $index, 3, '0', STR_PAD_LEFT), 'description' => $index === 69 ? 'Unique description needle' : null, 'tags' => $index === 69 ? '["special-keyword"]' : '[]']);
                $metadata = json_decode($version['metadata']);
                $metadata->name = $row['name'];
                $metadata->description = $row['description'];
                $metadata->tags = json_decode($row['tags']);
                $resources[] = $row;
                $versions[] = array_replace($version, ['id' => (string) Str::uuid(), 'section_id' => $row['id'], 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
            }
            DB::table('site_builder_saved_sections')->insert($resources);
            DB::table('site_builder_saved_section_versions')->insert($versions);
        }
        $cursor = null;
        $names = [];
        do {
            $result = $saved->searchPage($this->actor, cursor: $cursor, limit: 30);
            $this->assertLessThanOrEqual(30, $result['entries']->count());
            $names = [...$names, ...$result['entries']->pluck('name')->all()];
            $cursor = $result['nextCursor'];
        } while ($cursor !== null);
        $this->assertCount(70, array_unique($names));
        $this->assertSame('Visible 069', $names[69]);
        $last = SavedSection::query()->where('name', 'Visible 069')->firstOrFail();
        $this->assertSame($last->id, $saved->find($this->actor, $last->id)->id);
        $this->assertCount(1, $saved->searchPage($this->actor, search: 'special-keyword')['entries']);
        $this->assertCount(1, $saved->searchPage($this->actor, search: 'description needle')['entries']);
        $this->assertCount(0, $saved->searchPage($this->actor, search: str_repeat('é', 128), category: str_repeat('é', 256))['entries']);
        $firstPage = $saved->searchPage($this->actor, limit: 1);
        $otherDomain = new GenericUser(['id' => $this->actor->id, 'allowed' => true, 'canShare' => true]);
        $this->expectException(ValidationException::class);
        $saved->searchPage($otherDomain, cursor: $firstPage['nextCursor'], limit: 1);
    }

    public function test_repeated_capture_insert_normalizes_page_copy_bookkeeping_without_recursive_growth(): void
    {
        $opaque = new JsonObject(['sourceOpaque' => (object) ['empty' => (object) [], 'list' => [], 'null' => null]]);
        [$page, $lease] = $this->source(new Document([new Node('root', 'test.composition', props: new JsonObject(['heading' => 'Keep']))], $opaque));
        $root = 'root';
        $version = 0;
        for ($cycle = 0; $cycle < 25; $cycle++) {
            $section = app(SavedSectionCommands::class)->capture($this->actor, $page->id, $lease->token, $version, $root, ['name' => 'Cycle '.$cycle]);
            $source = app(SavedSectionCommands::class)->snapshot($this->actor, $section->id, 1);
            $this->assertNull($source->document->extensions->get('site-builder.copy'));
            $this->assertTrue(app(CanonicalJson::class)->equals($opaque, $source->document->extensions));
            if ($cycle > 0) {
                $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $source->provenance['copyHistoryDigest']);
            }
            $result = app(CompositionCommands::class)->insert($this->actor, $page->id, $lease->token, $version, $this->selection($section), InsertPosition::end());
            $root = $result->insertedIds[0];
            $version++;
        }
        $document = $page->fresh()->draft_content;
        $this->assertCount(26, $document->nodes);
        $this->assertCount(25, $document->extensions->get('site-builder.copy')->entries);
        $this->assertLessThan(65536, strlen(app(DocumentCodec::class)->encode($document)));
        $this->assertTrue(app(CanonicalJson::class)->equals($opaque->get('sourceOpaque'), $document->extensions->get('sourceOpaque')));
    }

    public function test_unknown_provenance_cannot_be_silently_normalized_during_partial_capture(): void
    {
        $extensions = new JsonObject(['site-builder.copy' => (object) ['version' => 99, 'future' => (object) ['opaque' => []]]]);
        [$page, $lease] = $this->source(new Document([new Node('root', 'test.composition')], $extensions));
        try {
            app(SavedSectionCommands::class)->capture($this->actor, $page->id, $lease->token, 0, 'root', ['name' => 'Unsafe normalization']);
            $this->fail('Unknown copy history cannot be erased.');
        } catch (ValidationException) {
            $this->assertSame(0, SavedSection::count());
            $this->assertTrue(app(CanonicalJson::class)->equals($extensions, $page->fresh()->draft_content->extensions));
        }
    }

    public function test_localized_name_description_and_decoded_tags_search_is_casefolded_portably(): void
    {
        [$page, $lease] = $this->source();
        $saved = app(SavedSectionCommands::class);
        $first = $saved->capture($this->actor, $page->id, $lease->token, 0, 'root', ['name' => 'Überblick', 'description' => 'Équipe française', 'tags' => ['Grüße', 'Straße'], 'category' => 'Neuigkeiten']);
        $second = $saved->capture($this->actor, $page->id, $lease->token, 0, 'root', ['name' => 'Autre', 'description' => 'Deuxième', 'tags' => ['ÉDITORIAL'], 'category' => 'Actualités']);
        foreach (['überBLICK', 'éQUIPE', 'GRÜSSE', 'STRASSE'] as $needle) {
            $this->assertSame([$first->id], $saved->searchPage($this->actor, search: $needle)['entries']->modelKeys());
        }
        $this->assertSame([$second->id], $saved->searchPage($this->actor, search: 'éditorial')['entries']->modelKeys());
        $this->assertSame([], $saved->searchPage($this->actor, search: 'überBLICK', category: 'Actualités')['entries']->modelKeys());
    }
}
