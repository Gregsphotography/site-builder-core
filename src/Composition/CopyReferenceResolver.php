<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\Reference;
use SiteBuilder\Core\Components\ReferenceScanner;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Contracts\CopyReferenceInspector;
use SiteBuilder\Core\Contracts\MediaResolver;
use SiteBuilder\Core\Contracts\OwnerAwareCopyReferenceInspector;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Rendering\SafeUrl;
use SiteBuilder\Core\Routing\PageUrlResolver;

final class CopyReferenceResolver
{
    public function __construct(private ReferenceScanner $scanner, private ComponentRegistry $components, private Gate $gate, private SafeUrl $urls, private MediaResolver $media, private PageUrlResolver $pageUrls) {}

    public function inspect(Authenticatable $actor, SourceSnapshot $source, CopyContext $destination, ?ReferenceMap $map = null): ReferenceReport
    {
        $map ??= ReferenceMap::fromArray([]);
        $occurrences = [];
        $diagnostics = [];
        $seen = [];
        $blocking = false;
        foreach ($this->scanner->scan($source->document) as $reference) {
            $key = ReferenceMap::key($reference->nodeId, $reference->path);
            $seen[$key] = true;
            $entry = $map->entry($reference->nodeId, $reference->path);
            $value = $entry['value'] ?? $reference->value;
            if ($entry !== null && array_key_exists('value', $entry)) {
                $value = $entry['value'];
            }
            if ($entry !== null && ($entry['kind'] !== $reference->kind || ! app(CanonicalJson::class)->equals($entry['original'], $reference->value))) {
                $status = 'invalid-map';
                $message = 'The mapped source kind/value no longer matches this occurrence.';
                $blocking = true;
            } else {
                $check = $this->check($actor, new Reference($reference->nodeId, $reference->locale, $reference->path, $reference->kind, $value), $destination);
                $status = $check->status;
                $message = $check->message;
                $blocking = $blocking || in_array($status, ['missing', 'unauthorized'], true) || ($status === 'unverified' && ! ($entry['keepUnverified'] ?? false));
            }
            $occurrences[] = ['key' => $key, 'nodeId' => $reference->nodeId, 'path' => $reference->path, 'locale' => $reference->locale, 'kind' => $reference->kind, 'original' => $reference->value, 'value' => $value, 'status' => $status, 'message' => $message];
        }
        foreach ($map->all() as $key => $entry) {
            if (! isset($seen[$key])) {
                $diagnostics[] = ['severity' => 'error', 'code' => 'unknown_reference_mapping', 'path' => $entry['path'], 'message' => 'Mapping is not a declared source reference occurrence.'];
                $blocking = true;
            }
        }
        foreach ($this->components->diagnostics($source->document) as $diagnostic) {
            if (in_array($diagnostic->code, ['unknown_component', 'unsupported_component_version'], true)) {
                $diagnostics[] = ['severity' => 'warning', 'code' => 'opaque_reference_schema', 'path' => $diagnostic->path, 'message' => 'Unavailable component data is retained; undeclared references cannot be interpreted/remapped.'];
            }
        }

        return new ReferenceReport($occurrences, $diagnostics, $blocking);
    }

    private function check(Authenticatable $actor, Reference $reference, CopyContext $destination): ReferenceCheck
    {
        if ($reference->value === null) {
            return ReferenceCheck::valid('Reference cleared; publication requirements are checked normally.');
        }
        if (($reference->kind === 'page' && (! is_int($reference->value) || $reference->value < 1)) || ($reference->kind === 'media' && (! is_string($reference->value) || $reference->value === '' || ! $this->urls->valid($reference->value, true)))) {
            return ReferenceCheck::missing('The destination reference value is invalid.');
        }
        if ($reference->kind === 'revision') {
            try {
                app(CompositionResolver::class)->revision(PublishedPartReference::from($reference->value), null, $actor, true);

                return ReferenceCheck::valid('Authorized immutable published site-part revision.');
            } catch (\Throwable) {
                return ReferenceCheck::unauthorized('This immutable published site-part revision is unavailable or unauthorized.');
            }
        }
        foreach (app()->tagged(CopyReferenceInspector::class) as $inspector) {
            if ($destination->owner !== null && (($destination->owner->kind === 'site-part' && ! $inspector instanceof OwnerAwareCopyReferenceInspector) || ($inspector instanceof OwnerAwareCopyReferenceInspector && ! $inspector->supportsOwner($destination->owner)))) {
                continue;
            }
            $result = $inspector->inspect($actor, $reference, $destination);
            if ($result !== null) {
                return $result;
            }
        }
        if ($reference->kind === 'page') {
            $page = Page::query()->find($reference->value);
            if ($page === null) {
                return ReferenceCheck::missing('The referenced page is unavailable.');
            }
            if (! $this->gate->forUser($actor)->allows('view', $page)) {
                return ReferenceCheck::unauthorized('The actor cannot use this referenced page.');
            }

            return ReferenceCheck::valid($page->status === 'published' && $this->pageUrls->path($page, $reference->locale ?? $destination->locale) !== null ? 'Authorized published page reference.' : 'Authorized page; no published URL exists in the reference locale.');
        }
        $url = $this->media->url($reference->value);
        if ($url === null || ! $this->urls->valid($url, true)) {
            return ReferenceCheck::missing('The media resolver reports this reference unavailable.');
        }

        return ReferenceCheck::unverified('The host media adapter cannot verify ownership/existence; explicitly keep or map this reference.');
    }
}
