<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Snapshots;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\PropertyDefinition;
use SiteBuilder\Core\Composition\CompositionResolver;
use SiteBuilder\Core\Composition\CopyContext;
use SiteBuilder\Core\Composition\CopyReferenceResolver;
use SiteBuilder\Core\Composition\DocumentCopies;
use SiteBuilder\Core\Composition\ReferenceMap;
use SiteBuilder\Core\Composition\SourceSnapshot;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\DraftHistory\HistoryContext;
use SiteBuilder\Core\DraftHistory\LogicalAction;
use SiteBuilder\Core\Editing\TreeResolver;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\MutationResult;
use SiteBuilder\Core\Persistence\PageAccess;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Core\SiteParts\SitePartAccess;
use SiteBuilder\Core\SiteParts\SitePartCommands;
use SiteBuilder\Core\SiteParts\SitePartLocks;
use SiteBuilder\Core\SiteParts\SitePartMutationResult;
use SiteBuilder\Core\SiteParts\SitePartPayload;
use stdClass;

/** Server-resolved field copies; selections/digests never grant write authority. */
final class SnapshotCopyCommands
{
    public function __construct(private SnapshotSourceRegistry $sources, private ComponentRegistry $components, private TreeResolver $tree, private CanonicalJson $json, private DocumentValidator $validator, private CopyReferenceResolver $references, private DocumentCopies $copies) {}

    public function choices(Authenticatable $actor, SnapshotDestination $destination, string $locale, string $lease, int $version): array
    {
        return $this->withOwner($actor, $destination, $locale, $lease, $version, function ($owner, Document $document) use ($actor, $destination, $locale): array {
            $type = $this->tree->locate($document, $destination->nodeId)->node->type;
            $result = [];
            foreach ($this->sources->all() as $provider => $source) {
                $choices = $source->choices($actor, $destination, $locale);
                if (! array_is_list($choices) || count($choices) > 100) {
                    throw new \InvalidArgumentException('The source catalog exceeds its bound.');
                }
                $seen = [];
                foreach ($choices as $choice) {
                    if (! $choice instanceof SnapshotChoice || isset($seen[$choice->id])) {
                        throw new \InvalidArgumentException('Invalid source catalog.');
                    }
                    $seen[$choice->id] = true;
                    if ($choice->componentType === $type) {
                        $result[] = ['provider' => $provider, 'id' => $choice->id, 'label' => $choice->label, 'componentType' => $type];
                    }
                }
            }
            if (count($result) > 100) {
                throw new \InvalidArgumentException('The source catalog exceeds its bound.');
            }

            return $result;
        });
    }

    public function selection(Authenticatable $actor, SnapshotDestination $destination, string $provider, string $sourceId, string $locale, string $lease, int $version): SnapshotSelection
    {
        return $this->withOwner($actor, $destination, $locale, $lease, $version, function ($owner, Document $document) use ($actor, $destination, $provider, $sourceId, $locale, $version): SnapshotSelection {
            $source = $this->source($actor, $destination, $provider, $sourceId, $locale, $document);

            return new SnapshotSelection($provider, $sourceId, $source->version, $source->digest, $locale, $destination, $version);
        });
    }

    public function review(Authenticatable $actor, SnapshotSelection $selection, string $lease, ?ReferenceMap $referenceMap = null): SnapshotReview
    {
        return $this->withOwner($actor, $selection->destination, $selection->locale, $lease, $selection->expectedWriteVersion,
            fn ($owner, Document $document, RenderContext $context): SnapshotReview => $this->candidate($actor, $selection, $document, $context, $referenceMap)[0]);
    }

    public function apply(Authenticatable $actor, SnapshotSelection $selection, string $lease, string $expectedCandidateDigest, ?ReferenceMap $referenceMap = null, ?HistoryContext $history = null): MutationResult|SitePartMutationResult
    {
        return $this->withOwner($actor, $selection->destination, $selection->locale, $lease, $selection->expectedWriteVersion,
            function ($owner, Document $document, RenderContext $context) use ($actor, $selection, $lease, $expectedCandidateDigest, $referenceMap, $history): MutationResult|SitePartMutationResult {
                [$review, $candidate] = $this->candidate($actor, $selection, $document, $context, $referenceMap);
                $review->references->assertApplicable();
                if ($review->candidateDigest === null || ! hash_equals($review->candidateDigest, $expectedCandidateDigest)) {
                    throw new WriteConflict('snapshot_review_changed', $owner->write_version);
                }
                $action = new LogicalAction('snapshot', 'Copy source fields', nodeId: $selection->destination->nodeId, locale: $selection->locale);
                if ($owner instanceof Page) {
                    return app(PageCommands::class)->saveDraft($actor, $owner->id, $lease, $selection->expectedWriteVersion, $candidate, $history, $action);
                }

                return app(SitePartCommands::class)->saveDraft($actor, $owner->id, $lease, $selection->expectedWriteVersion, SitePartPayload::document($owner->kind, $candidate), $history, $action);
            });
    }

    private function candidate(Authenticatable $actor, SnapshotSelection $selection, Document $document, RenderContext $context, ?ReferenceMap $map): array
    {
        $source = $this->source($actor, $selection->destination, $selection->provider, $selection->sourceId, $selection->locale, $document);
        if ($source->version !== $selection->sourceVersion || ! hash_equals($source->digest, $selection->digest)) {
            throw new WriteConflict('source_changed', $selection->expectedWriteVersion);
        }
        $copyContext = new CopyContext($selection->destination->kind === 'page' ? $selection->destination->id : 0, $selection->locale, $selection->destination->owner);
        $report = $this->references->inspect($actor, $source, $copyContext, $map);
        if ($report->blocking) {
            return [new SnapshotReview($selection, [], $report, null), null];
        }
        $incoming = $this->copies->instantiate($source, $copyContext, $map ?? ReferenceMap::fromArray([]))->document->nodes[0];
        $target = $this->tree->locate($document, $selection->destination->nodeId)->node;
        $properties = $this->components->resolve($target->type)->properties();
        $props = $target->props->toObject();
        $translations = $target->translations->toObject();
        $changes = [];
        foreach (['props' => $incoming->props->toObject(), 'translations' => $incoming->translations->get($selection->locale, new stdClass)] as $scope => $values) {
            foreach ($values as $key => $value) {
                $oldValues = $scope === 'props' ? $props : ($translations->{$selection->locale} ?? new stdClass);
                $present = property_exists($oldValues, $key);
                $before = $scope === 'props' ? ($props->{$key} ?? null) : ($translations->{$selection->locale}->{$key} ?? null);
                $value = $this->merge($before, $value, $properties[$key]);
                if ($present && $this->json->equals($before, $value)) {
                    continue;
                }
                $path = $scope === 'props' ? '/props/'.$key : '/translations/'.$selection->locale.'/'.$key;
                $changes[] = ['path' => $path, 'beforePresent' => $present, 'before' => $before, 'after' => $value];
                if ($scope === 'props') {
                    $props->{$key} = $value;
                } else {
                    $translations->{$selection->locale} ??= new stdClass;
                    $translations->{$selection->locale}->{$key} = $value;
                }
            }
        }
        $replacement = new Node($target->id, $target->type, $target->version, new JsonObject($props), new JsonObject($translations), $target->visibility, $target->children, $target->extensions);
        $walk = function (array $nodes) use (&$walk, $replacement): array {
            return array_map(fn (Node $node): Node => $node->id === $replacement->id ? $replacement : new Node($node->id, $node->type, $node->version, $node->props, $node->translations, $node->visibility, $walk($node->children), $node->extensions), $nodes);
        };
        $candidate = new Document($walk($document->nodes), $document->extensions);
        $this->validator->validate($candidate, context: $context);

        return [new SnapshotReview($selection, $changes, $report, $this->json->digest($candidate)), $candidate];
    }

    private function source(Authenticatable $actor, SnapshotDestination $destination, string $provider, string $id, string $locale, Document $document): SourceSnapshot
    {
        $target = $this->tree->locate($document, $destination->nodeId)->node;
        $definition = $this->components->resolve($target->type);
        if ($definition === null || $definition->version() !== $target->version) {
            throw new \InvalidArgumentException('This component reader is unavailable.');
        }
        $service = $this->sources->get($provider);
        $choices = $service->choices($actor, $destination, $locale);
        if (! array_is_list($choices) || count($choices) > 100) {
            throw new \InvalidArgumentException('Invalid source catalog.');
        }
        $choice = array_find($choices, fn ($choice): bool => $choice instanceof SnapshotChoice && $choice->id === $id && $choice->componentType === $target->type);
        if ($choice === null) {
            throw new AuthorizationException('This snapshot source is unavailable.');
        }
        $source = $service->snapshot($actor, $destination, $id, $locale, true);
        app(DocumentCodec::class)->encode($source->document);
        if ($source->kind !== 'host-snapshot' || $source->id !== $provider.':'.$id || ! hash_equals($source->digest, $this->json->digest($source->document)) || count($source->document->nodes) !== 1 || $source->document->extensions->toObject() != new stdClass) {
            throw new \InvalidArgumentException('Invalid source snapshot.');
        }
        $node = $source->document->nodes[0];
        if ($node->type !== $target->type || $node->version !== $target->version || $node->children !== [] || $node->extensions->toObject() != new stdClass || $node->visibility !== 'visible' || array_diff(array_keys((array) $node->translations->toObject()), [$locale]) !== []) {
            throw new \InvalidArgumentException('Sources may only project fields onto the selected physical component.');
        }
        $check = function (mixed $value, PropertyDefinition $property) use (&$check): void {
            if ($property->kind === 'revision') {
                throw new \InvalidArgumentException('Snapshot fields cannot introduce composition pins.');
            }
            if ($property->kind === 'object' && $value instanceof stdClass) {
                foreach ($value as $key => $child) {
                    if (! isset($property->properties[$key])) {
                        throw new \InvalidArgumentException('Source fields must be declared.');
                    }
                    $check($child, $property->properties[$key]);
                }
            } elseif ($property->kind === 'list' && is_array($value)) {
                foreach ($value as $child) {
                    $check($child, $property->items);
                }
            }
        };
        foreach ([$node->props->toObject(), $node->translations->get($locale, new stdClass)] as $map) {
            foreach ($map as $key => $value) {
                $property = $definition->properties()[$key] ?? throw new \InvalidArgumentException('Source fields must be declared.');
                $check($value, $property);
            }
        }
        $this->validator->validate($source->document);

        return $source;
    }

    private function merge(mixed $old, mixed $value, PropertyDefinition $definition): mixed
    {
        if ($definition->kind === 'object' && $value instanceof stdClass) {
            $result = $old instanceof stdClass ? clone $old : new stdClass;
            foreach ($value as $key => $child) {
                $result->{$key} = $this->merge($result->{$key} ?? null, $child, $definition->properties[$key]);
            }

            return $result;
        }
        if ($definition->kind === 'list' && is_array($value)) {
            return array_map(fn ($child, $index) => $this->merge(is_array($old) ? ($old[$index] ?? null) : null, $child, $definition->items), $value, array_keys($value));
        }

        return $value;
    }

    private function withOwner(Authenticatable $actor, SnapshotDestination $destination, string $locale, string $lease, int $version, \Closure $operation): mixed
    {
        if (! app(LocaleCatalog::class)->has($locale)) {
            throw new \InvalidArgumentException('Choose a configured locale.');
        }

        return DB::transaction(function () use ($actor, $destination, $locale, $lease, $version, $operation): mixed {
            if ($destination->kind === 'page') {
                $owner = Page::query()->lockForUpdate()->findOrFail($destination->id);
                $identity = app(PageAccess::class)->authorize($actor, 'update', $owner);
                app(PageLocks::class)->assertHeld($owner, $identity, $lease);
                $context = new RenderContext($locale, 'preview', $owner->id, $owner->layout);
                $document = $owner->draft_content ?? new Document;
                if ($owner->write_version !== $version) {
                    throw new WriteConflict('stale_version', $owner->write_version);
                }
                app(CompositionResolver::class)->lockMutation($document, $document, $context, $actor);
            } else {
                $initial = SitePart::query()->findOrFail($destination->id);
                $identity = app(SitePartAccess::class)->authorize($actor, 'update', $initial);
                app(SitePartLocks::class)->assertHeld($initial, $identity, $lease);
                if ($initial->write_version !== $version) {
                    throw new WriteConflict('stale_version', $initial->write_version);
                }
                if ($initial->draft_payload->document === null) {
                    throw new \InvalidArgumentException('Select a document resource.');
                }
                $context = app(SitePartCommands::class)->context($locale);
                app(CompositionResolver::class)->lockMutation($initial->draft_payload->document, $initial->draft_payload->document, $context, $actor, $initial->id);
                $owner = SitePart::query()->lockForUpdate()->findOrFail($destination->id);
                $identity = app(SitePartAccess::class)->authorize($actor, 'update', $owner);
                app(SitePartLocks::class)->assertHeld($owner, $identity, $lease);
                if ($owner->write_version !== $version) {
                    throw new WriteConflict('stale_version', $owner->write_version);
                }
                $document = $owner->draft_payload->document;
            }

            return $operation($owner, $document, $context);
        }, 3);
    }
}
