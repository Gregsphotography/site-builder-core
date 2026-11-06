<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SavedSections;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use SiteBuilder\Core\Composition\CompositionResolver;
use SiteBuilder\Core\Composition\SectionSourceScope;
use SiteBuilder\Core\Composition\SourceDocumentValidator;
use SiteBuilder\Core\Composition\SourceSnapshot;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Editing\TreeResolver;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\ActorIdentity;
use SiteBuilder\Core\Persistence\PageAccess;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\SiteParts\ContentDependencies;
use SiteBuilder\Core\SiteParts\SitePartCommands;
use Throwable;

final class SavedSectionCommands
{
    public function __construct(private Gate $gate, private PageAccess $access, private PageLocks $locks, private CanonicalJson $json, private DocumentValidator $validator) {}

    public function available(): bool
    {
        $schema = (new SavedSection)->getConnection()->getSchemaBuilder();

        return $schema->hasTable('site_builder_saved_sections') && $schema->hasTable('site_builder_saved_section_versions');
    }

    public function entries(Authenticatable $actor, ?string $search = null, ?string $category = null, int $limit = 50): Collection
    {
        return $this->searchPage($actor, $search, $category, null, $limit)['entries'];
    }

    public function find(Authenticatable $actor, string $sectionId): ?SavedSection
    {
        if (! $this->available()) {
            return null;
        }
        $section = SavedSection::query()->whereNull('archived_at')->find($sectionId);

        return $section !== null && $this->gate->forUser($actor)->allows('view', $section) ? $section : null;
    }

    public function searchPage(Authenticatable $actor, ?string $search = null, ?string $category = null, ?string $cursor = null, int $limit = 30): array
    {
        if (! $this->available() || ! $this->gate->forUser($actor)->allows('viewAny', SavedSection::class)) {
            return ['entries' => new Collection, 'nextCursor' => null];
        }
        $identity = ActorIdentity::from($actor);
        if (! mb_check_encoding($search ?? '', 'UTF-8') || ! mb_check_encoding($category ?? '', 'UTF-8')) {
            throw ValidationException::withMessages(['search' => 'Use valid UTF-8 saved-section search/category text.']);
        }
        $search = mb_substr($search ?? '', 0, 255);
        $category = mb_substr($category ?? '', 0, 255);
        $needle = mb_convert_case($search, MB_CASE_FOLD, 'UTF-8');
        $filter = $this->json->digest([$search, $category, $identity->id, $identity->type]);
        $query = SavedSection::query()->whereNull('archived_at')->orderBy('name')->orderBy('id');
        $after = null;
        if ($cursor !== null) {
            try {
                $after = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($after) || ($after['filter'] ?? null) !== $filter || ! is_string($after['name'] ?? null) || ! is_string($after['id'] ?? null)) {
                    throw new InvalidArgumentException('Cursor does not match this authorized search.');
                }
            } catch (Throwable) {
                throw ValidationException::withMessages(['cursor' => 'The saved-section search cursor is invalid or belongs to another search/actor.']);
            }
        }
        $limit = max(1, min(100, $limit));
        $entries = new Collection;
        $more = false;
        // Keyset batches bound driver/query memory too. Only authorized visible
        // lookahead decides whether another page exists; no raw/private totals leak.
        while (true) {
            $batchQuery = clone $query;
            if ($after !== null) {
                $batchQuery->where(fn ($query) => $query->where('name', '>', $after['name'])->orWhere(fn ($query) => $query->where('name', $after['name'])->where('id', '>', $after['id'])));
            }
            $batch = $batchQuery->limit(100)->get();
            foreach ($batch as $section) {
                $after = ['name' => $section->name, 'id' => $section->id];
                if (! $this->gate->forUser($actor)->allows('view', $section)) {
                    continue;
                }
                if ($category !== '' && $section->category !== $category) {
                    continue;
                }
                $haystack = implode(' ', [$section->name, $section->description ?? '', ...array_filter($section->tags ?? [], 'is_string')]);
                if ($needle !== '' && ! str_contains(mb_convert_case($haystack, MB_CASE_FOLD, 'UTF-8'), $needle)) {
                    continue;
                }
                if ($entries->count() === $limit) {
                    $more = true;
                    break 2;
                }
                $entries->push($section);
            }
            if ($batch->count() < 100) {
                break;
            }
        }
        $last = $entries->last();
        $next = $more ? Crypt::encryptString(json_encode(['filter' => $filter, 'name' => $last->name, 'id' => $last->id], JSON_THROW_ON_ERROR)) : null;

        return ['entries' => $entries, 'nextCursor' => $next];
    }

    public function capture(Authenticatable $actor, int $pageId, string $leaseToken, int $expectedPageVersion, string $rootId, array $metadata): SavedSection
    {
        $this->assertAvailable();

        return DB::transaction(function () use ($actor, $pageId, $leaseToken, $expectedPageVersion, $rootId, $metadata): SavedSection {
            $page = $this->sourcePage($actor, $pageId, $leaseToken, $expectedPageVersion);
            $identity = ActorIdentity::from($actor);
            $this->gate->forUser($actor)->authorize('create', SavedSection::class);
            $values = $this->metadata($metadata, true);
            $section = new SavedSection;
            $section->forceFill($values + ['id' => (string) Str::uuid(), 'owner_id' => $identity->id, 'owner_type' => $identity->type, 'created_by' => $identity->id, 'created_by_type' => $identity->type, 'updated_by' => $identity->id, 'updated_by_type' => $identity->type, 'write_version' => 0, 'current_version' => 0]);
            if ($section->visibility === 'shared') {
                $this->gate->forUser($actor)->authorize('share', $section);
            }
            $capture = $this->selected($page, $rootId);
            app(CompositionResolver::class)->lockMutation(null, $capture['document'], new RenderContext(config('app.locale', 'en'), 'preview', $page->id, $page->layout), $actor);
            app(SitePartCommands::class)->validateNewPins($actor, null, $capture['document']);
            $section->save();
            $this->append($section, $capture['document'], $identity, ['kind' => 'page', 'id' => (string) $page->id, 'version' => (string) $page->write_version, 'digest' => $this->json->digest($page->draft_content), 'rootIds' => [$rootId], 'copyHistoryDigest' => $capture['copyHistoryDigest'], 'renderLayout' => $page->layout]);

            return $section->fresh();
        }, 3);
    }

    public function update(Authenticatable $actor, string $sectionId, int $expectedSectionVersion, array $metadata): SavedSection
    {
        $this->assertAvailable();

        return DB::transaction(function () use ($actor, $sectionId, $expectedSectionVersion, $metadata): SavedSection {
            $section = $this->locked($actor, $sectionId, $expectedSectionVersion, 'update');
            $values = $this->metadata($metadata);
            if (isset($values['visibility']) && $values['visibility'] !== $section->visibility) {
                $this->gate->forUser($actor)->authorize('share', $section);
            }
            $section->forceFill($values);
            if (! $section->isDirty()) {
                return $section;
            }
            $previous = $section->versions()->firstOrFail();
            $identity = ActorIdentity::from($actor);
            $section->forceFill(['write_version' => $expectedSectionVersion + 1, 'updated_by' => $identity->id, 'updated_by_type' => $identity->type]);
            $this->append($section, $previous->document, $identity, $this->provenance($previous));

            return $section->fresh();
        }, 3);
    }

    public function recapture(Authenticatable $actor, string $sectionId, int $expectedSectionVersion, int $pageId, string $leaseToken, int $expectedPageVersion, string $rootId, array $metadata = []): SavedSection
    {
        $this->assertAvailable();

        return DB::transaction(function () use ($actor, $sectionId, $expectedSectionVersion, $pageId, $leaseToken, $expectedPageVersion, $rootId, $metadata): SavedSection {
            $page = $this->sourcePage($actor, $pageId, $leaseToken, $expectedPageVersion);
            $section = $this->locked($actor, $sectionId, $expectedSectionVersion, 'update');
            $values = $this->metadata($metadata);
            if (isset($values['visibility']) && $values['visibility'] !== $section->visibility) {
                $this->gate->forUser($actor)->authorize('share', $section);
            }
            $identity = ActorIdentity::from($actor);
            $section->forceFill($values + ['write_version' => $expectedSectionVersion + 1, 'updated_by' => $identity->id, 'updated_by_type' => $identity->type]);
            $capture = $this->selected($page, $rootId);
            app(CompositionResolver::class)->lockMutation(null, $capture['document'], new RenderContext(config('app.locale', 'en'), 'preview', $page->id, $page->layout), $actor);
            app(SitePartCommands::class)->validateNewPins($actor, null, $capture['document']);
            $this->append($section, $capture['document'], $identity, ['kind' => 'page', 'id' => (string) $page->id, 'version' => (string) $page->write_version, 'digest' => $this->json->digest($page->draft_content), 'rootIds' => [$rootId], 'copyHistoryDigest' => $capture['copyHistoryDigest'], 'renderLayout' => $page->layout]);

            return $section->fresh();
        }, 3);
    }

    public function archive(Authenticatable $actor, string $sectionId, int $expectedSectionVersion): SavedSection
    {
        $this->assertAvailable();

        return DB::transaction(function () use ($actor, $sectionId, $expectedSectionVersion): SavedSection {
            $section = $this->locked($actor, $sectionId, $expectedSectionVersion, 'delete');
            $previous = $section->versions()->firstOrFail();
            $identity = ActorIdentity::from($actor);
            $section->forceFill(['archived_at' => now(), 'write_version' => $expectedSectionVersion + 1, 'updated_by' => $identity->id, 'updated_by_type' => $identity->type]);
            $this->append($section, $previous->document, $identity, $this->provenance($previous));

            return $section->fresh();
        }, 3);
    }

    public function snapshot(Authenticatable $actor, string $sectionId, int|string $version, bool $lock = false): SourceSnapshot
    {
        $this->assertAvailable();
        $section = SavedSection::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($sectionId);
        ActorIdentity::from($actor);
        $this->gate->forUser($actor)->authorize('use', $section);
        if ($section->archived_at !== null || (string) $section->current_version !== (string) $version) {
            throw new WriteConflict('stale_saved_section_source', $section->write_version);
        }
        $revision = $section->versions()->where('version', $section->current_version)->firstOrFail();
        if (! hash_equals($revision->digest, $this->json->digest($revision->document))) {
            throw new LogicException('The saved-section snapshot digest does not match immutable content.');
        }

        return SourceSnapshot::fromDocument('saved-section', $section->id, (string) $revision->version, $revision->document, $this->provenance($revision));
    }

    private function sourcePage(Authenticatable $actor, int $pageId, string $token, int $version): Page
    {
        $page = Page::query()->lockForUpdate()->findOrFail($pageId);
        $identity = $this->access->authorize($actor, 'update', $page);
        $this->locks->assertHeld($page, $identity, $token);
        if ($page->write_version !== $version) {
            throw new WriteConflict('stale_version', $page->write_version);
        }

        return $page;
    }

    private function selected(Page $page, string $rootId): array
    {
        $document = $page->draft_content ?? new Document;
        $scope = SectionSourceScope::project($document);
        $root = app(TreeResolver::class)->locate($document, $rootId)->node;
        $extensions = $scope['extensions']->toObject();
        if ($scope['renderFeatures'] !== null) {
            // Source context remains readable; application projects these page
            // settings into inactive provenance and uses destination design.
            $extensions->{'site-builder.render'} = $scope['renderFeatures'];
        }
        $selected = new Document([$root], new JsonObject($extensions));
        app(SourceDocumentValidator::class)->validate($selected, context: new RenderContext(config('app.locale', 'en'), 'preview', $page->id, $page->layout));

        return ['document' => $selected, 'copyHistoryDigest' => $scope['copyHistoryDigest']];
    }

    private function locked(Authenticatable $actor, string $id, int $version, string $ability): SavedSection
    {
        $section = SavedSection::query()->lockForUpdate()->findOrFail($id);
        ActorIdentity::from($actor);
        $this->gate->forUser($actor)->authorize($ability, $section);
        if ($section->write_version !== $version) {
            throw new WriteConflict('stale_saved_section_version', $section->write_version);
        }
        if ($section->archived_at !== null) {
            throw new WriteConflict('saved_section_archived', $section->write_version);
        }

        return $section;
    }

    private function append(SavedSection $section, Document $document, ActorIdentity $actor, array $source): void
    {
        app(SourceDocumentValidator::class)->validate($document, context: new RenderContext(config('app.locale', 'en'), 'preview', layout: $source['renderLayout'] ?? null));
        $version = $section->current_version + 1;
        $revision = new SavedSectionVersion;
        $revision->forceFill(['id' => (string) Str::uuid(), 'section_id' => $section->id, 'version' => $version, 'document' => $document, 'digest' => $this->json->digest($document), 'metadata' => (object) ['name' => $section->name, 'description' => $section->description, 'category' => $section->category, 'tags' => $section->tags, 'visibility' => $section->visibility, 'archived_at' => $section->archived_at?->toAtomString(), 'sourceCopyHistoryDigest' => $source['copyHistoryDigest'] ?? null, 'sourceRenderLayout' => $source['renderLayout'] ?? null], 'source_kind' => $source['kind'], 'source_id' => $source['id'], 'source_version' => $source['version'], 'source_digest' => $source['digest'], 'source_root_ids' => $source['rootIds'], 'created_by' => $actor->id, 'created_by_type' => $actor->type, 'created_at' => now()])->save();
        app(ContentDependencies::class)->syncSavedVersion($revision);
        $section->forceFill(['current_version' => $version])->save();
    }

    private function provenance(SavedSectionVersion $revision): array
    {
        return ['kind' => $revision->source_kind, 'id' => $revision->source_id, 'version' => $revision->source_version, 'digest' => $revision->source_digest, 'rootIds' => $revision->source_root_ids, 'copyHistoryDigest' => $revision->metadata->sourceCopyHistoryDigest ?? null, 'renderLayout' => $revision->metadata->sourceRenderLayout ?? null];
    }

    private function metadata(array $metadata, bool $creating = false): array
    {
        if (array_diff(array_keys($metadata), ['name', 'description', 'category', 'tags', 'visibility']) !== []) {
            throw ValidationException::withMessages(['savedSection' => 'Only declared saved-section metadata can change.']);
        }
        Validator::make($metadata, ['name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'category' => ['sometimes', 'required', 'string', 'max:255'], 'tags' => ['sometimes', 'array', 'max:20'], 'tags.*' => ['string', 'max:80'], 'visibility' => ['sometimes', 'in:private,shared']])->validate();

        return $creating ? $metadata + ['description' => null, 'category' => 'General', 'tags' => [], 'visibility' => 'private'] : $metadata;
    }

    private function assertAvailable(): void
    {
        if (! $this->available()) {
            throw new LogicException('Install optional saved sections with site-builder:saved-sections-install, then run migrate.');
        }
    }
}
