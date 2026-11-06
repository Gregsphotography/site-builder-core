<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

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
use SiteBuilder\Core\DraftHistory\DraftHistory;
use SiteBuilder\Core\DraftHistory\HistoryContext;
use SiteBuilder\Core\DraftHistory\HistoryRecord;
use SiteBuilder\Core\DraftHistory\HistorySession;
use SiteBuilder\Core\DraftHistory\LogicalAction;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Persistence\ActorIdentity;

/** Transient server-owned checkpoints. All lock orders begin with the owner page. */
final class SitePartDraftHistory
{
    public function __construct(private SitePartAccess $access, private SitePartLocks $locks, private DocumentCodec $codec, private CanonicalJson $json, private DocumentValidator $validator, private ComponentRegistry $registry) {}

    public function available(): bool
    {
        return Schema::hasTable('site_builder_draft_histories') && Schema::hasColumn('site_builder_draft_histories', 'site_part_id');
    }

    public function open(Authenticatable $actor, string $pageId, string $leaseToken, string $tabId, bool $reset = false): HistorySession
    {
        return DB::transaction(function () use ($actor, $pageId, $leaseToken, $tabId, $reset): HistorySession {
            $page = $this->page($actor, $pageId, $leaseToken);
            $this->requireAvailable();
            if (! Str::isUuid($tabId)) {
                throw ValidationException::withMessages(['history' => 'The editor tab identity must be a UUID.']);
            }
            $identity = ActorIdentity::from($actor);
            $row = HistoryRecord::query()->where('owner_kind', 'site-part')->where('owner_id', (string) $pageId)->where('tab_id', $tabId)->lockForUpdate()->first();
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
            $checkpoints = [$this->checkpoint($page->draft_payload)];
            if ($this->bytes($checkpoints) > $this->maxBytes()) {
                throw ValidationException::withMessages(['history' => 'The current draft exceeds the configured history budget. Increase the bounded budget or edit without document history.']);
            }
            $row = new HistoryRecord;
            $row->forceFill([
                'id' => (string) Str::uuid(), 'owner_kind' => 'site-part', 'owner_id' => (string) $pageId, 'page_id' => null, 'site_part_id' => $pageId, 'tab_id' => $tabId,
                'actor_id' => $identity->id, 'actor_type' => $identity->type, 'lease_hash' => hash('sha256', $leaseToken), 'epoch' => (string) Str::uuid(),
                'sequence' => 0, 'cursor' => 0, 'accepted_version' => $page->write_version, 'accepted_digest' => $this->json->digest($page->draft_payload),
                'checkpoints' => $checkpoints, 'actions' => [], 'group_state' => null, 'evicted' => 0, 'expires_at' => $this->expiry(),
            ])->save();

            app(ContentDependencies::class)->syncHistory($row);

            return $this->session($row);
        }, 3);
    }

    /** Status is a read of the latest acknowledgement; it cannot apply a stale cursor. */
    public function status(Authenticatable $actor, string $pageId, string $leaseToken, HistoryContext $context): HistorySession
    {
        return DB::transaction(function () use ($actor, $pageId, $leaseToken, $context): HistorySession {
            $page = $this->page($actor, $pageId, $leaseToken);
            $row = $this->row($actor, $page, $leaseToken, $context, false);
            $this->assertPage($row, $page);

            return $this->session($row);
        }, 3);
    }

    public function closeGroup(Authenticatable $actor, string $pageId, string $leaseToken, HistoryContext $context): HistorySession
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
    public function assertCurrent(HistoryContext $context, Authenticatable $actor, SitePart $lockedPage, string $leaseToken): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('History preflight requires the owner write transaction.');
        }
        $identity = $this->access->authorize($actor, 'update', $lockedPage);
        $this->locks->assertHeld($lockedPage, $identity, $leaseToken);
        $this->assertPage($this->row($actor, $lockedPage, $leaseToken, $context), $lockedPage);
    }

    /**
     * Internal writer hook: call after a guarded save, inside its page-locked transaction.
     * Before/after are authoritative server values, never request-supplied restoration targets.
     */
    public function recordAccepted(HistoryContext $context, Authenticatable $actor, SitePart $lockedPage, string $leaseToken, ?SitePartPayload $before, ?SitePartPayload $after, LogicalAction $action): void
    {
        if (DB::transactionLevel() < 1 || ! $lockedPage->exists) {
            throw new LogicException('Accepted history must be recorded inside the owner write transaction.');
        }
        $identity = $this->access->authorize($actor, 'update', $lockedPage);
        $this->locks->assertHeld($lockedPage, $identity, $leaseToken);
        $row = $this->row($actor, $lockedPage, $leaseToken, $context);
        if (! hash_equals($row->accepted_digest, $this->json->digest($before)) || $lockedPage->write_version < $row->accepted_version || $lockedPage->write_version > $row->accepted_version + 1 || ! $this->json->equals($lockedPage->draft_payload, $after)) {
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

    public function undo(Authenticatable $actor, string $pageId, string $leaseToken, int $expectedVersion, HistoryContext $context): SitePartHistoryMutationResult
    {
        return $this->move($actor, $pageId, $leaseToken, $expectedVersion, $context, -1);
    }

    public function redo(Authenticatable $actor, string $pageId, string $leaseToken, int $expectedVersion, HistoryContext $context): SitePartHistoryMutationResult
    {
        return $this->move($actor, $pageId, $leaseToken, $expectedVersion, $context, 1);
    }

    /** Called only from an authorized owner mutation transaction, including delete. */
    public function invalidate(string $pageId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('History boundaries require the owner transaction.');
        }
        if ($this->available()) {
            foreach (HistoryRecord::query()->where('owner_kind', 'site-part')->where('owner_id', (string) $pageId)->lockForUpdate()->get() as $row) {
                app(ContentDependencies::class)->removeOwner('history', $row->id);
                $row->delete();
            }
        }
    }

    public function prune(): int
    {
        return app(DraftHistory::class)->prune();
    }

    private function move(Authenticatable $actor, string $pageId, string $token, int $version, HistoryContext $context, int $direction): SitePartHistoryMutationResult
    {
        $part = SitePart::query()->findOrFail($pageId);
        $identity = $this->access->authorize($actor, 'update', $part);
        $this->locks->assertHeld($part, $identity, $token);
        if ($part->write_version !== $version) {
            throw new WriteConflict('stale_version', $part->write_version);
        }
        $preview = HistoryRecord::query()->find($context->id);
        if ($preview === null || $preview->owner_kind !== 'site-part' || $preview->owner_id !== $pageId || ! $preview->expires_at->isFuture() || $preview->sequence !== $context->sequence || $preview->cursor !== $context->cursor || ! $this->matches($preview, $identity, $token) || ! hash_equals($preview->epoch, $context->epoch)) {
            throw new WriteConflict('history_unavailable', $part->write_version);
        }
        $this->assertPage($preview, $part);
        $cursor = $context->cursor + $direction;
        if (! isset($preview->checkpoints[$cursor])) {
            throw new WriteConflict('history_unavailable', $part->write_version);
        }
        $candidate = SitePartPayload::decode($preview->checkpoints[$cursor]['document']);
        $session = null;
        $mutation = app(SitePartCommands::class)->mutate($actor, $pageId, $token, $version, 'update', function (SitePart $part) use ($actor, $token, $context, $direction, $version, &$session): void {
            $row = $this->row($actor, $part, $token, $context);
            $this->assertPage($row, $part);
            $cursor = $row->cursor + $direction;
            if (! isset($row->checkpoints[$cursor])) {
                throw new WriteConflict('history_unavailable', $part->write_version);
            }
            $checkpoint = $row->checkpoints[$cursor];
            $target = SitePartPayload::decode($checkpoint['document']);
            if ($target->kind !== $part->kind || ! hash_equals($checkpoint['digest'], $this->json->digest($target))) {
                throw new WriteConflict('history_changed', $part->write_version);
            }
            $this->validator->validate($target->validationDocument(), context: app(SitePartCommands::class)->context());
            $part->forceFill(['draft_payload' => $target, 'write_version' => $version + 1]);
            $row->forceFill(['cursor' => $cursor, 'sequence' => $row->sequence + 1, 'accepted_version' => $version + 1, 'accepted_digest' => $checkpoint['digest'], 'group_state' => null, 'expires_at' => $this->expiry()])->save();
            app(ContentDependencies::class)->syncHistory($row);
            $session = $this->session($row);
        }, $candidate);

        return new SitePartHistoryMutationResult($mutation, $session);
    }

    private function page(Authenticatable $actor, string $pageId, string $token): SitePart
    {
        $initial = SitePart::query()->findOrFail($pageId);
        $initialIdentity = $this->access->authorize($actor, 'update', $initial);
        $this->locks->assertHeld($initial, $initialIdentity, $token);
        app(CompositionResolver::class)->lockReferences($initial->draft_payload->validationDocument(), app(SitePartCommands::class)->context(), $actor, $pageId);
        $page = SitePart::query()->lockForUpdate()->findOrFail($pageId);
        $identity = $this->access->authorize($actor, 'update', $page);
        $this->locks->assertHeld($page, $identity, $token);

        return $page;
    }

    private function row(Authenticatable $actor, SitePart $page, string $token, HistoryContext $context, bool $exact = true): HistoryRecord
    {
        $this->requireAvailable();
        $row = HistoryRecord::query()->lockForUpdate()->find($context->id);
        if ($row === null || ! $row->expires_at->isFuture()) {
            throw new WriteConflict('history_unavailable', $page->write_version);
        }
        if ($row->owner_kind !== 'site-part' || $row->owner_id !== (string) $page->id || $row->site_part_id !== $page->id || ! $this->matches($row, ActorIdentity::from($actor), $token) || ! hash_equals($row->epoch, $context->epoch) || ($exact && ($row->sequence !== $context->sequence || $row->cursor !== $context->cursor))) {
            throw new WriteConflict('history_changed', $page->write_version);
        }

        return $row;
    }

    private function matches(HistoryRecord $row, ActorIdentity $identity, string $token): bool
    {
        return $row->actor_id === $identity->id && $row->actor_type === $identity->type && hash_equals($row->lease_hash, hash('sha256', $token));
    }

    private function assertPage(HistoryRecord $row, SitePart $page): void
    {
        if ($row->accepted_version !== $page->write_version || ! hash_equals($row->accepted_digest, $this->json->digest($page->draft_payload)) || ! hash_equals($this->checkpointDigest($row->checkpoints[$row->cursor] ?? null), $row->accepted_digest)) {
            throw new WriteConflict('history_page_changed', $page->write_version);
        }
    }

    private function session(HistoryRecord $row): HistorySession
    {
        $actions = $row->actions;

        return new HistorySession(new HistoryContext($row->id, $row->epoch, $row->sequence, $row->cursor), ['available' => true, 'undoCount' => $row->cursor, 'redoCount' => count($actions) - $row->cursor, 'undoLabel' => $row->cursor > 0 ? $actions[$row->cursor - 1]['label'] : null, 'redoLabel' => $row->cursor < count($actions) ? $actions[$row->cursor]['label'] : null, 'evicted' => $row->evicted]);
    }

    private function checkpoint(?SitePartPayload $document): array
    {
        return ['document' => $document === null ? null : json_encode($document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'digest' => $this->json->digest($document)];
    }

    private function checkpointDigest(?array $checkpoint): string
    {
        return is_string($checkpoint['digest'] ?? null) ? $checkpoint['digest'] : '';
    }

    private function typingGroup(LogicalAction $action, ?SitePartPayload $before, ?SitePartPayload $after): ?string
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
        $old = $find($before?->document?->nodes ?? []);
        $node = $find($after?->document?->nodes ?? []);
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
        if (! $this->json->equals($strip($before->validationDocument()), $strip($after->validationDocument()))) {
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
