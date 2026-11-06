<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Editing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\DraftHistory\DraftHistory;
use SiteBuilder\Core\DraftHistory\HistoryContext;
use SiteBuilder\Core\DraftHistory\LogicalAction;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageAccess;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Rendering\RenderContext;

/** Tree operations share ordinary page admission, dependency hooks and transactional history. */
final class TreeCommands
{
    public function __construct(private PageAccess $access, private PageLocks $locks, private PageCommands $pages, private TreeResolver $tree, private TreeOperations $operations, private LocaleCatalog $locales) {}

    public function add(Authenticatable $actor, int $pageId, string $token, int $version, string $type, TreeSlot $slot, TreePosition $position, ?HistoryContext $history = null): TreeMutationResult
    {
        return $this->mutate($actor, $pageId, $token, $version, $history, 'Add component', function (Document $old, RenderContext $context) use ($type, $slot, $position): array {
            $document = $this->operations->add($old, $type, $slot, $position, $context);

            return [$document, $this->newRoots($old, $document, $slot)];
        });
    }

    public function move(Authenticatable $actor, int $pageId, string $token, int $version, string $nodeId, TreeSlot $sourceSlot, TreeSlot $destinationSlot, TreePosition $position, ?HistoryContext $history = null): TreeMutationResult
    {
        return $this->mutate($actor, $pageId, $token, $version, $history, 'Move component', fn (Document $old, RenderContext $context): array => [$this->operations->move($old, $nodeId, $sourceSlot, $destinationSlot, $position, $context), [$nodeId]]);
    }

    public function duplicate(Authenticatable $actor, int $pageId, string $token, int $version, string $nodeId, TreeSlot $sourceSlot, ?HistoryContext $history = null): TreeMutationResult
    {
        return $this->mutate($actor, $pageId, $token, $version, $history, 'Duplicate component', function (Document $old, RenderContext $context) use ($nodeId, $sourceSlot): array {
            $document = $this->operations->duplicate($old, $nodeId, $sourceSlot, $context);

            return [$document, $this->newRoots($old, $document, $sourceSlot)];
        });
    }

    public function remove(Authenticatable $actor, int $pageId, string $token, int $version, string $nodeId, TreeSlot $sourceSlot, ?HistoryContext $history = null): TreeMutationResult
    {
        return $this->mutate($actor, $pageId, $token, $version, $history, 'Remove component', fn (Document $old, RenderContext $context): array => [$this->operations->remove($old, $nodeId, $sourceSlot, $context), $sourceSlot->parentId === null ? [] : [$sourceSlot->parentId]]);
    }

    public function visibility(Authenticatable $actor, int $pageId, string $token, int $version, string $nodeId, TreeSlot $sourceSlot, bool $visible, ?HistoryContext $history = null): TreeMutationResult
    {
        return $this->mutate($actor, $pageId, $token, $version, $history, $visible ? 'Show component' : 'Hide component', fn (Document $old, RenderContext $context): array => [$this->operations->visibility($old, $nodeId, $sourceSlot, $visible, $context), [$nodeId]]);
    }

    public function reorder(Authenticatable $actor, int $pageId, string $token, int $version, TreeSlot $slot, array $ids, ?HistoryContext $history = null): TreeMutationResult
    {
        return $this->mutate($actor, $pageId, $token, $version, $history, 'Reorder components', fn (Document $old, RenderContext $context): array => [$this->operations->reorder($old, $slot, $ids, $context), $ids]);
    }

    private function newRoots(Document $old, Document $new, TreeSlot $slot): array
    {
        $known = $this->tree->nodes($old);

        return array_values(array_map(fn (Node $node): string => $node->id, array_filter($this->tree->children($new, $slot), fn (Node $node): bool => ! isset($known[$node->id]))));
    }

    private function mutate(Authenticatable $actor, int $pageId, string $token, int $version, ?HistoryContext $history, string $label, callable $operation): TreeMutationResult
    {
        return DB::transaction(function () use ($actor, $pageId, $token, $version, $history, $label, $operation): TreeMutationResult {
            $page = Page::query()->lockForUpdate()->findOrFail($pageId);
            $identity = $this->access->authorize($actor, 'update', $page);
            $this->locks->assertHeld($page, $identity, $token);
            if ($page->write_version !== $version) {
                throw new WriteConflict('stale_version', $page->write_version);
            }
            if ($history !== null) {
                app(DraftHistory::class)->inspectCurrent($history, $actor, $page, $token);
            }
            [$document, $selectedIds] = $operation($page->draft_content ?? new Document, new RenderContext($this->locales->default(), 'preview', $page->id, $page->layout));
            $mutation = $this->pages->saveDraft($actor, $pageId, $token, $version, $document, $history, new LogicalAction('tree', $label));

            return new TreeMutationResult($mutation, $selectedIds);
        }, 3);
    }
}
