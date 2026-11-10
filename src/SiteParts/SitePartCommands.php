<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Composition\CompositionData;
use SiteBuilder\Core\Composition\CompositionResolver;
use SiteBuilder\Core\Composition\OverlaySettings;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Design\DesignData;
use SiteBuilder\Core\Design\DesignResolver;
use SiteBuilder\Core\DraftHistory\HistoryContext;
use SiteBuilder\Core\DraftHistory\LogicalAction;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Persistence\ActorIdentity;
use SiteBuilder\Core\Persistence\AuthoringConstraints;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;

final class SitePartCommands
{
    public function __construct(private SitePartAccess $access, private SitePartLocks $locks, private DocumentValidator $validator, private CanonicalJson $json, private CompositionResolver $composition) {}

    public function create(Authenticatable $actor, string $kind, array $metadata, ?SitePartPayload $payload = null): SitePartMutationResult
    {
        $identity = $this->access->authorize($actor, 'create', [SitePart::class, $kind]);
        $this->metadata($metadata, true);
        if ($payload === null) {
            if ($kind === 'design') {
                throw ValidationException::withMessages(['payload' => 'Choose an explicit trusted theme before creating a site-design resource.']);
            }
            $document = $kind === 'overlay' ? OverlaySettings::with(new Document, app(LocaleCatalog::class)->default(), $metadata['name']) : new Document;
            $payload = SitePartPayload::document($kind, $document);
        }
        if ($payload->kind !== $kind) {
            throw ValidationException::withMessages(['kind' => 'Site-part kinds and typed payloads must agree.']);
        }

        return DB::transaction(function () use ($actor, $kind, $metadata, $payload, $identity): SitePartMutationResult {
            $document = $payload->validationDocument();
            $this->preserve(null, $document);
            $this->composition->lockReferences($document, $this->context(), $actor);
            $this->validateNewPins($actor, null, $document);
            $this->validator->validate($document, context: $this->context());
            app(AuthoringConstraints::class)->check($actor, $document, $this->context());
            $part = new SitePart;
            $part->forceFill(['id' => (string) Str::uuid(), 'kind' => $kind, ...$metadata, 'draft_payload' => $payload, 'write_version' => 0, 'created_by' => $identity->id, 'created_by_type' => $identity->type, 'updated_by' => $identity->id, 'updated_by_type' => $identity->type])->save();
            app(ContentDependencies::class)->syncPart($part);

            return $this->result($part);
        }, 3);
    }

    public function saveDraft(Authenticatable $actor, string $partId, string $lease, int $version, SitePartPayload $payload, ?HistoryContext $history = null, ?LogicalAction $action = null): SitePartMutationResult
    {
        return $this->mutate($actor, $partId, $lease, $version, 'update', function (SitePart $part) use ($actor, $payload): void {
            if ($part->kind !== $payload->kind) {
                throw ValidationException::withMessages(['kind' => 'A resource kind cannot change after creation.']);
            }
            $before = $part->draft_payload->validationDocument();
            $document = $payload->validationDocument();
            $this->preserve($before, $document);
            app(DesignResolver::class)->assertUnavailablePreserved($before, $document, $this->context());
            $this->validateNewPins($actor, $before, $document);
            $this->validator->validate($document, context: $this->context());
            if (! $this->json->equals($part->draft_payload, $payload)) {
                $part->draft_payload = $payload;
            }
        }, $payload, $history, $action ?? new LogicalAction('edit', 'Edit site part'));
    }

    public function saveMetadata(Authenticatable $actor, string $partId, string $lease, int $version, array $metadata, ?HistoryContext $history = null): SitePartMutationResult
    {
        return $this->mutate($actor, $partId, $lease, $version, 'update', function (SitePart $part) use ($metadata): void {
            $this->metadata($metadata);
            $part->forceFill($metadata);
        }, history: $history, action: new LogicalAction('metadata', 'Update site-part settings'), validateClosure: false);
    }

    public function publish(Authenticatable $actor, string $partId, string $lease, int $version, string $intendedDigest, ?string $label = null): SitePartMutationResult
    {
        return $this->mutate($actor, $partId, $lease, $version, 'publish', function (SitePart $part) use ($actor, $intendedDigest, $label): void {
            if (! hash_equals($this->json->digest($part->draft_payload), $intendedDigest)) {
                throw new WriteConflict('draft_digest_changed', $part->write_version);
            }
            Validator::make(['label' => $label], ['label' => ['nullable', 'string', 'max:255']])->validate();
            $document = $part->draft_payload->validationDocument();
            $this->composition->resolve($document, $this->context(), $actor, $part->id);
            $this->validator->validate($document, true, app(LocaleCatalog::class)->all(), $this->context());
            $identity = ActorIdentity::from($actor);
            $revision = new SitePartRevision;
            $revision->forceFill(['id' => (string) Str::uuid(), 'resource_id' => $part->id, 'kind' => $part->kind, 'sequence' => ((int) $part->revisions()->max('sequence')) + 1, 'payload' => $part->draft_payload, 'digest' => $this->json->digest($part->draft_payload), 'published_from_version' => $part->write_version, 'created_by' => $identity->id, 'created_by_type' => $identity->type, 'label' => $label, 'published_at' => now()])->save();
            $part->current_revision_id = $revision->id;
            $part->write_version++;
        }, boundary: true);
    }

    public function unpublish(Authenticatable $actor, string $partId, string $lease, int $version): SitePartMutationResult
    {
        return $this->mutate($actor, $partId, $lease, $version, 'publish', function (SitePart $part): void {
            $part->current_revision_id = null;
            $part->write_version++;
        }, boundary: true, validateClosure: false);
    }

    public function restore(Authenticatable $actor, string $partId, string $lease, int $version, string $revisionId): SitePartMutationResult
    {
        $initial = SitePart::query()->findOrFail($partId);
        $identity = $this->access->authorize($actor, 'restore', $initial);
        $this->locks->assertHeld($initial, $identity, $lease);
        if ($initial->write_version !== $version) {
            throw new WriteConflict('stale_version', $initial->write_version);
        }
        $candidate = $initial->revisions()->findOrFail($revisionId)->payload;

        return $this->mutate($actor, $partId, $lease, $version, 'restore', function (SitePart $part) use ($revisionId): void {
            $revision = $part->revisions()->findOrFail($revisionId);
            if ($revision->withdrawn_at !== null || ! hash_equals($revision->digest, $this->json->digest($revision->payload))) {
                throw ValidationException::withMessages(['revision' => 'The immutable revision is withdrawn or failed its digest check.']);
            }
            $this->validator->validate($revision->payload->validationDocument(), context: $this->context());
            $part->draft_payload = $revision->payload;
            $part->write_version++;
        }, $candidate, boundary: true);
    }

    public function archive(Authenticatable $actor, string $partId, string $lease, int $version): SitePartMutationResult
    {
        return $this->mutate($actor, $partId, $lease, $version, 'delete', function (SitePart $part): void {
            $part->archived_at = now();
        }, boundary: true, validateClosure: false);
    }

    public function withdraw(Authenticatable $actor, string $partId, string $lease, int $version, string $revisionId, string $reason): SitePartMutationResult
    {
        return $this->mutate($actor, $partId, $lease, $version, 'withdraw', function (SitePart $part) use ($actor, $revisionId, $reason): void {
            Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();
            $identity = ActorIdentity::from($actor);
            $part->revisions()->findOrFail($revisionId)->forceFill(['withdrawn_at' => now(), 'withdrawal_reason' => $reason, 'withdrawn_by' => $identity->id, 'withdrawn_by_type' => $identity->type])->save();
            $part->write_version++;
        }, boundary: true, validateClosure: false);
    }

    public function delete(Authenticatable $actor, string $partId, string $lease, int $version): void
    {
        $this->mutate($actor, $partId, $lease, $version, 'delete', function (SitePart $part): void {
            app(ContentDependencies::class)->assertUnusedResource($part->id);
            $part->current_revision_id = null;
            $part->save();
            app(ContentDependencies::class)->removeOwner('site-part', $part->id);
            foreach ($part->revisions()->get() as $revision) {
                app(ContentDependencies::class)->removeOwner('site-part-revision', $revision->id);
            }
            $part->revisions()->delete();
            $part->delete();
        }, boundary: true, validateClosure: false);
    }

    /** Guarded adapter used by design/history commands; no table or policy is request-selected. */
    public function mutate(Authenticatable $actor, string $partId, string $lease, int $version, string $ability, callable $command, ?SitePartPayload $candidate = null, ?HistoryContext $history = null, ?LogicalAction $action = null, bool $boundary = false, bool $validateClosure = true): SitePartMutationResult
    {
        if (! in_array($ability, ['update', 'publish', 'restore', 'delete', 'withdraw'], true)) {
            throw new \InvalidArgumentException('Unsupported site-part mutation ability.');
        }

        return DB::transaction(function () use ($actor, $partId, $lease, $version, $ability, $command, $candidate, $history, $action, $boundary, $validateClosure): SitePartMutationResult {
            $initial = SitePart::query()->findOrFail($partId);
            $initialIdentity = $this->access->authorize($actor, $ability, $initial);
            $this->locks->assertHeld($initial, $initialIdentity, $lease);
            if ($initial->write_version !== $version) {
                throw new WriteConflict('stale_version', $initial->write_version);
            }
            $this->composition->lockMutation($initial->draft_payload->validationDocument(), $validateClosure ? ($candidate ?? $initial->draft_payload)->validationDocument() : null, $this->context(), $actor, $partId);
            if ($validateClosure) {
                app(AuthoringConstraints::class)->check($actor, ($candidate ?? $initial->draft_payload)->validationDocument(), $this->context());
            }
            $part = SitePart::query()->lockForUpdate()->findOrFail($partId);
            $identity = $this->access->authorize($actor, $ability, $part);
            $this->locks->assertHeld($part, $identity, $lease);
            if ($part->write_version !== $version) {
                throw new WriteConflict('stale_version', $part->write_version);
            }
            if ($history !== null) {
                app(SitePartDraftHistory::class)->assertCurrent($history, $actor, $part, $lease);
            }
            $before = $part->draft_payload;
            $command($part);
            if ($part->exists && $part->isDirty()) {
                $part->forceFill(['write_version' => $version + 1, 'updated_by' => $identity->id, 'updated_by_type' => $identity->type])->save();
            }
            if ($boundary) {
                app(SitePartDraftHistory::class)->invalidate($partId);
            } elseif ($history !== null) {
                app(SitePartDraftHistory::class)->recordAccepted($history, $actor, $part, $lease, $before, $part->draft_payload, $action ?? new LogicalAction('edit', 'Edit site part'));
            }
            if ($part->exists) {
                app(ContentDependencies::class)->syncPart($part);
            }

            return $this->result($part->exists ? $part->fresh() : $part);
        }, 3);
    }

    public function context(string $locale = 'en'): RenderContext
    {
        return new RenderContext($locale, 'preview', layout: config('site-builder.site_parts.preview_layout'));
    }

    public function result(SitePart $part): SitePartMutationResult
    {
        return new SitePartMutationResult($part, $this->json->digest($part->draft_payload), $part->exists ? $this->validator->diagnostics($part->draft_payload->validationDocument(), context: $this->context()) : []);
    }

    public function validateNewPins(Authenticatable $actor, ?Document $before, Document $after): void
    {
        $this->composition->withCurrentReads(function () use ($actor, $before, $after): void {
            $existing = [];
            foreach ($before === null ? [] : CompositionData::references($before) as $path => $entry) {
                $key = ($entry['nodeId'] ?? $path).':'.$this->json->digest($entry['reference']);
                $existing[$key] = ($existing[$key] ?? 0) + 1;
            }
            foreach (CompositionData::references($after) as $path => $entry) {
                $key = ($entry['nodeId'] ?? $path).':'.$this->json->digest($entry['reference']);
                $new = ($existing[$key] ?? 0) === 0;
                if (! $new) {
                    $existing[$key]--;
                }
                $this->composition->revision($entry['reference'], $entry['kind'], $actor, $new);
            }
        });
    }

    private function preserve(?Document $before, Document $after): void
    {
        app(RenderFeatureRegistry::class)->assertPreserved($before, $after);
        $old = $before === null ? [] : DesignData::nodes($before);
        foreach (DesignData::nodes($after) as $id => $node) {
            $definition = app(ComponentRegistry::class)->resolve($node->type);
            if (($definition === null || $definition->version() !== $node->version) && (! isset($old[$id]) || ! $this->json->equals($old[$id], $node))) {
                throw ValidationException::withMessages(['payload' => 'Unsupported site-part nodes remain read-only.']);
            }
        }
    }

    private function metadata(array $metadata, bool $creating = false): void
    {
        if (array_diff(array_keys($metadata), ['name', 'description']) !== []) {
            throw ValidationException::withMessages(['metadata' => 'Only site-part name/description may be edited here.']);
        }
        Validator::make($metadata, ['name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:4000']])->validate();
    }
}
