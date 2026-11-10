<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\ReferenceScanner;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Diagnostic;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Contracts\HasPlacementPolicy;
use SiteBuilder\Core\Design\DesignData;
use SiteBuilder\Core\Design\DesignResolver;
use SiteBuilder\Core\Rendering\LayoutRegistry;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Core\SiteParts\SitePartAccess;
use SiteBuilder\Core\SiteParts\SitePartCatalog;
use SiteBuilder\Core\SiteParts\SitePartRevision;
use stdClass;
use WeakMap;

/** Resolves immutable local rows only. Every mount, including hidden repeats, is charged. */
final class CompositionResolver
{
    private int $checking = 0;

    private array $revisionReaders = [];

    private int $currentReads = 0;

    private ?WeakMap $designCache = null;

    public function __construct(private CanonicalJson $json, private DocumentCodec $codec, private ComponentRegistry $components, private SitePartAccess $access, private LayoutRegistry $layouts) {}

    /** Trusted import-only scope: the supplied immutable catalog is exclusive, never a DB fallback. */
    public function withRevisionReader(\Closure $reader, \Closure $action): mixed
    {
        $this->revisionReaders[] = $reader;
        $this->designCache = null;
        try {
            return $action();
        } finally {
            array_pop($this->revisionReaders);
            $this->designCache = null;
        }
    }

    /** Invoke after sorted resource locks; current reads avoid a stale MySQL RR snapshot. */
    public function withCurrentReads(\Closure $action): mixed
    {
        $this->currentReads++;
        try {
            return $action();
        } finally {
            $this->currentReads--;
        }
    }

    public function revision(PublishedPartReference $reference, ?string $kind = null, ?Authenticatable $actor = null, bool $newUse = false, bool $closure = true): SitePartRevision
    {
        if ($this->revisionReaders === [] && ! app(SitePartCatalog::class)->available()) {
            $this->fail('Install the optional site-part schema to resolve this required revision.');
        }
        if ($this->revisionReaders !== []) {
            [$part, $revision] = ($this->revisionReaders[array_key_last($this->revisionReaders)])($reference);
        } else {
            $current = $this->currentReads > 0 && DB::transactionLevel() > 0;
            $part = SitePart::query()->when($current, fn ($query) => $query->lockForUpdate())->find($reference->resourceId);
            $revision = SitePartRevision::query()->when($current, fn ($query) => $query->lockForUpdate())->find($reference->revisionId);
        }
        if ($part === null || $revision === null || $revision->resource_id !== $part->id || $revision->kind !== $part->kind || ($kind !== null && $part->kind !== $kind) || $revision->withdrawn_at !== null || ! hash_equals($reference->digest, $revision->digest) || ! hash_equals($revision->digest, $this->json->digest($revision->payload))) {
            $this->fail('A pinned site-part revision is missing, withdrawn, has the wrong kind or failed its immutable digest check.');
        }
        if ($actor !== null) {
            $this->access->authorize($actor, 'use', $part);
        }
        if ($newUse && ($part->archived_at !== null || $part->current_revision_id === null)) {
            $this->fail('An archived/unpublished site part cannot be attached for new use. Existing immutable pins remain unchanged.');
        }

        if ($actor !== null && $closure) {
            $this->resolve($revision->payload->validationDocument(), new RenderContext(config('app.locale', 'en'), 'preview', layout: config('site-builder.site_parts.preview_layout')), $actor, $part->id);
        }

        return $revision;
    }

    public function siteDesign(Document $document, RenderContext $context): ?stdClass
    {
        $reference = CompositionData::payload($document)?->siteDesign ?? null;
        if ($reference === null) {
            return null;
        }
        $this->designCache ??= new WeakMap;
        $contexts = $this->designCache[$document] ??= new WeakMap;
        if (! isset($contexts[$context])) {
            $contexts[$context] = $this->revision(PublishedPartReference::from($reference), 'design')->payload->design->toObject();
        }

        return $contexts[$context];
    }

    public function diagnostics(Document $document, RenderContext $context): array
    {
        if ($this->checking > 0) {
            return [];
        }
        try {
            $resolution = $this->resolve($document, $context);
            $this->checking++;
            try {
                $checkPresentation = function (array $entries) use (&$checkPresentation, $context): void {
                    foreach ($entries as $entry) {
                        $presentation = app(DesignResolver::class)->resolve($entry->document, $entry->node, $context, $entry->presentationOwner, $entry->ancestors);
                        if ($presentation->diagnostics !== []) {
                            $this->fail('Expanded presentation is incompatible: '.$presentation->diagnostics[0]->message);
                        }
                        $checkPresentation($entry->children);
                    }
                };
                $checkPresentation([...$resolution->body, ...($resolution->header ?? []), ...($resolution->footer ?? []), ...array_merge([], ...array_column($resolution->overlays, 'nodes'))]);
                foreach ($resolution->documents as $source) {
                    foreach (app(DocumentValidator::class)->diagnostics($source, context: $context) as $diagnostic) {
                        if (! in_array($diagnostic->code, ['unknown_property', 'unconfigured_locale'], true)) {
                            $this->fail('A required published site-part payload is no longer renderable: '.$diagnostic->message);
                        }
                    }
                }
            } finally {
                $this->checking--;
            }

            return [];
        } catch (ValidationException|\InvalidArgumentException $exception) {
            return [new Diagnostic('unavailable_composition', '/extensions/site-builder.render/composition', $exception->getMessage())];
        }
    }

    public function resolve(Document $document, RenderContext $context, ?Authenticatable $actor = null, ?string $ownerResourceId = null): CompositionResolution
    {
        $required = CompositionData::payload($document);
        if ($required !== null && ! CompositionData::shape($required)) {
            $this->fail('The required composition feature version/shape is unavailable.');
        }
        $usage = ['nodes' => 0, 'bytes' => strlen($this->codec->encode($document)), 'mounts' => 0, 'revisions' => 0];
        $references = [];
        $overlays = [];
        $documents = [];
        $distinct = [];
        $limits = ['nodes' => $this->limit('max_nodes', 1000), 'bytes' => $this->limit('max_bytes', 2097152), 'mounts' => $this->limit('max_mounts', 256), 'revisions' => $this->limit('max_revisions', 128)];
        $check = function () use (&$usage, $limits): void {
            foreach ($limits as $key => $maximum) {
                if ($usage[$key] > $maximum) {
                    $this->fail('Expanded composition exceeds the bounded '.$key.' limit.');
                }
            }
        };
        $mount = function (PublishedPartReference $reference, ?string $kind, array $ancestry, array $chain, int $depth, int $structuralDepth, ?string $selectable, ?string $binding, array $designAncestors) use (&$mount, &$walk, &$usage, &$references, &$documents, &$distinct, $check, $actor, $context, $ownerResourceId): array {
            if (count($ancestry) - ($ownerResourceId === null ? 0 : 1) >= $this->limit('max_hops', 8) || in_array($reference->resourceId, $ancestry, true)) {
                $this->fail('A cyclic resource/revision ancestry or excessive reference depth is forbidden.');
            }
            $revision = $this->revision($reference, $kind, $actor, closure: false);
            $kind = $revision->kind;
            $references[$revision->id] = $reference;
            $distinct[$revision->id] = true;
            $usage['revisions'] = count($distinct);
            $usage['mounts']++;
            $usage['bytes'] += strlen(json_encode($revision->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $check();
            if ($kind === 'design') {
                $documents[$revision->id] = $revision->payload->validationDocument();

                return [];
            }
            $source = $revision->payload->document;
            $siteDefaults = $this->siteDesign($source, $context);
            if ($siteDefaults !== null) {
                $designAncestors[] = ['payload' => $siteDefaults, 'source' => 'Mounted site design', 'scope' => 'ancestor'];
            }
            $partDefaults = DesignData::payload($source->extensions);
            if ($partDefaults !== null) {
                $designAncestors[] = ['payload' => $partDefaults, 'source' => 'Site part '.$revision->resource_id, 'scope' => 'ancestor'];
            }
            $documents[$revision->id] = $source;
            $ancestry[] = $reference->resourceId;
            $chain[] = $reference->resourceId.'@'.$reference->revisionId;
            $head = $tail = [];
            foreach (['header', 'footer', 'siteDesign'] as $nestedBinding) {
                $pin = CompositionData::payload($source)?->{$nestedBinding} ?? null;
                if ($pin !== null) {
                    if ($nestedBinding !== 'siteDesign' && ! $this->layouts->supports($context->layout, $nestedBinding)) {
                        $this->fail('The destination layout cannot render a nested required '.$nestedBinding.' region.');
                    }
                    $nested = $mount(PublishedPartReference::from($pin), $nestedBinding === 'siteDesign' ? 'design' : $nestedBinding, $ancestry, [...$chain, 'binding:'.$nestedBinding], $depth, $structuralDepth, $selectable, $binding, $designAncestors);
                    if ($nestedBinding === 'header') {
                        $head = $nested;
                    } elseif ($nestedBinding === 'footer') {
                        $tail = $nested;
                    }
                }
            }

            return [...$head, ...$walk($source->nodes, $source, $ancestry, $chain, $depth, $structuralDepth, $selectable, $binding, true, $designAncestors), ...$tail];
        };
        $walk = function (array $nodes, Document $source, array $ancestry, array $chain, int $depth, int $structuralDepth, ?string $selectable, ?string $binding, bool $mounted, array $designAncestors) use (&$walk, &$mount, &$usage, $check, $context, $document, &$overlays): array {
            $result = [];
            foreach ($nodes as $node) {
                if ($depth > $this->limit('max_depth', 32)) {
                    $this->fail('Expanded composition exceeds the bounded depth limit.');
                }
                $usage['nodes']++;
                $check();
                if ($node->type === 'core.global-reference' && $node->version === 1) {
                    if (CompositionData::payload($source) === null) {
                        $this->fail('Global references require a registered owner composition capability.');
                    }
                    $mountedNodes = $mount(PublishedPartReference::from($node->props->get('revision')), 'section', $ancestry, [...$chain, 'node:'.$node->id], $depth, $structuralDepth, $selectable ?? $node->id, $binding, $designAncestors);
                    if ($node->visibility === 'hidden') {
                        $mountedNodes = array_map(fn ($entry) => new CompositionRenderNode($entry->node, $entry->document, $entry->children, $entry->address, $entry->selectableId, $entry->binding, $entry->mounted, true, $entry->presentationOwner, $entry->ancestors), $mountedNodes);
                    }
                    $result = [...$result, ...$mountedNodes];

                    continue;
                }
                foreach (app(ReferenceScanner::class)->scan(new Document([$node])) as $occurrence) {
                    if ($occurrence->nodeId === $node->id && $occurrence->kind === 'revision') {
                        if (CompositionData::payload($source) === null) {
                            $this->fail('Declared global revision properties require the owner composition capability.');
                        }
                        $reference = PublishedPartReference::from($occurrence->value);
                        if ($occurrence->revisionKind === 'overlay') {
                            $identity = OverlaySettings::id($reference, $context);
                            $entries = $mount($reference, 'overlay', $ancestry, ['overlay:'.$reference->revisionId], $depth + 1, 0, null, 'overlay', []);
                            $overlays[$identity] = ['reference' => $reference, 'nodes' => $entries];
                        } else {
                            $mount($reference, $occurrence->revisionKind, $ancestry, [...$chain, 'property:'.$node->id.':'.$occurrence->path], $depth + 1, $structuralDepth, $selectable, $binding, $designAncestors);
                        }
                    }
                }
                $definition = $this->components->resolve($node->type);
                $nextStructural = $structuralDepth + ($definition instanceof HasPlacementPolicy && $definition->placement()->structural ? 1 : 0);
                if ($nextStructural > max(1, min(8, (int) config('site-builder.composition.max_structural_depth', 4)))) {
                    $this->fail('Expanded composition exceeds the structural ancestor limit.');
                }
                $nextDesign = $designAncestors;
                if (($ownDesign = DesignData::payload($node->extensions)) !== null) {
                    $nextDesign[] = ['payload' => $ownDesign, 'source' => 'Parent '.$node->id, 'scope' => 'ancestor'];
                }
                $children = $walk($node->children, $source, $ancestry, [...$chain, 'node:'.$node->id], $depth + 1, $nextStructural, $selectable, $binding, $mounted, $nextDesign);
                if ($definition !== null && $definition->version() === $node->version) {
                    foreach ($children as $child) {
                        if (! $definition->allowedChildren()->allows($child->node->type)) {
                            $this->fail('Expanded global content violates its effective parent child-type policy.');
                        }
                    }
                    if (method_exists($definition->allowedChildren(), 'allowsCount') && ! $definition->allowedChildren()->allowsCount(count($children))) {
                        $this->fail('Expanded global content violates its effective parent cardinality.');
                    }
                }
                $address = hash('sha256', json_encode([$context->pageId, $chain, $node->id], JSON_THROW_ON_ERROR));
                $result[] = new CompositionRenderNode($node, $source, $children, $address, $mounted ? $selectable : $node->id, $binding, $mounted, false, $mounted ? $document : null, $mounted ? $designAncestors : []);
            }

            return $result;
        };
        $ancestry = $ownerResourceId === null ? [] : [$ownerResourceId];
        $check();
        $body = $walk($document->nodes, $document, $ancestry, [], 1, 0, null, null, false, []);
        $header = $footer = null;
        foreach (['header', 'footer', 'siteDesign'] as $binding) {
            $value = CompositionData::payload($document)?->{$binding} ?? null;
            if ($value === null) {
                continue;
            }
            if ($binding !== 'siteDesign' && ! $this->layouts->supports($context->layout, $binding)) {
                $this->fail('The selected trusted layout does not declare the '.$binding.' region.');
            }
            $nodes = $mount(PublishedPartReference::from($value), $binding === 'siteDesign' ? 'design' : $binding, $ancestry, ['binding:'.$binding], 1, 0, null, $binding, []);
            if ($binding === 'header') {
                $header = $nodes;
            } elseif ($binding === 'footer') {
                $footer = $nodes;
            }
        }
        $check();

        $attached = [];
        $attach = function (array $entries) use (&$attach, &$attached, $overlays, $context): void {
            foreach ($entries as $entry) {
                if ($entry->suppressed || $entry->node->visibility === 'hidden') {
                    continue;
                }
                foreach (app(ResolvedResourceReferences::class)->overlays($entry->node, $context) as $identity => $reference) {
                    if (! isset($attached[$identity]) && isset($overlays[$identity])) {
                        $attached[$identity] = $overlays[$identity];
                        $attach($overlays[$identity]['nodes']);
                    }
                }
                $attach($entry->children);
            }
        };
        $attach([...$body, ...($header ?? []), ...($footer ?? [])]);

        return new CompositionResolution($document, $body, $header, $footer, $references, $documents, $usage, $attached);
    }

    public function lockMutation(?Document $before, ?Document $after, RenderContext $context, ?Authenticatable $actor = null, ?string $ownerResourceId = null): array
    {
        $ids = $ownerResourceId === null ? [] : [$ownerResourceId];
        if ($before !== null) {
            try {
                foreach (CompositionData::references($before) as $entry) {
                    $ids[] = $entry['reference']->resourceId;
                }
            } catch (\InvalidArgumentException) {
                // Removing malformed old content must remain possible; no new pin
                // is admitted from the old payload or its unparseable provenance.
            }
        }
        if ($after !== null) {
            foreach ($this->resolve($after, $context, $actor, $ownerResourceId)->references as $reference) {
                $ids[] = $reference->resourceId;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        if (DB::transactionLevel() > 0 && $ids !== []) {
            SitePart::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($after !== null) {
                $this->withCurrentReads(fn () => $this->resolve($after, $context, $actor, $ownerResourceId));
            }
        }

        return $ids;
    }

    public function lockReferences(Document $document, RenderContext $context, ?Authenticatable $actor = null, ?string $ownerResourceId = null): array
    {
        return $this->lockDocuments([$document], $context, $actor, $ownerResourceId);
    }

    public function lockDocuments(array $documents, RenderContext $context, ?Authenticatable $actor = null, ?string $ownerResourceId = null): array
    {
        $ids = $ownerResourceId === null ? [] : [$ownerResourceId];
        $closures = [];
        foreach ($documents as $document) {
            if ($document === null) {
                continue;
            }
            $resolution = $this->resolve($document, $context, $actor, $ownerResourceId);
            $closures[] = [$document, array_keys($resolution->references)];
            foreach ($resolution->references as $reference) {
                $ids[] = $reference->resourceId;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        if (DB::transactionLevel() > 0 && $ids !== []) {
            SitePart::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            foreach ($closures as [$document, $references]) {
                $fresh = $this->withCurrentReads(fn () => $this->resolve($document, $context, $actor, $ownerResourceId));
                if (array_keys($fresh->references) !== $references) {
                    $this->fail('The immutable composition closure changed while acquiring its locks. Retry from saved content.');
                }
            }
        }

        return $ids;
    }

    private function limit(string $key, int $maximum): int
    {
        return max(1, min($maximum, (int) config('site-builder.composition.'.$key, $maximum)));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['composition' => $message]);
    }
}
