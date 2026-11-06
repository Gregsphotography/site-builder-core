<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use Illuminate\Contracts\Auth\Authenticatable;
use SiteBuilder\Core\Components\ReferenceScanner;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Design\DesignOperations;
use SiteBuilder\Core\Design\DesignResolver;
use SiteBuilder\Core\DraftHistory\HistoryContext;
use SiteBuilder\Core\DraftHistory\LogicalAction;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Core\SiteParts\SitePartCommands;
use SiteBuilder\Core\SiteParts\SitePartMutationResult;
use SiteBuilder\Core\SiteParts\SitePartPayload;

/** Append a reviewed code-owned starting point to an independently owned resource. */
final class SitePartCopyCommands
{
    public function insert(Authenticatable $actor, string $partId, string $lease, int $version, SourceSelection $selection, ?ReferenceMap $map = null, ?HistoryContext $history = null): SitePartMutationResult
    {
        if ($selection->kind !== 'code-template') {
            throw new \InvalidArgumentException('Choose a trusted installed code pattern.');
        }
        $commands = app(SitePartCommands::class);

        return $commands->mutate($actor, $partId, $lease, $version, 'update', function (SitePart $part) use ($actor, $selection, $map, $commands): void {
            $old = $part->draft_payload->document ?? throw new \InvalidArgumentException('Patterns require a document resource.');
            $source = app(SourceResolver::class)->resolve($actor, $selection, true);
            if (array_any(app(ReferenceScanner::class)->scan($source->document), fn ($reference): bool => $reference->kind === 'revision')) {
                throw new \InvalidArgumentException('Use the explicit published-pin workflow for linked source content.');
            }
            $context = new CopyContext(0, $selection->locale, new CopyOwner('site-part', $part->id));
            app(CopyReferenceResolver::class)->inspect($actor, $source, $context, $map)->assertApplicable();
            $copy = app(DocumentCopies::class)->instantiate($source, $context, $map ?? ReferenceMap::fromArray([]));
            $render = $commands->context($selection->locale);
            $candidate = app(DesignOperations::class)->admitComposition($old, [...$old->nodes, ...$copy->document->nodes], $render);
            SectionSourceScope::project($old);
            $scope = SectionSourceScope::project($source->document);
            $sourceExtensions = $scope['extensions']->toObject();
            if ($scope['renderFeatures'] !== null) {
                $sourceExtensions->{'site-builder.render'} = $scope['renderFeatures'];
            }
            $extensions = $candidate->extensions->toObject();
            $provenance = $extensions->{'site-builder.copy'} ?? (object) ['version' => 1, 'entries' => []];
            $provenance->entries[] = (object) ['scope' => 'sections', 'sourceKind' => $source->kind, 'sourceId' => $source->id, 'sourceVersion' => $source->version, 'sourceDigest' => $source->digest, 'rootIds' => array_map(fn ($node) => $node->id, $copy->document->nodes), 'sourceDocumentExtensions' => $sourceExtensions, 'sourceCopyHistoryDigest' => $scope['copyHistoryDigest'], 'copiedAt' => now()->toAtomString()];
            $extensions->{'site-builder.copy'} = $provenance;
            $candidate = new Document($candidate->nodes, new JsonObject($extensions));
            app(RenderFeatureRegistry::class)->assertPreserved($old, $candidate);
            app(DesignResolver::class)->assertUnavailablePreserved($old, $candidate, $render);
            $commands->validateNewPins($actor, $old, $candidate);
            app(DocumentValidator::class)->validate($candidate, context: $render);
            $part->draft_payload = SitePartPayload::document($part->kind, $candidate);
        }, history: $history, action: new LogicalAction('insert', 'Insert installed pattern'));
    }
}
