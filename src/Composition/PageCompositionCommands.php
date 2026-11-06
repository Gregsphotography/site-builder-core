<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\DraftHistory\HistoryContext;
use SiteBuilder\Core\DraftHistory\LogicalAction;
use SiteBuilder\Core\Editing\TreeOperations;
use SiteBuilder\Core\Editing\TreeResolver;
use SiteBuilder\Core\Exceptions\InvalidDocument;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\MutationResult;
use SiteBuilder\Core\Persistence\PageAccess;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Core\SiteParts\SitePartCommands;
use stdClass;

final class PageCompositionCommands
{
    public function __construct(private PageAccess $access, private PageLocks $locks, private PageCommands $pages, private CompositionResolver $resolver, private CanonicalJson $json, private DocumentValidator $validator) {}

    public function bind(Authenticatable $actor, int $pageId, string $lease, int $version, string $binding, ?PublishedPartReference $reference, ?HistoryContext $history = null): MutationResult
    {
        return $this->mutate($actor, $pageId, $lease, $version, $history, fn ($document, $context) => $this->binding($document, $binding, $reference));
    }

    public function insertReference(Authenticatable $actor, int $pageId, string $lease, int $version, PublishedPartReference $reference, InsertPosition $position, ?HistoryContext $history = null): MutationResult
    {
        return $this->mutate($actor, $pageId, $lease, $version, $history, fn ($document, $context) => $this->insertion($document, $reference, $position));
    }

    public function replaceReference(Authenticatable $actor, int $pageId, string $lease, int $version, string $nodeId, PublishedPartReference $reference, ?HistoryContext $history = null): MutationResult
    {
        return $this->mutate($actor, $pageId, $lease, $version, $history, fn ($document, $context) => $this->replacement($document, $nodeId, $reference));
    }

    public function reviewBind(Authenticatable $actor, int $pageId, string $binding, ?PublishedPartReference $reference): array
    {
        return $this->review($actor, $pageId, fn ($document) => $this->binding($document, $binding, $reference));
    }

    public function reviewInsertReference(Authenticatable $actor, int $pageId, PublishedPartReference $reference, InsertPosition $position): array
    {
        return $this->review($actor, $pageId, fn ($document) => $this->insertion($document, $reference, $position));
    }

    public function reviewReplaceReference(Authenticatable $actor, int $pageId, string $nodeId, PublishedPartReference $reference): array
    {
        return $this->review($actor, $pageId, fn ($document) => $this->replacement($document, $nodeId, $reference));
    }

    public function stage(Authenticatable $actor, array $targets, PublishedPartReference $old, PublishedPartReference $new, bool $confirmation = false): array
    {
        if (! $confirmation || ! array_is_list($targets) || $targets === [] || count($targets) > 20 || $old->resourceId !== $new->resourceId) {
            throw ValidationException::withMessages(['stage' => 'Explicitly confirm at most 20 page drafts and revisions of the same resource.']);
        }
        foreach ($targets as $target) {
            if (! is_array($target) || array_diff(array_keys($target), ['pageId', 'leaseToken', 'expectedVersion', 'expectedDigest', 'history']) !== [] || ! is_int($target['pageId'] ?? null) || ! is_string($target['leaseToken'] ?? null) || ! is_int($target['expectedVersion'] ?? null) || ! is_string($target['expectedDigest'] ?? null) || (isset($target['history']) && ! $target['history'] instanceof HistoryContext)) {
                throw ValidationException::withMessages(['stage' => 'Each selected target requires its own acknowledged version, digest and active tab lease.']);
            }
        }
        $ids = array_column($targets, 'pageId');
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['stage' => 'Select each destination page only once.']);
        }
        usort($targets, fn ($a, $b) => $a['pageId'] <=> $b['pageId']);

        return DB::transaction(function () use ($actor, $targets, $old, $new): array {
            $prepared = [];
            $resourceIds = [$old->resourceId, $new->resourceId];
            foreach ($targets as $target) {
                $page = $this->page($actor, $target['pageId'], $target['leaseToken'], $target['expectedVersion']);
                if (! hash_equals($this->json->digest($page->draft_content), $target['expectedDigest'])) {
                    throw new WriteConflict('draft_digest_changed', $page->write_version);
                }
                $document = $page->draft_content ?? new Document;
                $candidate = $document;
                $changed = false;
                foreach (CompositionData::references($document) as $path => $entry) {
                    if (! $this->json->equals($entry['reference'], $old)) {
                        continue;
                    }
                    $candidate = $entry['nodeId'] === null ? $this->binding($candidate, basename($path), $new) : $this->replacement($candidate, $entry['nodeId'], $new);
                    $changed = true;
                }
                if (! $changed) {
                    throw new WriteConflict('site_part_pin_changed', $page->write_version);
                }
                $context = $this->context($page);
                foreach ([$document, $candidate] as $value) {
                    foreach ($this->resolver->resolve($value, $context, $actor)->references as $pin) {
                        $resourceIds[] = $pin->resourceId;
                    }
                }
                $prepared[] = [$target, $candidate];
            }
            $resourceIds = array_values(array_unique($resourceIds));
            sort($resourceIds, SORT_STRING);
            SitePart::query()->whereIn('id', $resourceIds)->orderBy('id')->lockForUpdate()->get();
            $this->resolver->revision($new, actor: $actor, newUse: true);
            $results = [];
            foreach ($prepared as [$target, $candidate]) {
                $results[] = $this->pages->saveDraft($actor, $target['pageId'], $target['leaseToken'], $target['expectedVersion'], $candidate, $target['history'] ?? null, new LogicalAction('site-part-update', 'Update pinned site part'));
            }

            return $results;
        }, 3);
    }

    private function review(Authenticatable $actor, int $pageId, callable $operation): array
    {
        $page = Page::query()->findOrFail($pageId);
        $this->access->authorize($actor, 'update', $page);
        $diagnostics = [];
        try {
            $document = $operation($page->draft_content ?? new Document);
            app(SitePartCommands::class)->validateNewPins($actor, $page->draft_content, $document);
            $diagnostics = $this->validator->validate($document, context: $this->context($page));
            $compatible = true;
        } catch (ValidationException|\InvalidArgumentException|InvalidDocument $exception) {
            $compatible = false;
            $diagnostics = [['code' => 'incompatible_composition', 'path' => '/composition', 'message' => $exception->getMessage()]];
        }

        return ['compatible' => $compatible, 'blocking' => ! $compatible, 'diagnostics' => array_map(fn ($diagnostic) => is_array($diagnostic) ? $diagnostic : ['code' => $diagnostic->code, 'path' => $diagnostic->path, 'message' => $diagnostic->message], $diagnostics), 'writeVersion' => $page->write_version, 'draftDigest' => $this->json->digest($page->draft_content), 'layout' => $page->layout];
    }

    private function mutate(Authenticatable $actor, int $pageId, string $lease, int $version, ?HistoryContext $history, callable $operation): MutationResult
    {
        return DB::transaction(function () use ($actor, $pageId, $lease, $version, $history, $operation): MutationResult {
            $page = $this->page($actor, $pageId, $lease, $version);
            $document = $operation($page->draft_content ?? new Document, $this->context($page));

            return $this->pages->saveDraft($actor, $pageId, $lease, $version, $document, $history, new LogicalAction('composition', 'Change pinned site part'));
        }, 3);
    }

    private function page(Authenticatable $actor, int $id, string $lease, int $version): Page
    {
        $page = Page::query()->lockForUpdate()->findOrFail($id);
        $identity = $this->access->authorize($actor, 'update', $page);
        $this->locks->assertHeld($page, $identity, $lease);
        if ($page->write_version !== $version) {
            throw new WriteConflict('stale_version', $page->write_version);
        }

        return $page;
    }

    private function context(Page $page): RenderContext
    {
        return new RenderContext(config('app.locale', 'en'), 'preview', $page->id, $page->layout);
    }

    private function binding(Document $document, string $binding, ?PublishedPartReference $reference): Document
    {
        if (! in_array($binding, ['header', 'footer', 'siteDesign'], true)) {
            throw new \InvalidArgumentException('Only fixed header/footer/siteDesign bindings are supported.');
        }
        $extensions = $document->extensions->toObject();
        $envelope = $extensions->{'site-builder.render'} ?? (object) ['version' => 1];
        $composition = $envelope->composition ?? (object) ['version' => 1];
        if (! $composition instanceof stdClass || ! CompositionData::shape($composition) || $envelope->version !== 1) {
            throw new \InvalidArgumentException('Unknown rendering requirements must be preserved.');
        }
        if ($reference === null) {
            unset($composition->{$binding});
        } else {
            $composition->{$binding} = $reference->jsonSerialize();
        }
        $envelope->composition = $composition;
        if (count(get_object_vars($composition)) === 1 && ! CompositionData::hasReferences($document->nodes)) {
            unset($envelope->composition);
        }
        if (count(get_object_vars($envelope)) === 1) {
            unset($extensions->{'site-builder.render'});
        } else {
            $extensions->{'site-builder.render'} = $envelope;
        }

        return new Document($document->nodes, new JsonObject($extensions));
    }

    private function insertion(Document $document, PublishedPartReference $reference, InsertPosition $position): Document
    {
        app(TreeOperations::class)->assertWritableSlot($document, $position->slot());
        $siblings = app(TreeResolver::class)->children($document, $position->slot());
        $node = new Node((string) Str::uuid(), 'core.global-reference', props: new JsonObject(['revision' => $reference->jsonSerialize()]));
        array_splice($siblings, $position->index($document), 0, [$node]);

        return CompositionData::promote(app(TreeResolver::class)->withChildren($document, $position->slot(), $siblings));
    }

    private function replacement(Document $document, string $id, PublishedPartReference $reference): Document
    {
        $found = false;
        $walk = function (array $nodes) use (&$walk, &$found, $id, $reference): array {
            return array_map(function (Node $node) use (&$walk, &$found, $id, $reference): Node {
                if ($node->id === $id) {
                    if ($node->type !== 'core.global-reference' || $node->version !== 1) {
                        throw new \InvalidArgumentException('Select a physical immutable section reference.');
                    }
                    $found = true;
                    $props = $node->props->toObject();
                    $props->revision = $reference->jsonSerialize();

                    return new Node($node->id, $node->type, $node->version, new JsonObject($props), $node->translations, $node->visibility, $node->children, $node->extensions);
                }

                return new Node($node->id, $node->type, $node->version, $node->props, $node->translations, $node->visibility, $walk($node->children), $node->extensions);
            }, $nodes);
        };
        $nodes = $walk($document->nodes);
        if (! $found) {
            throw new \InvalidArgumentException('The selected physical reference is unavailable.');
        }

        return CompositionData::promote(new Document($nodes, $document->extensions));
    }
}
