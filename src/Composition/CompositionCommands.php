<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Design\DesignOperations;
use SiteBuilder\Core\DraftHistory\DraftHistory;
use SiteBuilder\Core\DraftHistory\HistoryContext;
use SiteBuilder\Core\DraftHistory\LogicalAction;
use SiteBuilder\Core\Editing\TreeOperations;
use SiteBuilder\Core\Editing\TreeResolver;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\MutationResult;
use SiteBuilder\Core\Persistence\PageAccess;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\SiteParts\ContentDependencies;
use SiteBuilder\Core\SiteParts\SitePartCommands;
use stdClass;

/** Only pinned server sources can enter this narrow copy-admission path. */
final class CompositionCommands
{
    public function __construct(private PageAccess $access, private PageLocks $locks, private SourceResolver $sources, private CopyReferenceResolver $references, private DocumentCopies $copies, private DocumentValidator $validator, private CanonicalJson $json, private DesignOperations $design) {}

    public function review(Authenticatable $actor, int $pageId, string $leaseToken, int $expectedVersion, SourceSelection $selection, ?ReferenceMap $map = null, ?InsertPosition $position = null): ReferenceReport
    {
        return DB::transaction(function () use ($actor, $pageId, $leaseToken, $expectedVersion, $selection, $map, $position): ReferenceReport {
            $page = $this->page($actor, $pageId, $leaseToken, $expectedVersion);
            $old = $page->draft_content ?? new Document;
            $position ??= InsertPosition::end();
            $this->assertPosition($old, $position);
            $source = $this->sources->resolve($actor, $selection, true);

            $context = new CopyContext($page->id, $selection->locale);
            $report = $this->references->inspect($actor, $source, $context, $map);
            if ($report->blocking) {
                return $report;
            }
            try {
                $copy = $this->copies->instantiate($source, $context, $map ?? ReferenceMap::fromArray([]));
                $candidate = $this->design->admitComposition($old, $this->insertedNodes($old, $copy->document->nodes, $position), $this->renderContext($page, $selection));
                $this->validator->validate($candidate, context: $this->renderContext($page, $selection));
            } catch (InvalidArgumentException|ValidationException $exception) {
                return new ReferenceReport($report->occurrences, [...$report->diagnostics, ['severity' => 'error', 'code' => 'incompatible_design_source', 'path' => '/source', 'message' => $exception->getMessage()]], true);
            }

            return $report;
        }, 3);
    }

    /** Read-only projection through the same copy/design admission as insertion. */
    public function previewSource(Authenticatable $actor, int $pageId, SourceSelection $selection, ?ReferenceMap $map = null, ?InsertPosition $position = null): Document
    {
        $preview = $this->previewContext($actor, $pageId, $selection, $map, $position);
        $tree = app(TreeResolver::class);

        return new Document(array_map(fn (string $id) => $tree->locate($preview->document, $id)->node, $preview->rootIds), $preview->document->extensions);
    }

    /** Full admission context plus selected roots: preserve ancestor inheritance and child-only placement. */
    public function previewContext(Authenticatable $actor, int $pageId, SourceSelection $selection, ?ReferenceMap $map = null, ?InsertPosition $position = null): CompositionPreview
    {
        $page = Page::query()->findOrFail($pageId);
        $this->access->authorize($actor, 'preview', $page);
        $old = $page->draft_content ?? new Document;
        $position ??= InsertPosition::end();
        $this->assertPosition($old, $position);
        $source = $this->sources->resolve($actor, $selection);
        $context = new CopyContext($page->id, $selection->locale);
        $map ??= ReferenceMap::fromArray([]);
        $this->references->inspect($actor, $source, $context, $map)->assertApplicable();
        $copy = $this->copies->instantiate($source, $context, $map);
        $renderContext = $this->renderContext($page, $selection);
        $candidate = $this->design->admitComposition($old, $this->insertedNodes($old, $copy->document->nodes, $position), $renderContext);
        $this->validator->validate($candidate, context: $renderContext);

        return new CompositionPreview($candidate, array_map(fn ($node): string => $node->id, $copy->document->nodes));
    }

    public function insert(Authenticatable $actor, int $pageId, string $leaseToken, int $expectedVersion, SourceSelection $selection, InsertPosition $position, ?ReferenceMap $map = null, ?HistoryContext $history = null): CompositionResult
    {
        return $this->apply($actor, $pageId, $leaseToken, $expectedVersion, $selection, $map, $position, history: $history);
    }

    public function replaceDraftSections(Authenticatable $actor, int $pageId, string $leaseToken, int $expectedVersion, string $expectedDraftDigest, SourceSelection $selection, ?ReferenceMap $map = null, bool $confirmation = false, ?HistoryContext $history = null): CompositionResult
    {
        if (! $confirmation) {
            throw ValidationException::withMessages(['replace' => 'Explicit confirmation is required to replace current draft sections.']);
        }

        return $this->apply($actor, $pageId, $leaseToken, $expectedVersion, $selection, $map, null, $expectedDraftDigest, $history);
    }

    private function apply(Authenticatable $actor, int $pageId, string $token, int $version, SourceSelection $selection, ?ReferenceMap $map, ?InsertPosition $position, ?string $expectedDigest = null, ?HistoryContext $history = null): CompositionResult
    {
        return DB::transaction(function () use ($actor, $pageId, $token, $version, $selection, $map, $position, $expectedDigest, $history): CompositionResult {
            $page = $this->page($actor, $pageId, $token, $version);
            if ($history !== null) {
                app(DraftHistory::class)->inspectCurrent($history, $actor, $page, $token);
            }
            $before = $page->draft_content;
            $old = $before ?? new Document;
            if ($expectedDigest !== null && ! hash_equals($this->json->digest($page->draft_content), $expectedDigest)) {
                throw new WriteConflict('draft_digest_changed', $page->write_version);
            }
            if ($position !== null) {
                $this->assertPosition($old, $position);
            }
            $source = $this->sources->resolve($actor, $selection, true);
            $sourceScope = SectionSourceScope::project($source->document);
            $context = new CopyContext($page->id, $selection->locale);
            $map ??= ReferenceMap::fromArray([]);
            $report = $this->references->inspect($actor, $source, $context, $map);
            $report->assertApplicable();
            $copy = $this->copies->instantiate($source, $context, $map);
            $nodes = $position === null ? $copy->document->nodes : $this->insertedNodes($old, $copy->document->nodes, $position);
            $ids = array_map(fn ($node): string => $node->id, $copy->document->nodes);
            $renderContext = $this->renderContext($page, $selection);
            $candidate = $this->design->admitComposition($old, $nodes, $renderContext);
            app(CompositionResolver::class)->lockMutation($before, $candidate, $renderContext, $actor);
            app(SitePartCommands::class)->validateNewPins($actor, $before, $candidate);
            if ($history !== null) {
                app(DraftHistory::class)->assertCurrent($history, $actor, $page, $token);
            }
            $extensions = $candidate->extensions->toObject();
            $provenance = $extensions->{'site-builder.copy'} ?? (object) ['version' => 1, 'entries' => []];
            if (! $provenance instanceof stdClass || ($provenance->version ?? null) !== 1 || ! is_array($provenance->entries ?? null) || ! array_is_list($provenance->entries)) {
                throw ValidationException::withMessages(['copy' => 'An existing unrecognized copy-provenance extension must be preserved.']);
            }
            $sourceExtensions = $sourceScope['extensions']->toObject();
            if ($sourceScope['renderFeatures'] !== null) {
                $sourceExtensions->{'site-builder.render'} = $sourceScope['renderFeatures'];
            }
            $provenance->entries[] = (object) ['scope' => 'sections', 'sourceKind' => $source->kind, 'sourceId' => $source->id, 'sourceVersion' => $source->version, 'sourceDigest' => $source->digest, 'rootIds' => $ids, 'sourceDocumentExtensions' => $sourceExtensions, 'sourceCopyHistoryDigest' => $sourceScope['copyHistoryDigest'] ?? ($source->provenance['copyHistoryDigest'] ?? null), 'copiedAt' => now()->toAtomString()];
            $extensions->{'site-builder.copy'} = $provenance;
            $document = new Document($nodes, new JsonObject($extensions));
            // Incoming nodes are exact ID-only clones of this reloaded immutable
            // source plus verified typed mappings. No raw client Document is accepted.
            $diagnostics = $this->validator->validate($document, context: $renderContext);
            if (! $this->json->equals($page->draft_content, $document)) {
                $page->forceFill(['draft_content' => $document, 'write_version' => $version + 1])->save();
            }
            if ($history !== null) {
                app(DraftHistory::class)->recordAccepted($history, $actor, $page, $token, $before, $page->draft_content, new LogicalAction('insert', $position === null ? 'Replace sections' : 'Insert sections'));
            }
            app(ContentDependencies::class)->syncPage($page);
            $page = $page->fresh();

            return new CompositionResult(new MutationResult($page, $this->json->digest($page->draft_content), $diagnostics), $ids, $source, $report);
        }, 3);
    }

    private function renderContext(Page $page, SourceSelection $selection): RenderContext
    {
        return new RenderContext($selection->locale, 'preview', $page->id, $page->layout);
    }

    private function assertPosition(Document $document, InsertPosition $position): void
    {
        app(TreeOperations::class)->assertWritableSlot($document, $position->slot());
        $position->index($document);
    }

    private function insertedNodes(Document $document, array $nodes, InsertPosition $position): array
    {
        $tree = app(TreeResolver::class);
        $siblings = $tree->children($document, $position->slot());
        array_splice($siblings, $position->index($document), 0, $nodes);

        return $tree->withChildren($document, $position->slot(), $siblings)->nodes;
    }

    private function page(Authenticatable $actor, int $pageId, string $token, int $version): Page
    {
        $page = Page::query()->lockForUpdate()->findOrFail($pageId);
        $identity = $this->access->authorize($actor, 'update', $page);
        $this->locks->assertHeld($page, $identity, $token);
        if ($page->write_version !== $version) {
            throw new WriteConflict('stale_version', $page->write_version);
        }

        return $page;
    }
}
