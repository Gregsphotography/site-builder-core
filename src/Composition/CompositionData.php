<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use SiteBuilder\Core\Components\ReferenceScanner;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use stdClass;

final class CompositionData
{
    public static function payload(Document $document): ?stdClass
    {
        $envelope = $document->extensions->get('site-builder.render');

        return $envelope instanceof stdClass && ($envelope->composition ?? null) instanceof stdClass ? $envelope->composition : null;
    }

    public static function shape(stdClass $payload): bool
    {
        if (($payload->version ?? null) !== 1 || array_diff(array_keys(get_object_vars($payload)), ['version', 'header', 'footer', 'siteDesign']) !== []) {
            return false;
        }
        foreach (['header', 'footer', 'siteDesign'] as $key) {
            if (isset($payload->{$key})) {
                try {
                    PublishedPartReference::from($payload->{$key});
                } catch (\InvalidArgumentException) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function references(Document $document): array
    {
        $references = [];
        foreach (['header', 'footer', 'siteDesign'] as $binding) {
            $value = self::payload($document)?->{$binding} ?? null;
            if ($value !== null) {
                $references['/extensions/site-builder.render/composition/'.$binding] = ['reference' => PublishedPartReference::from($value), 'kind' => $binding === 'siteDesign' ? 'design' : $binding, 'nodeId' => null];
            }
        }
        $paths = [];
        $walk = function (array $nodes, string $path) use (&$walk, &$paths): void {
            foreach ($nodes as $index => $node) {
                $paths[$node->id] = [$path.'/'.$index, $node->type];
                $walk($node->children, $path.'/'.$index.'/children');
            }
        };
        $walk($document->nodes, '/nodes');
        foreach (app(ReferenceScanner::class)->scan($document) as $occurrence) {
            if ($occurrence->kind === 'revision') {
                [$nodePath, $type] = $paths[$occurrence->nodeId];
                $references[$nodePath.$occurrence->path] = ['reference' => PublishedPartReference::from($occurrence->value), 'kind' => $type === 'core.global-reference' ? 'section' : $occurrence->revisionKind, 'nodeId' => $occurrence->nodeId];
            }
        }

        return $references;
    }

    public static function promote(Document $document): Document
    {
        if (self::payload($document) !== null || ! self::hasReferences($document->nodes)) {
            return $document;
        }
        $extensions = $document->extensions->toObject();
        $envelope = $extensions->{'site-builder.render'} ?? (object) ['version' => 1];
        $envelope->composition = (object) ['version' => 1];
        $extensions->{'site-builder.render'} = $envelope;

        return new Document($document->nodes, new JsonObject($extensions));
    }

    public static function authoringDefaults(Document $document): Document
    {
        $defaults = config('site-builder.site_parts.defaults', []);
        if (! is_array($defaults) || $defaults === []) {
            return $document;
        }
        $extensions = $document->extensions->toObject();
        $envelope = $extensions->{'site-builder.render'} ?? (object) ['version' => 1];
        $composition = $envelope->composition ?? (object) ['version' => 1];
        if (! $composition instanceof stdClass || ! self::shape($composition)) {
            throw new \InvalidArgumentException('Authoring defaults cannot overwrite unavailable rendering requirements.');
        }
        foreach ($defaults as $binding => $value) {
            if (! in_array($binding, ['header', 'footer', 'siteDesign'], true)) {
                throw new \InvalidArgumentException('Authoring defaults use only fixed header/footer/siteDesign bindings.');
            }
            if (! property_exists($composition, $binding) && $value !== null) {
                $composition->{$binding} = PublishedPartReference::from($value)->jsonSerialize();
            }
        }
        if (count(get_object_vars($composition)) > 1) {
            $envelope->composition = $composition;
            $extensions->{'site-builder.render'} = $envelope;
        }

        return new Document($document->nodes, new JsonObject($extensions));
    }

    public static function hasReferences(array $nodes): bool
    {
        if (array_any($nodes, fn (Node $node): bool => $node->type === 'core.global-reference' || self::hasReferences($node->children))) {
            return true;
        }

        return array_any(app(ReferenceScanner::class)->scan(new Document($nodes)), fn ($reference) => $reference->kind === 'revision');
    }

    public static function withoutFeature(Document $document): Document
    {
        $extensions = $document->extensions->toObject();
        if (isset($extensions->{'site-builder.render'}->composition)) {
            unset($extensions->{'site-builder.render'}->composition);
            if (count(get_object_vars($extensions->{'site-builder.render'})) === 1) {
                unset($extensions->{'site-builder.render'});
            }
        }

        return new Document($document->nodes, new JsonObject($extensions));
    }
}
