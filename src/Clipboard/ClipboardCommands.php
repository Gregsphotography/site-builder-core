<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Clipboard;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\Reference;
use SiteBuilder\Core\Components\ReferenceScanner;
use SiteBuilder\Core\Composition\CompositionData;
use SiteBuilder\Core\Composition\CompositionResolver;
use SiteBuilder\Core\Composition\CompositionResult;
use SiteBuilder\Core\Composition\CopyContext;
use SiteBuilder\Core\Composition\CopyReferenceResolver;
use SiteBuilder\Core\Composition\DocumentCopies;
use SiteBuilder\Core\Composition\InsertPosition;
use SiteBuilder\Core\Composition\PublishedPartReference;
use SiteBuilder\Core\Composition\ReferenceMap;
use SiteBuilder\Core\Composition\ReferenceReport;
use SiteBuilder\Core\Composition\SectionSourceScope;
use SiteBuilder\Core\Composition\SourceDocumentValidator;
use SiteBuilder\Core\Composition\SourceSnapshot;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Contracts\CopyReferenceInspector;
use SiteBuilder\Core\Design\DesignData;
use SiteBuilder\Core\Design\DesignOperations;
use SiteBuilder\Core\DraftHistory\DraftHistory;
use SiteBuilder\Core\DraftHistory\HistoryContext;
use SiteBuilder\Core\DraftHistory\LogicalAction;
use SiteBuilder\Core\Editing\TreeOperations;
use SiteBuilder\Core\Editing\TreeResolver;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\ActorIdentity;
use SiteBuilder\Core\Persistence\MutationResult;
use SiteBuilder\Core\Persistence\PageAccess;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;
use SiteBuilder\Core\SiteParts\ContentDependencies;
use stdClass;

/** Exact retained sources are the only clipboard admission proof; strings grant no access. */
final class ClipboardCommands
{
    public function __construct(private PageAccess $access, private PageLocks $locks, private DocumentCodec $codec, private DocumentValidator $validator, private CanonicalJson $json, private DocumentCopies $copies, private CopyReferenceResolver $references, private ReferenceScanner $scanner, private ComponentRegistry $components, private DesignOperations $design, private RenderFeatureRegistry $features, private ClipboardNamespace $namespace, private ClipboardDomainResolver $domains, private ClipboardExportPolicy $export) {}

    public function available(): bool
    {
        return Schema::hasTable('site_builder_clipboard_owners') && Schema::hasTable('site_builder_clipboard_snapshots');
    }

    public function capture(Authenticatable $actor, int $sourcePageId, string $leaseToken, int $expectedPageVersion, string $rootId, string $tabId, string $locale): ClipboardEnvelope
    {
        $this->assertAvailable();
        $this->locale($locale);
        if (! Str::isUuid($tabId)) {
            throw new InvalidArgumentException('A clipboard capture requires the current editor tab UUID.');
        }

        return DB::transaction(function () use ($actor, $sourcePageId, $leaseToken, $expectedPageVersion, $rootId, $tabId, $locale): ClipboardEnvelope {
            $page = $this->page($actor, $sourcePageId, $leaseToken, $expectedPageVersion);
            $this->access->authorize($actor, 'preview', $page);
            $current = $page->draft_content ?? new Document;
            $scope = SectionSourceScope::project($current);
            $root = DesignData::nodes($current)[$rootId] ?? throw new InvalidArgumentException('Select a current section subtree to copy.');
            $extensions = $scope['extensions']->toObject();
            if ($scope['renderFeatures'] !== null) {
                $extensions->{'site-builder.render'} = $scope['renderFeatures'];
            }
            $document = new Document([$root], new JsonObject($extensions));
            app(SourceDocumentValidator::class)->validate($document, $this->context($page, $locale));
            $capabilities = $this->capabilities($document);
            $this->export->assertExportable($actor, $page, $document);
            $limits = new ClipboardLimits;
            $id = (string) Str::uuid();
            $expires = now()->addSeconds($limits->ttlSeconds);
            $namespace = $this->namespace->current();
            $digest = $this->json->digest($document);
            $references = ['pages' => [], 'media' => [], 'revisions' => []];
            foreach ($this->scanner->scan($document) as $reference) {
                $collection = match ($reference->kind) {
                    'page' => 'pages', 'media' => 'media', 'revision' => 'revisions',
                    default => throw new LogicException('A clipboard reference kind requires an explicit typed collection.'),
                };
                $value = $reference->kind === 'revision' ? PublishedPartReference::from($reference->value)->jsonSerialize() : $reference->value;
                $references[$collection][] = ['nodeId' => $reference->nodeId, 'path' => $reference->path, 'locale' => $reference->locale, 'kind' => $reference->kind, 'value' => $value];
            }
            $payload = (object) ['format' => ClipboardEnvelope::FORMAT, 'version' => ClipboardEnvelope::VERSION, 'source' => (object) ['namespace' => $namespace, 'pageId' => $page->id, 'pageVersion' => $page->write_version, 'snapshotId' => $id, 'digest' => $digest, 'expiresAt' => $expires->toAtomString()], 'requiredCapabilities' => (object) $capabilities, 'nodes' => $document->nodes, 'extensions' => $document->extensions->toObject(), 'references' => (object) $references];
            $text = $this->json->encode($payload);
            $encoded = $this->codec->encode($document);
            $charged = strlen($text) + strlen($encoded);
            if (strlen($text) > $limits->envelopeBytes || $charged > $limits->bytes) {
                $this->fail('The selected subtree exceeds the clipboard content/storage limit. Copy a smaller section.');
            }
            $identity = ActorIdentity::from($actor);
            $owner = $this->owner($identity, $namespace);
            // Upsert takes the exclusive quota lock without shared-lock upgrades.
            DB::table('site_builder_clipboard_owners')->upsert([['id' => $owner, 'actor_id' => $identity->id, 'actor_type' => $identity->type, 'namespace' => $namespace, 'created_at' => now(), 'updated_at' => now()]], ['id'], ['updated_at']);
            $quota = DB::table('site_builder_clipboard_owners')->where('id', $owner)->lockForUpdate()->firstOrFail();
            $sequence = (int) $quota->sequence + 1;
            DB::table('site_builder_clipboard_owners')->where('id', $owner)->update(['sequence' => $sequence, 'updated_at' => now()]);
            $query = DB::table('site_builder_clipboard_snapshots')->where('owner_id', $owner);
            (clone $query)->where('expires_at', '<=', now())->delete();
            // A consistent read may predate the quota lock under MySQL RR.
            $retained = (clone $query)->orderBy('owner_sequence')->lockForUpdate()->get(['id', 'storage_bytes']);
            $bytes = (int) $retained->sum('storage_bytes');
            while ($retained->count() >= $limits->snapshots || $bytes + $charged > $limits->bytes) {
                $oldest = $retained->shift();
                if ($oldest === null) {
                    throw new LogicException('Clipboard quota accounting is inconsistent.');
                }
                (clone $query)->where('id', $oldest->id)->delete();
                $bytes -= (int) $oldest->storage_bytes;
            }
            DB::table('site_builder_clipboard_snapshots')->insert(['id' => $id, 'owner_id' => $owner, 'source_page_id' => $page->id, 'source_version' => $page->write_version, 'owner_sequence' => $sequence, 'source_domain' => $this->domain($actor, $page), 'source_layout' => $page->layout, 'source_tab_id' => $tabId, 'document' => $encoded, 'envelope' => $text, 'digest' => $digest, 'envelope_digest' => hash('sha256', $text), 'copy_history_digest' => $scope['copyHistoryDigest'], 'storage_bytes' => $charged, 'expires_at' => $expires, 'created_at' => now()]);

            return $this->envelope($payload, $document, $text);
        }, 3);
    }

    public function review(Authenticatable $actor, int $pageId, string $leaseToken, int $expectedVersion, string $text, string $locale, ?ReferenceMap $map = null, ?InsertPosition $position = null): ClipboardReview
    {
        $this->assertAvailable();
        $this->locale($locale);

        return DB::transaction(function () use ($actor, $pageId, $leaseToken, $expectedVersion, $text, $locale, $map, $position): ClipboardReview {
            $page = $this->page($actor, $pageId, $leaseToken, $expectedVersion);
            [$source, $envelope, $crossDomain, $closureError] = $this->resolve($actor, $page, $text, map: $map, locale: $locale, position: $position);
            $report = $this->report($actor, $page, $source, $locale, $map, $crossDomain, $closureError);
            if (! $report->blocking) {
                try {
                    $this->candidate($page, $source, $locale, $map ?? ReferenceMap::fromArray([]), $position ?? InsertPosition::end());
                } catch (InvalidArgumentException|ValidationException $exception) {
                    $report = new ReferenceReport($report->occurrences, [...$report->diagnostics, ['severity' => 'error', 'code' => 'incompatible_clipboard_source', 'path' => '/clipboard', 'message' => $exception->getMessage()]], true);
                }
            }

            return new ClipboardReview($envelope, $report, hash('sha256', $text), $page->write_version);
        }, 3);
    }

    public function paste(Authenticatable $actor, int $pageId, string $leaseToken, int $expectedVersion, string $text, InsertPosition $position, string $locale, ?ReferenceMap $map = null, ?HistoryContext $history = null): CompositionResult
    {
        $this->assertAvailable();
        $this->locale($locale);

        return DB::transaction(function () use ($actor, $pageId, $leaseToken, $expectedVersion, $text, $position, $locale, $map, $history): CompositionResult {
            $page = $this->page($actor, $pageId, $leaseToken, $expectedVersion);
            [$source, , $crossDomain, $closureError] = $this->resolve($actor, $page, $text, $history, $leaseToken, $map, $locale, $position);
            $report = $this->report($actor, $page, $source, $locale, $map, $crossDomain, $closureError);
            $report->assertApplicable();
            $before = $page->draft_content;
            [$document, $ids, $diagnostics] = $this->candidate($page, $source, $locale, $map ?? ReferenceMap::fromArray([]), $position);
            $page->forceFill(['draft_content' => $document, 'write_version' => $expectedVersion + 1])->save();
            if ($history !== null) {
                app(DraftHistory::class)->recordAccepted($history, $actor, $page, $leaseToken, $before, $document, new LogicalAction('paste', 'Paste section'));
            }
            app(ContentDependencies::class)->syncPage($page);
            $page = $page->fresh();

            return new CompositionResult(new MutationResult($page, $this->json->digest($page->draft_content), $diagnostics), $ids, $source, $report);
        }, 3);
    }

    /** Pruning takes only quota/snapshot locks and never writes a page. */
    public function prune(): int
    {
        if (! $this->available()) {
            return 0;
        }
        $count = 0;
        DB::table('site_builder_clipboard_owners')->orderBy('id')->chunkById(100, function ($owners) use (&$count): void {
            foreach ($owners as $owner) {
                $count += DB::transaction(function () use ($owner): int {
                    $locked = DB::table('site_builder_clipboard_owners')->where('id', $owner->id)->lockForUpdate()->first();
                    if ($locked === null) {
                        return 0;
                    }
                    $query = DB::table('site_builder_clipboard_snapshots')->where('owner_id', $owner->id);
                    $removed = (clone $query)->where('expires_at', '<=', now())->delete();
                    if ((clone $query)->lockForUpdate()->first(['id']) === null) {
                        DB::table('site_builder_clipboard_owners')->where('id', $owner->id)->delete();
                    }

                    return $removed;
                }, 3);
            }
        }, 'id');

        return $count;
    }

    private function resolve(Authenticatable $actor, Page $destination, string $text, ?HistoryContext $history = null, string $leaseToken = '', ?ReferenceMap $map = null, string $locale = '', ?InsertPosition $position = null): array
    {
        if (strlen($text) > (new ClipboardLimits)->envelopeBytes) {
            $this->fail('Clipboard text exceeds the configured limit. Preserve it for recovery and copy a smaller section.');
        }
        try {
            $payload = json_decode($text, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->fail('Clipboard text must be a supported structured content envelope.');
        }
        if (! $payload instanceof stdClass || ($payload->format ?? null) !== ClipboardEnvelope::FORMAT || ($payload->version ?? null) !== ClipboardEnvelope::VERSION || ! ($payload->source ?? null) instanceof stdClass || ! is_string($payload->source->namespace ?? null) || ! is_string($payload->source->snapshotId ?? null) || ! Str::isUuid($payload->source->snapshotId)) {
            $this->fail('This clipboard format/version is unsupported. The original text is unchanged.');
        }
        $namespace = $this->namespace->current();
        if (! hash_equals($namespace, $payload->source->namespace)) {
            $this->fail('This clipboard belongs to another installation. A trusted host importer is required; numeric references are not portable identities.');
        }
        $identity = ActorIdentity::from($actor);
        $owner = $this->owner($identity, $namespace);
        $record = DB::table('site_builder_clipboard_snapshots')->where('owner_id', $owner)->where('id', $payload->source->snapshotId)->lockForUpdate()->first();
        if ($record === null || ! Carbon::parse($record->expires_at)->isFuture() || $record->source_page_id === null) {
            $this->fail('The retained clipboard snapshot is unavailable or expired. Copy the source section again.');
        }
        if (! hash_equals($record->envelope_digest, hash('sha256', $record->envelope))) {
            throw new LogicException('The retained clipboard envelope failed its integrity check.');
        }
        if (! hash_equals($record->envelope, $this->json->encode($payload))) {
            $this->fail('Clipboard content/provenance changed after capture. Copy the original section again.');
        }
        $sourcePage = Page::query()->find($record->source_page_id);
        if ($sourcePage === null) {
            $this->fail('The clipboard source page is unavailable.');
        }
        // Public view access does not confer access to a captured private draft.
        $this->access->authorize($actor, 'preview', $sourcePage);
        if ($record->source_domain !== $this->domain($actor, $sourcePage)) {
            $this->fail('The clipboard source changed content domain. Capture a new authorized snapshot.');
        }
        if ($history !== null) {
            app(DraftHistory::class)->inspectCurrent($history, $actor, $destination, $leaseToken);
        }
        $document = $this->codec->decode($record->document);
        if (! hash_equals($record->digest, $this->json->digest($document))) {
            throw new LogicException('The retained clipboard document failed its integrity check.');
        }
        if (! $this->json->equals($payload->requiredCapabilities, (object) $this->capabilities($document))) {
            $this->fail('The clipboard requires unavailable render capabilities. Preserve the original text.');
        }
        $source = SourceSnapshot::fromDocument('clipboard', $record->id, (string) $record->source_version, $document, ['copyHistoryDigest' => $record->copy_history_digest]);
        $mapped = $this->copies->instantiate($source, new CopyContext($destination->id, $locale), $map ?? ReferenceMap::fromArray([]))->document;
        // Only the incoming physical subtree becomes active. Source page bindings
        // are inactive provenance and must never be attached to this destination.
        $position ??= InsertPosition::end();
        $before = $destination->draft_content ?? new Document;
        app(TreeOperations::class)->assertWritableSlot($before, $position->slot());
        $tree = app(TreeResolver::class);
        $siblings = $tree->children($before, $position->slot());
        array_splice($siblings, $position->index($before), 0, $mapped->nodes);
        $incoming = CompositionData::promote($tree->withChildren($before, $position->slot(), $siblings));
        $closureError = null;
        try {
            app(CompositionResolver::class)->lockMutation($destination->draft_content, $incoming, $this->context($destination, $locale), $actor);
        } catch (AuthorizationException|ValidationException|InvalidArgumentException) {
            // Keep exact occurrence choices available for an explicit authorized
            // remap; an unavailable original pin never permits a partial write.
            $closureError = 'The pinned source/destination closure is unavailable or unauthorized. Remap the reported pin or resolve destination references.';
        }
        if ($history !== null) {
            app(DraftHistory::class)->assertCurrent($history, $actor, $destination, $leaseToken);
        }
        $this->export->assertExportable($actor, $sourcePage, $document);
        $this->export->assertTransferable($actor, $sourcePage, $destination, $document);

        return [$source, $this->envelope($payload, $document, $record->envelope), $record->source_domain !== $this->domain($actor, $destination), $closureError];
    }

    private function report(Authenticatable $actor, Page $page, SourceSnapshot $source, string $locale, ?ReferenceMap $map, bool $crossDomain, ?string $closureError = null): ReferenceReport
    {
        $report = $this->references->inspect($actor, $source, new CopyContext($page->id, $locale), $map);
        $occurrences = $report->occurrences;
        $diagnostics = $report->diagnostics;
        $blocking = $report->blocking;
        if ($closureError !== null) {
            $diagnostics[] = ['severity' => 'error', 'code' => 'unavailable_clipboard_closure', 'path' => '/clipboard', 'message' => $closureError];
            $blocking = true;
        }
        if ($crossDomain && array_any($this->components->diagnostics($source->document), fn ($diagnostic): bool => in_array($diagnostic->code, ['unknown_component', 'unsupported_component_version'], true))) {
            $diagnostics[] = ['severity' => 'error', 'code' => 'opaque_cross_domain_source', 'path' => '/clipboard', 'message' => 'Unknown reference semantics cannot be remapped across content domains. A trusted host adapter is required.'];
            $blocking = true;
        }
        $domain = $this->domain($actor, $page);
        foreach ($occurrences as &$occurrence) {
            $entry = $map?->entry($occurrence['nodeId'], $occurrence['path']);
            $message = null;
            if ($crossDomain && $entry === null) {
                $message = 'Explicitly map or clear every declared reference when crossing content domains.';
            } elseif ($occurrence['kind'] === 'page' && is_int($occurrence['value'])) {
                $target = Page::query()->find($occurrence['value']);
                if ($target !== null && $this->domain($actor, $target) !== $domain) {
                    $message = 'The mapped page must belong to the destination content domain.';
                }
            } elseif ($crossDomain && $occurrence['kind'] === 'media' && $occurrence['status'] === 'unverified') {
                $message = 'Unverified host media cannot cross content domains by acknowledgement; map/clear it or install a trusted inspector.';
            } elseif ($crossDomain && $occurrence['kind'] === 'revision' && $occurrence['value'] !== null) {
                $reference = new Reference($occurrence['nodeId'], $occurrence['locale'], $occurrence['path'], 'revision', PublishedPartReference::from($occurrence['value'])->jsonSerialize());
                $approved = false;
                foreach (app()->tagged(CopyReferenceInspector::class) as $inspector) {
                    $check = $inspector->inspect($actor, $reference, new CopyContext($page->id, $locale));
                    if ($check !== null) {
                        $approved = $check->status === 'valid';
                        break;
                    }
                }
                if (! $approved) {
                    $message = 'Published site-part pins crossing content domains need an explicit map and a trusted destination-aware reference inspector. Acknowledgement does not grant use.';
                }
            }
            if ($message !== null) {
                $occurrence['status'] = 'unauthorized';
                $occurrence['message'] = $message;
                $blocking = true;
            }
        }
        unset($occurrence);

        return new ReferenceReport($occurrences, $diagnostics, $blocking);
    }

    private function candidate(Page $page, SourceSnapshot $source, string $locale, ReferenceMap $map, InsertPosition $position): array
    {
        $before = $page->draft_content ?? new Document;
        app(TreeOperations::class)->assertWritableSlot($before, $position->slot());
        $index = $position->index($before);
        $scope = SectionSourceScope::project($source->document);
        $copy = $this->copies->instantiate($source, new CopyContext($page->id, $locale), $map);
        $tree = app(TreeResolver::class);
        $siblings = $tree->children($before, $position->slot());
        array_splice($siblings, $index, 0, $copy->document->nodes);
        $nodes = $tree->withChildren($before, $position->slot(), $siblings)->nodes;
        $ids = array_map(fn (Node $node): string => $node->id, $copy->document->nodes);
        $candidate = $this->design->admitComposition($before, $nodes, $this->context($page, $locale));
        $extensions = $candidate->extensions->toObject();
        $history = $extensions->{'site-builder.copy'} ?? (object) ['version' => 1, 'entries' => []];
        if (! $history instanceof stdClass || ($history->version ?? null) !== 1 || ! is_array($history->entries ?? null) || ! array_is_list($history->entries)) {
            $this->fail('An existing unrecognized copy-provenance extension must be preserved.');
        }
        $sourceExtensions = $scope['extensions']->toObject();
        if ($scope['renderFeatures'] !== null) {
            $sourceExtensions->{'site-builder.render'} = $scope['renderFeatures'];
        }
        $history->entries[] = (object) ['scope' => 'sections', 'sourceKind' => $source->kind, 'sourceId' => $source->id, 'sourceVersion' => $source->version, 'sourceDigest' => $source->digest, 'rootIds' => $ids, 'sourceDocumentExtensions' => $sourceExtensions, 'sourceCopyHistoryDigest' => $source->provenance['copyHistoryDigest'] ?? null, 'copiedAt' => now()->toAtomString()];
        $extensions->{'site-builder.copy'} = $history;
        $document = new Document($nodes, new JsonObject($extensions));
        $diagnostics = $this->validator->validate($document, context: $this->context($page, $locale));

        return [$document, $ids, $diagnostics];
    }

    private function capabilities(Document $document): array
    {
        SectionSourceScope::assertSupported($document);
        $capabilities = ['document' => Document::VERSION];
        foreach ([null, ...array_values(DesignData::nodes($document))] as $node) {
            if ($this->features->readOnly($document, $node)) {
                $this->fail('An unavailable required render capability cannot be copied. Preserve the source content.');
            }
            $envelope = ($node?->extensions ?? $document->extensions)->get('site-builder.render');
            foreach ($envelope ?? [] as $key => $feature) {
                if ($key === 'version') {
                    continue;
                }
                $name = 'render.'.$key;
                if (isset($capabilities[$name]) && $capabilities[$name] !== $feature->version) {
                    $this->fail('Mixed render capability versions require an explicit host clipboard adapter.');
                }
                $capabilities[$name] = $feature->version;
            }
        }
        ksort($capabilities);

        return $capabilities;
    }

    private function envelope(stdClass $payload, Document $document, string $text): ClipboardEnvelope
    {
        return new ClipboardEnvelope($text, $payload->source->snapshotId, $payload->source->digest, $payload->source->pageId, $payload->source->pageVersion, $payload->source->expiresAt, count(DesignData::nodes($document)), array_map(fn (Node $node): string => $node->id, $document->nodes));
    }

    private function owner(ActorIdentity $identity, string $namespace): string
    {
        return $this->json->digest([$identity->type, $identity->id, $namespace]);
    }

    private function domain(Authenticatable $actor, Page $page): string
    {
        $domain = $this->domains->domain($actor, $page);
        if ($domain === '' || strlen($domain) > 191 || ! mb_check_encoding($domain, 'UTF-8') || str_contains($domain, "\0")) {
            throw new LogicException('A clipboard content domain must be a bounded, nonempty trusted identifier.');
        }

        return $domain;
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

    private function context(Page $page, string $locale): RenderContext
    {
        return new RenderContext($locale, 'preview', $page->id, $page->layout);
    }

    private function locale(string $locale): void
    {
        if (! app(LocaleCatalog::class)->has($locale)) {
            throw new InvalidArgumentException('Select a configured destination locale.');
        }
    }

    private function assertAvailable(): void
    {
        if (! $this->available()) {
            $this->fail('Install optional clipboard storage with site-builder:clipboard-install, then run migrate.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['clipboard' => $message]);
    }
}
