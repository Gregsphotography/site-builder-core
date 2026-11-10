<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Persistence;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Composition\CompositionData;
use SiteBuilder\Core\Composition\CompositionResolver;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Contracts\PageDeletionGuard;
use SiteBuilder\Core\Design\DesignData;
use SiteBuilder\Core\Design\DesignOperations;
use SiteBuilder\Core\Design\DesignResolver;
use SiteBuilder\Core\DraftHistory\DraftHistory;
use SiteBuilder\Core\DraftHistory\HistoryContext;
use SiteBuilder\Core\DraftHistory\LogicalAction;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageRedirect;
use SiteBuilder\Core\Models\PageRevision;
use SiteBuilder\Core\Models\PageSlug;
use SiteBuilder\Core\Rendering\LayoutRegistry;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;
use SiteBuilder\Core\Rendering\SafeUrl;
use SiteBuilder\Core\SiteParts\ContentDependencies;
use SiteBuilder\Core\SiteParts\SitePartCommands;

final class PageCommands
{
    public function __construct(private PageAccess $access, private PageLocks $locks, private DocumentValidator $validator, private CanonicalJson $json, private LocaleCatalog $locales, private LayoutRegistry $layouts, private ComponentRegistry $registry) {}

    public function create(Authenticatable $actor, array $metadata, array $slugs = [], ?Document $document = null): MutationResult
    {
        return DB::transaction(function () use ($actor, $metadata, $slugs, $document): MutationResult {
            $this->access->authorize($actor, 'create', Page::class);
            $document = CompositionData::authoringDefaults($document ?? new Document);
            Validator::make($metadata, ['title' => ['required', 'string', 'max:255']])->validate();
            $this->metadata($metadata);
            $this->preserveUnsupported(null, $document);
            $creationContext = new RenderContext($this->locales->default(), 'preview', layout: $metadata['layout'] ?? null);
            app(CompositionResolver::class)->lockMutation(null, $document, $creationContext, $actor);
            app(SitePartCommands::class)->validateNewPins($actor, null, $document);
            $this->validator->validate($document, context: $creationContext);
            $page = new Page($metadata);
            $page->forceFill(['status' => 'draft', 'draft_content' => $document, 'write_version' => 0])->save();
            $this->slugs($page, $slugs);
            app(ContentDependencies::class)->syncPage($page);

            return $this->result($page->fresh());
        }, 3);
    }

    public function saveDraft(Authenticatable $actor, int $pageId, string $token, int $expectedVersion, Document $document, ?HistoryContext $history = null, ?LogicalAction $action = null): MutationResult
    {
        return $this->mutate($actor, $pageId, $token, $expectedVersion, 'update', function (Page $page) use ($document): void {
            $this->preserveUnsupported($page->draft_content, $document);
            $context = new RenderContext($this->locales->default(), 'preview', $page->id, $page->layout);
            app(DesignResolver::class)->assertUnavailablePreserved($page->draft_content, $document, $context);
            $this->validator->validate($document, context: $context);
            if (! $this->json->equals($page->draft_content, $document)) {
                $page->draft_content = $document;
            }
        }, $history, $action ?? new LogicalAction('edit', 'Edit content'), candidate: $document);
    }

    public function saveMetadata(Authenticatable $actor, int $pageId, string $token, int $expectedVersion, array $metadata, ?array $slugs = null, ?HistoryContext $history = null, ?LogicalAction $action = null): MutationResult
    {
        return $this->mutate($actor, $pageId, $token, $expectedVersion, 'update', function (Page $page) use ($metadata, $slugs): void {
            $this->metadata($metadata);
            if (array_key_exists('layout', $metadata) && $metadata['layout'] !== $page->layout) {
                $context = new RenderContext($this->locales->default(), 'preview', $page->id, $metadata['layout']);
                foreach ([$page->draft_content, $page->published_content] as $document) {
                    if ($document === null) {
                        continue;
                    }
                    if (CompositionData::payload($document) !== null && app(CompositionResolver::class)->diagnostics($document, $context) !== []) {
                        throw ValidationException::withMessages(['layout' => 'The new wrapper cannot render the pinned composition regions or site design.']);
                    }
                    if (app(RenderFeatureRegistry::class)->readOnly($document)) {
                        throw ValidationException::withMessages(['layout' => 'Unavailable document rendering requirements prevent an unreviewed wrapper change.']);
                    }
                    if (DesignData::payload($document->extensions) !== null && app(DesignOperations::class)->compatibility($document, $context) !== []) {
                        throw ValidationException::withMessages(['layout' => 'The new wrapper cannot render the pinned document design. Choose a compatible registered adapter.']);
                    }
                }
            }
            $page->fill($metadata);
            if ($slugs !== null) {
                $beforeSlugs = $page->slugs()->pluck('slug', 'locale')->all();
                $this->slugs($page, $slugs);
                // Slugs are separate rows; only an actual URL change advances the page.
                if (! $this->json->equals($beforeSlugs, $page->slugs()->pluck('slug', 'locale')->all())) {
                    $page->write_version++;
                }
            }
        }, $history, $action ?? new LogicalAction('metadata', 'Update page settings'));
    }

    public function publish(Authenticatable $actor, int $pageId, string $token, int $expectedVersion, string $intendedDigest, ?string $label = null): MutationResult
    {
        return $this->mutate($actor, $pageId, $token, $expectedVersion, 'publish', function (Page $page) use ($actor, $intendedDigest, $label): void {
            if (! hash_equals($this->json->digest($page->draft_content), $intendedDigest)) {
                throw new WriteConflict('draft_digest_changed', $page->write_version);
            }
            Validator::make(['label' => $label], ['label' => ['nullable', 'string', 'max:255']])->validate();
            $locales = $page->slugs()->pluck('locale')->all();
            $this->validator->validate($page->draft_content ?? new Document, true, $locales, new RenderContext($this->locales->default(), 'public', $page->id, $page->layout));
            $this->layouts->view($page->layout);
            $page->forceFill(['published_content' => $page->draft_content, 'status' => 'published', 'published_at' => now(), 'published_from_version' => $page->write_version]);
            $revision = new PageRevision;
            $identity = ActorIdentity::from($actor);
            $revision->forceFill(['page_id' => $page->id, 'scope' => 'content', 'content' => $page->draft_content, 'created_by' => $identity->id, 'created_by_type' => $identity->type, 'label' => $label, 'created_at' => now(), 'published_from_version' => $page->write_version, 'origin' => 'native'])->save();
            $keep = (int) config('site-builder.revisions.keep', 20);
            if ($keep > 0) {
                // Historical imports survive retention; only native publication history is pruned.
                $ids = $page->revisions()->where('origin', 'native')->skip($keep)->take(PHP_INT_MAX)->pluck('id');
                $page->revisions()->whereIn('id', $ids)->delete();
            }
            $page->write_version++;
        }, boundary: true);
    }

    public function unpublish(Authenticatable $actor, int $pageId, string $token, int $expectedVersion): MutationResult
    {
        return $this->mutate($actor, $pageId, $token, $expectedVersion, 'publish', function (Page $page): void {
            $page->forceFill(['status' => 'draft', 'published_at' => null]);
            $page->write_version++;
        }, boundary: true);
    }

    public function restore(Authenticatable $actor, int $pageId, string $token, int $expectedVersion, int $revisionId): MutationResult
    {
        return $this->mutate($actor, $pageId, $token, $expectedVersion, 'restore', function (Page $page) use ($revisionId): void {
            $revision = $page->revisions()->where('scope', 'content')->findOrFail($revisionId);
            $this->validator->validate($revision->content ?? new Document, context: new RenderContext($this->locales->default(), 'preview', $page->id, $page->layout));
            $page->draft_content = $revision->content;
            $page->write_version++;
        }, boundary: true, candidate: fn (Page $page) => $page->revisions()->where('scope', 'content')->findOrFail($revisionId)->content);
    }

    public function delete(Authenticatable $actor, int $pageId, string $token, int $expectedVersion): void
    {
        $this->mutate($actor, $pageId, $token, $expectedVersion, 'delete', function (Page $page) use ($actor): void {
            foreach (app()->tagged(PageDeletionGuard::class) as $guard) {
                $guard->check($page, $actor);
            }
            $page->delete();
        }, boundary: true);
    }

    private function mutate(Authenticatable $actor, int $pageId, string $token, int $expectedVersion, string $ability, callable $command, ?HistoryContext $history = null, ?LogicalAction $action = null, bool $boundary = false, Document|\Closure|null $candidate = null): MutationResult
    {
        return DB::transaction(function () use ($actor, $pageId, $token, $expectedVersion, $ability, $command, $history, $action, $boundary, $candidate): MutationResult {
            $page = Page::query()->lockForUpdate()->findOrFail($pageId);
            $userId = $this->access->authorize($actor, $ability, $page);
            $this->locks->assertHeld($page, $userId, $token);
            if ($page->write_version !== $expectedVersion) {
                throw new WriteConflict('stale_version', $page->write_version);
            }
            $context = new RenderContext($this->locales->default(), 'preview', $page->id, $page->layout);
            $target = $candidate instanceof \Closure ? $candidate($page) : $candidate;
            if ($target === null && $ability === 'publish') {
                $target = $page->draft_content;
            }
            app(CompositionResolver::class)->lockMutation($page->draft_content, $target, $context, $actor);
            if ($target !== null) {
                app(SitePartCommands::class)->validateNewPins($actor, $page->draft_content, $target);
            }
            if ($history !== null) {
                app(DraftHistory::class)->assertCurrent($history, $actor, $page, $token);
            }
            $before = $page->draft_content;
            $command($page);
            if ($page->exists && $page->isDirty()) {
                $page->write_version = $expectedVersion + 1;
                $page->save();
            }
            if ($boundary) {
                app(DraftHistory::class)->invalidatePage($pageId);
            } elseif ($history !== null) {
                app(DraftHistory::class)->recordAccepted($history, $actor, $page, $token, $before, $page->draft_content, $action ?? new LogicalAction('edit', 'Edit content'));
            }

            app(ContentDependencies::class)->syncPage($page);

            return $this->result($page->exists ? $page->fresh() : $page);
        }, 3);
    }

    private function result(Page $page): MutationResult
    {
        return new MutationResult($page, $this->json->digest($page->draft_content), $page->draft_content === null ? [] : $this->validator->diagnostics($page->draft_content, context: new RenderContext($this->locales->default(), 'preview', $page->id, $page->layout)));
    }

    private function metadata(array $data): void
    {
        if (array_diff(array_keys($data), ['title', 'type', 'layout', 'seo', 'schema', 'data']) !== []) {
            throw ValidationException::withMessages(['metadata' => 'Only shared page metadata may be changed here.']);
        }
        Validator::make($data, ['title' => ['sometimes', 'required', 'string', 'max:255'], 'type' => ['sometimes', 'required', 'string', 'max:255'], 'layout' => ['nullable', 'string', 'max:255']])->validate();
        if (array_key_exists('layout', $data)) {
            $this->layouts->view($data['layout']);
        }
        foreach (['seo', 'schema', 'data'] as $key) {
            if (array_key_exists($key, $data)) {
                $this->json->encode($data[$key]);
            }
        }
        if (isset($data['seo'])) {
            $seo = json_decode(json_encode($data['seo'], JSON_THROW_ON_ERROR));
            if (! $seo instanceof \stdClass && $seo !== []) {
                throw ValidationException::withMessages(['seo' => 'SEO must be a locale-keyed object.']);
            }
            foreach ($seo as $values) {
                if (! $values instanceof \stdClass) {
                    throw ValidationException::withMessages(['seo' => 'Each SEO locale must contain a property object.']);
                }
                foreach (['meta_title', 'meta_description', 'canonical', 'og_image'] as $key) {
                    if (isset($values->{$key}) && ! is_string($values->{$key})) {
                        throw ValidationException::withMessages(['seo' => 'SEO text and URL values must be strings.']);
                    }
                }
                foreach (['noindex', 'nofollow'] as $key) {
                    if (isset($values->{$key}) && ! is_bool($values->{$key})) {
                        throw ValidationException::withMessages(['seo' => 'SEO flags must be booleans.']);
                    }
                }
                foreach (['canonical', 'og_image'] as $key) {
                    if (isset($values->{$key}) && ! app(SafeUrl::class)->valid($values->{$key}, $key === 'og_image')) {
                        throw ValidationException::withMessages(['seo' => 'Unsafe SEO URL.']);
                    }
                }
                if (isset($values->canonical) && ! app(SafeUrl::class)->canonical($values->canonical)) {
                    throw ValidationException::withMessages(['seo' => 'Canonical URLs require HTTP/HTTPS or a safe root path.']);
                }
            }
        }
    }

    private function slugs(Page $page, array $slugs): void
    {
        foreach ($slugs as $locale => $slug) {
            if (! is_string($locale) || ! $this->locales->has($locale) || ($slug !== null && (! is_string($slug) || strlen($slug) > 255 || ! preg_match('~^[\pL\pN_-]+(?:/[\pL\pN_-]+)*$~uD', $slug) || in_array(explode('/', $slug)[0], $this->locales->all(), true)))) {
                throw ValidationException::withMessages(['slugs' => 'Use configured locales and safe nonreserved slash-separated slugs, or null to remove a locale.']);
            }
            if ($slug !== null && PageSlug::query()->where('locale', $locale)->where('slug', $slug)->where('page_id', '!=', $page->id)->exists()) {
                throw ValidationException::withMessages(['slugs' => 'This URL path is already used by another page in this language.']);
            }
            $old = $page->slugs()->where('locale', $locale)->first();
            if ($old !== null && $old->slug !== $slug) {
                PageRedirect::query()->updateOrCreate(['locale' => $locale, 'from_slug' => $old->slug], ['page_id' => $page->id, 'status' => 301]);
            }
            if ($slug === null) {
                $old?->delete();
            } else {
                try {
                    $page->slugs()->updateOrCreate(['locale' => $locale], ['slug' => $slug]);
                } catch (UniqueConstraintViolationException $exception) {
                    $message = $exception->errorInfo[2] ?? '';
                    $table = $page->getConnection()->getTablePrefix().(new PageSlug)->getTable();
                    if (! str_contains($message, 'site_builder_page_slugs_locale_slug_unique') && ! str_contains($message, 'UNIQUE constraint failed: '.$table.'.locale, '.$table.'.slug')) {
                        throw $exception;
                    }
                    throw ValidationException::withMessages(['slugs' => 'This URL path is already used by another page in this language.']);
                }
                PageRedirect::query()->where('locale', $locale)->where('from_slug', $slug)->delete();
            }
        }
    }

    private function preserveUnsupported(?Document $old, Document $new): void
    {
        app(RenderFeatureRegistry::class)->assertPreserved($old, $new);
        $indexed = [];
        $walk = function (array $nodes, bool $before) use (&$walk, &$indexed): void {
            foreach ($nodes as $node) {
                if ($before) {
                    $indexed[$node->id] = $node;
                } else {
                    $definition = $this->registry->resolve($node->type);
                    if (($definition === null || $definition->version() !== $node->version) && (! isset($indexed[$node->id]) || ! $this->json->equals($node, $indexed[$node->id]))) {
                        throw ValidationException::withMessages(['document' => 'Unsupported nodes are read-only; supported siblings can still be saved.']);
                    }
                }
                $walk($node->children, $before);
            }
        };
        $walk($old?->nodes ?? [], true);
        $walk($new->nodes, false);
    }
}
