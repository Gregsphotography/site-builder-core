<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;
use stdClass;

/** A section copy cannot discard a required document-wide rendering capability. */
final class SectionSourceScope
{
    public static function assertSupported(Document $document): void
    {
        if ($document->extensions->get('site-builder.render') !== null) {
            app(RenderFeatureRegistry::class)->projectSections($document);
        }
    }

    /** Project section settings; page-wide recognized copy history is digest-only. */
    public static function project(Document $document): array
    {
        self::assertSupported($document);
        $extensions = $document->extensions->toObject();
        $renderFeatures = $document->extensions->get('site-builder.render');
        unset($extensions->{'site-builder.render'}, $extensions->{'site-builder.discovery'}, $extensions->{'site-builder.overlay'});
        $digest = null;
        if (property_exists($extensions, 'site-builder.copy')) {
            $history = $extensions->{'site-builder.copy'};
            if (! $history instanceof stdClass || ($history->version ?? null) !== 1 || array_diff(array_keys(get_object_vars($history)), ['version', 'entries']) !== [] || ! is_array($history->entries ?? null) || ! array_is_list($history->entries)) {
                throw ValidationException::withMessages(['source' => 'Unrecognized page-wide copy provenance cannot be projected safely. The original payload is unchanged.']);
            }
            foreach ($history->entries as $entry) {
                if (! $entry instanceof stdClass || array_diff(array_keys(get_object_vars($entry)), ['scope', 'sourceKind', 'sourceId', 'sourceVersion', 'sourceDigest', 'rootIds', 'sourceDocumentExtensions', 'sourceCopyHistoryDigest', 'copiedAt']) !== [] || (isset($entry->scope) && $entry->scope !== 'sections') || ! is_string($entry->sourceKind ?? null) || ! preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $entry->sourceKind) || ! is_string($entry->sourceId ?? null) || $entry->sourceId === '' || strlen($entry->sourceId) > 191 || ! is_string($entry->sourceVersion ?? null) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,190}$/D', $entry->sourceVersion) || ! is_string($entry->sourceDigest ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $entry->sourceDigest) || ! is_array($entry->rootIds ?? null) || ! array_is_list($entry->rootIds) || array_any($entry->rootIds, fn ($id): bool => ! is_string($id) || $id === '' || strlen($id) > 128) || (property_exists($entry, 'sourceDocumentExtensions') && ! $entry->sourceDocumentExtensions instanceof stdClass) || (isset($entry->sourceCopyHistoryDigest) && (! is_string($entry->sourceCopyHistoryDigest) || ! preg_match('/^[a-f0-9]{64}$/D', $entry->sourceCopyHistoryDigest))) || (isset($entry->copiedAt) && ! is_string($entry->copiedAt))) {
                    throw ValidationException::withMessages(['source' => 'Unrecognized copy-history fields cannot be discarded by section projection.']);
                }
            }
            $digest = (new CanonicalJson)->digest($history);
            unset($extensions->{'site-builder.copy'});
        }

        return ['extensions' => new JsonObject($extensions), 'renderFeatures' => $renderFeatures, 'copyHistoryDigest' => $digest];
    }
}
