<?php

declare(strict_types=1);

namespace SiteBuilder\Core\DraftHistory;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Composition\CompositionResolver;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\ActorIdentity;
use SiteBuilder\Core\Persistence\MutationResult;
use SiteBuilder\Core\Persistence\PageAccess;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\SiteParts\ContentDependencies;

/** Transient server-owned checkpoints. All lock orders begin with the owner page. */
final class DraftHistory
{
    public function __construct(private PageAccess $access, private PageLocks $locks, private DocumentCodec $codec, private CanonicalJson $json, private DocumentValidator $validator, private ComponentRegistry $registry) {}

    public function available(): bool
    {
        return Schema::hasTable('site_builder_draft_histories');
    }

    public function open(Authenticatable $actor, int $pageId, string $leaseToken, string $tabId, bool $reset = false): HistorySession
    {
        return DB::transaction(function () use ($actor, $pageId, $leaseToken, $tabId, $reset): HistorySession {
            $page = $this->page($actor, $pageId, $leaseToken);
            $this->requireAvailable();
            if (! Str::isUuid($tabId)) {
                throw ValidationException::withMessages(['history' => 'The editor tab identity must be a UUID.']);
            }
            $identity = ActorIdentity::from($actor);
            app(CompositionResolver::class)->lockMutation($page->draft_content, null, new RenderContext(config('app.locale', 'en'), 'preview', $page->id, $page->layout), $actor);
            $row = HistoryRecord::query()->where('owner_kind', 'page')->where('owner_id', (string) $pageId)->where('tab_id', $tabId)->lockForUpdate()->first();
            $same = $row !== null && $this->matches($row, $identity, $leaseToken) && $row->expires_at->isFuture();
            if ($same && ! $reset) {
                $this->assertPage($row, $page);

                return $this->session($row);
            }
            // A current authorized lease may replace an obsolete epoch, never read it.
            if ($row !== null) {
                app(ContentDependencies::class)->removeOwner('history', $row->id);
                $row->delete();
            }
            $checkpoints = [$this->checkpoint($page->draft_content)];
            if ($this->bytes($checkpoints) > $this->maxBytes()) {
                throw ValidationException::withMessages(['history' => 'The current draft exceeds the configured history budget. Increase the bounded budget or edit without document history.']);
            }
            $row = new HistoryRecord;
            $row->forceFill([
                'id' => (string) Str::uuid(), 'owner_kind' => 'page', 'owner_id' => (string) $pageId, 'page_id' => $pageId, 'tab_id' => $tabId,
                'actor_id' => $identity->id, 'actor_type' => $identity->type, 'lease_hash' => hash('sha256', $leaseToken), 'epoch' => (string) Str::uuid(),
                'sequence' => 0, 'cursor' => 0, 'accepted_version' => $page->write_version, 'accepted_digest' => $this->json->digest($page->draft_content),
                'checkpoints' => $checkpoints, 'actions' => [], 'group_state' => null, 'evicted' => 0, 'expires_at' => $this->expiry(),
            ])->save();

            app(ContentDependencies::class)->syncHistory($row);

            return $this->session($row);
        }, 3);
    }

    /** Status is a read of the latest acknowledgement; it cannot apply a stale cursor. */
    public function status(Authenticatable $actor, int $pageId, string $leaseToken, HistoryContext $context): HistorySession
    {
        return DB::transaction(function () use ($actor, $pageId, $leaseToken, $context): HistorySession {
            $page = $this->page($actor, $pageId, $leaseToken);
            $row = $this->row($actor, $page, $leaseToken, $context, false);
            $this->assertPage($row, $page);

            return $this->session($row);
        }, 3);
    }

    public function closeGroup(Authenticatable $actor, int $pageId, string $leaseToken, HistoryContext $context): HistorySession
    {
        return DB::transaction(function () use ($actor, $pageId, $leaseToken, $context): HistorySession {
            $page = $this->page($actor, $pageId, $leaseToken);
            $row = $this->row($actor, $page, $leaseToken, $context);
            $this->assertPage($row, $page);
            if ($row->group_state !== null) {
                $row->forceFill(['group_state' => null, 'sequence' => $row->sequence + 1, 'expires_at' => $this->expiry()])->save();
            }

            return $this->session($row);
        }, 3);
    }

    /** Internal preflight under the page lock, before host validation/copy hooks. */
    public function assertCurrent(HistoryContext $context, Authenticatable $actor, Page $lockedPage, string $leaseToken): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('History preflight requires the owner write transaction.');
        }
        $identity = $this->access->authorize($actor, 'update', $lockedPage);
        $this->locks->assertHeld($lockedPage, $identity, $leaseToken);
        $this->assertPage($this->row($actor, $lockedPage, $leaseToken, $context), $lockedPage);
    }

    /** Read-only early guard before a prospective closure is assembled; writers still require assertCurrent afterwards. */
    public function inspectCurrent(HistoryContext $context, Authenticatable $actor, Page $lockedPage, string $leaseToken): void
    {
        $identity = $this->access->authorize($actor, 'update', $lockedPage);
        $this->locks->assertHeld($lockedPage, $identity, $leaseToken);
        $this->assertPage($this->row($actor, $lockedPage, $leaseToken, $context, true, false), $lockedPage);
    }

    /**
     * Internal writer hook: call after a guarded save, inside its page-locked transaction.
     * Before/after are authoritative server values, never request-supplied restoration targets.
     */
    public function recordAccepted(HistoryContext $context, Authenticatable $actor, Page $lockedPage, string $leaseToken, ?Document $before, ?Document $after, LogicalAction $action): void
    {
        if (DB::transactionLevel() < 1 || ! $lockedPage->exists) {
            throw new LogicException('Accepted history must be recorded inside the owner write transaction.');
        }
        $identity = $this->access->authorize($actor, 'update', $lockedPage);
        $this->locks->assertHeld($lockedPage, $identity, $leaseToken);
        $row = $this->row($actor, $lockedPage, $leaseToken, $context);
        if (! hash_equals($row->accepted_digest, $this->json->digest($before)) || $lockedPage->write_version < $row->accepted_version || $lockedPage->write_version > $row->accepted_version + 1 || ! $this->json->equals($lockedPage->draft_content, $after)) {
            throw new WriteConflict('history_page_changed', $lockedPage->write_version);
        }
        $changed = ! $this->json->equals($before, $after);
        if ($changed && $lockedPage->write_version !== $row->accepted_version + 1) {
            throw new LogicException('A changed history checkpoint requires one accepted owner write.');
        }
        $versionChanged = $lockedPage->write_version !== $row->accepted_version;
        $checkpoints = $row->checkpoints;
        if (! hash_equals($this->checkpointDigest($checkpoints[$row->cursor] ?? null), $this->json->digest($before))) {
            throw new WriteConflict('history_changed', $lockedPage->write_version);
        }
        $actions = $row->actions;
        $groupState = $row->group_state;
        if ($changed) {
            $atHead = $row->cursor === count($actions);
            $checkpoints = array_slice($checkpoints, 0, $row->cursor + 1);
            $actions = array_slice($actions, 0, $row->cursor);
            $metadata = ['kind' => $action->kind, 'label' => $action->label];
            $group = $this->typingGroup($action, $before, $after);
            $now = now()->getTimestamp();
            $coalesce = $atHead && $group !== null && $groupState !== null && hash_equals($groupState['key'], $group) && $now - $groupState['last'] <= 3 && $now - $groupState['started'] <= 15 && count($actions) > 0;
            if ($coalesce) {
                $checkpoints[count($checkpoints) - 1] = $this->checkpoint($after);
                $actions[count($actions) - 1] = $metadata;
                $groupState['last'] = $now;
            } else {
                $checkpoints[] = $this->checkpoint($after);
                $actions[] = $metadata;
                $groupState = $group === null ? null : ['key' => $group, 'started' => $now, 'last' => $now];
            }
            // Returning to the group's original state is a successful edit, not a phantom undo.
            if (count($checkpoints) > 1 && $this->checkpointDigest($checkpoints[count($checkpoints) - 2]) === $this->checkpointDigest(end($checkpoints))) {
                array_pop($checkpoints);
                array_pop($actions);
                $groupState = null;
            }
            $evicted = $row->evicted;
            while (count($actions) > $this->maxTransitions() || $this->bytes($checkpoints) > $this->maxBytes()) {
                if (count($checkpoints) === 1) {
                    throw ValidationException::withMessages(['history' => 'The accepted draft exceeds the configured history budget. The page and history were not changed.']);
                }
                array_shift($checkpoints);
                array_shift($actions);
                $evicted++;
            }
            $row->forceFill(['checkpoints' => $checkpoints, 'actions' => $actions, 'cursor' => count($actions), 'evicted' => $evicted]);
        } elseif ($action->kind !== 'typing') {
            $groupState = null;
        }
        if ($changed || $versionChanged || $row->group_state !== $groupState) {
            $row->sequence++;
        }
        $row->forceFill(['accepted_version' => $lockedPage->write_version, 'accepted_digest' => $this->json->digest($after), 'group_state' => $groupState, 'expires_at' => $this->expiry()])->save();
        app(ContentDependencies::class)->syncHistory($row);
    }

    public function undo(Authenticatable $actor, int $pageId, string $leaseToken, int $expectedVersion, HistoryContext $context): HistoryMutationResult
    {
        return $this->move($actor, $pageId, $leaseToken, $expectedVersion, $context, -1);
    }

    public function redo(Authenticatable $actor, int $pageId, string $leaseToken, int $expectedVersion, HistoryContext $context): HistoryMutationResult
    {
        return $this->move($actor, $pageId, $leaseToken, $expectedVersion, $context, 1);
    }

    /** Called only from an authorized owner mutation transaction, including delete. */
    public function invalidatePage(int $pageId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('History boundaries require the owner transaction.');
        }
        if ($this->available()) {
            foreach (HistoryRecord::query()->where('owner_kind', 'page')->where('owner_id', (string) $pageId)->lockForUpdate()->get() as $row) {
                app(ContentDependencies::class)->removeOwner('history', $row->id);
                $row->delete();
            }
        }
    }

    public function prune(): int
    {
        if (! $this->available()) {
            return 0;
        }

        return DB::transaction(function (): int {
            $rows = HistoryRecord::query()->where('expires_at', '<=', now())->lockForUpdate()->get();
            foreach ($rows as $row) {
                app(ContentDependencies::class)->removeOwner('history', $row->id);
                $row->delete();
            }

            return $rows->count();
        }, 3);
    }

    private function move(Authenticatable $actor, int $pageId, string $token, int $version, HistoryContext $context, int $direction): HistoryMutationResult
    {
        return DB::transaction(function () use ($actor, $pageId, $token, $version, $context, $direction): HistoryMutationResult {
            $page = $this->page($actor, $pageId, $token);
            if ($page->write_version !== $version) {
                throw new WriteConflict('stale_version', $page->write_version);
            }
            $preview = $this->row($actor, $page, $token, $context, true, false);
            $this->assertPage($preview, $page);
            $previewCursor = $preview->cursor + $direction;
            if (! isset($preview->checkpoints[$previewCursor])) {
                throw new WriteConflict('history_unavailable', $page->write_version);
            }
            $previewValue = $preview->checkpoints[$previewCursor]['document'];
            $prospective = $previewValue === null ? null : $this->codec->decode($previewValue);
            app(CompositionResolver::class)->lockMutation($page->draft_content, $prospective, new RenderContext(config('app.locale', 'en'), 'preview', $page->id, $page->layout), $actor);
            $row = $this->row($actor, $page, $token, $context);
            $this->assertPage($row, $page);
            $cursor = $row->cursor + $direction;
            if ($cursor < 0 || $cursor >= count($row->checkpoints)) {
                throw new WriteConflict('history_unavailable', $page->write_version);
            }
            // The ONLY restoration target is this adjacent, server-retained checkpoint.
            $checkpoint = $row->checkpoints[$cursor];
            $target = $checkpoint['document'] === null ? null : $this->codec->decode($checkpoint['document']);
            if (! hash_equals($checkpoint['digest'], $this->json->digest($target))) {
                throw new WriteConflict('history_changed', $page->write_version);
            }
            $diagnostics = $target === null ? [] : $this->validator->validate($target, context: new RenderContext(config('app.locale', 'en'), 'preview', $page->id, $page->layout));
            if (! $this->json->equals($page->draft_content, $target)) {
                $page->forceFill(['draft_content' => $target, 'write_version' => $version + 1])->save();
            }
            $row->forceFill(['cursor' => $cursor, 'sequence' => $row->sequence + 1, 'accepted_version' => $page->write_version, 'accepted_digest' => $checkpoint['digest'], 'group_state' => null, 'expires_at' => $this->expiry()])->save();
            app(ContentDependencies::class)->syncHistory($row);
            app(ContentDependencies::class)->syncPage($page);
            $page = $page->fresh();

            return new HistoryMutationResult(new MutationResult($page, $this->json->digest($page->draft_content), $diagnostics), $this->session($row));
        }, 3);
    }

    private function page(Authenticatable $actor, int $pageId, string $token): Page
    {
        $page = Page::query()->lockForUpdate()->findOrFail($pageId);
        $identity = $this->access->authorize($actor, 'update', $page);
        $this->locks->assertHeld($page, $identity, $token);

        return $page;
    }

    private function row(Authenticatable $actor, Page $page, string $token, HistoryContext $context, bool $exact = true, bool $lock = true): HistoryRecord
    {
        $this->requireAvailable();
        $row = HistoryRecord::query()->when($lock, fn ($query) => $query->lockForUpdate())->find($context->id);
        if ($row === null || ! $row->expires_at->isFuture()) {
            throw new WriteConflict('history_unavailable', $page->write_version);
        }
        if ($row->owner_kind !== 'page' || $row->owner_id !== (string) $page->id || $row->page_id !== $page->id || ! $this->matches($row, ActorIdentity::from($actor), $token) || ! hash_equals($row->epoch, $context->epoch) || ($exact && ($row->sequence !== $context->sequence || $row->cursor !== $context->cursor))) {
            throw new WriteConflict('history_changed', $page->write_version);
        }

        return $row;
    }

    private function matches(HistoryRecord $row, ActorIdentity $identity, string $token): bool
    {
        return $row->actor_id === $identity->id && $row->actor_type === $identity->type && hash_equals($row->lease_hash, hash('sha256', $token));
    }

    private function assertPage(HistoryRecord $row, Page $page): void
    {
        if ($row->accepted_version !== $page->write_version || ! hash_equals($row->accepted_digest, $this->json->digest($page->draft_content)) || ! hash_equals($this->checkpointDigest($row->checkpoints[$row->cursor] ?? null), $row->accepted_digest)) {
            throw new WriteConflict('history_page_changed', $page->write_version);
        }
    }

    private function session(HistoryRecord $row): HistorySession
    {
        $actions = $row->actions;

        return new HistorySession(new HistoryContext($row->id, $row->epoch, $row->sequence, $row->cursor), ['available' => true, 'undoCount' => $row->cursor, 'redoCount' => count($actions) - $row->cursor, 'undoLabel' => $row->cursor > 0 ? $actions[$row->cursor - 1]['label'] : null, 'redoLabel' => $row->cursor < count($actions) ? $actions[$row->cursor]['label'] : null, 'evicted' => $row->evicted]);
    }

    private function checkpoint(?Document $document): array
    {
        return ['document' => $document === null ? null : $this->codec->encode($document), 'digest' => $this->json->digest($document)];
    }

    private function checkpointDigest(?array $checkpoint): string
    {
        return is_string($checkpoint['digest'] ?? null) ? $checkpoint['digest'] : '';
    }

    private function typingGroup(LogicalAction $action, ?Document $before, ?Document $after): ?string
    {
        if ($action->kind !== 'typing' || $action->group === null || $action->nodeId === null || $action->locale === null || $action->properties === []) {
            return null;
        }
        $find = function (array $nodes) use (&$find, $action) {
            foreach ($nodes as $node) {
                if ($node->id === $action->nodeId) {
                    return $node;
                }
                if (($found = $find($node->children)) !== null) {
                    return $found;
                }
            }

            return null;
        };
        $old = $find($before?->nodes ?? []);
        $node = $find($after?->nodes ?? []);
        $definition = $node === null ? null : $this->registry->resolve($node->type);
        if ($old === null || $node === null || $old->type !== $node->type || $old->version !== $node->version || $definition === null || $definition->version() !== $node->version) {
            return null;
        }
        foreach ($action->properties as $key) {
            if (! isset($definition->properties()[$key]) || ! in_array($definition->properties()[$key]->kind, ['text', 'html', 'url'], true)) {
                return null;
            }
        }

        // A caller cannot disguise a structural/design/repeater/other-locale change
        // as typing simply by supplying plausible grouping metadata.
        $strip = function (Document $document) use ($action): object {
            $data = json_decode(json_encode($document, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
            $walk = function (array $nodes) use (&$walk, $action): void {
                foreach ($nodes as $node) {
                    if ($node->id === $action->nodeId) {
                        foreach ($action->properties as $key) {
                            unset($node->props->{$key});
                            if (isset($node->translations->{$action->locale})) {
                                unset($node->translations->{$action->locale}->{$key});
                            }
                        }
                        foreach ($node->translations as $locale => $values) {
                            if ((array) $values === []) {
                                unset($node->translations->{$locale});
                            }
                        }
                    }
                    $walk($node->children);
                }
            };
            $walk($data->nodes);

            return $data;
        };
        if (! $this->json->equals($strip($before), $strip($after))) {
            return null;
        }

        return $this->json->digest($action->toArray());
    }

    private function bytes(array $checkpoints): int
    {
        // Include JSON quoting/metadata overhead as well as the actual snapshot bytes.
        return strlen(json_encode($checkpoints, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function maxTransitions(): int
    {
        return max(1, min(100, (int) config('site-builder.history.transitions', 20)));
    }

    private function maxBytes(): int
    {
        return max(1024, min(32 * 1024 * 1024, (int) config('site-builder.history.max_bytes', 8 * 1024 * 1024)));
    }

    private function expiry(): Carbon
    {
        return now()->addSeconds(max(1, min(8 * 60 * 60, (int) config('site-builder.history.ttl_seconds', 2 * 60 * 60))));
    }

    private function requireAvailable(): void
    {
        if (! $this->available()) {
            throw new WriteConflict('history_unavailable');
        }
    }
}
