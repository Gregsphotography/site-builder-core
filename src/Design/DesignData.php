<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Design;

use InvalidArgumentException;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use stdClass;

final class DesignData
{
    public static function payload(JsonObject $extensions): ?stdClass
    {
        $envelope = $extensions->get('site-builder.render');

        return $envelope instanceof stdClass && ($envelope->version ?? null) === 1 && ($envelope->design ?? null) instanceof stdClass ? $envelope->design : null;
    }

    public static function nodes(Document $document): array
    {
        $nodes = [];
        $walk = function (array $items) use (&$walk, &$nodes): void {
            foreach ($items as $node) {
                $nodes[$node->id] = $node;
                $walk($node->children);
            }
        };
        $walk($document->nodes);

        return $nodes;
    }

    public static function find(Document $document, string $id): Node
    {
        return self::nodes($document)[$id] ?? throw new InvalidArgumentException('The selected design node is unavailable.');
    }

    public static function extensions(JsonObject $old, ?stdClass $design): JsonObject
    {
        $values = $old->toObject();
        $envelope = $values->{'site-builder.render'} ?? (object) ['version' => 1];
        if (! $envelope instanceof stdClass || ($envelope->version ?? null) !== 1) {
            throw new InvalidArgumentException('Unavailable render envelopes are read-only.');
        }
        if ($design === null) {
            unset($envelope->design);
        } else {
            $envelope->design = $design;
        }
        if (array_keys(get_object_vars($envelope)) === ['version']) {
            unset($values->{'site-builder.render'});
        } else {
            $values->{'site-builder.render'} = $envelope;
        }

        return new JsonObject($values);
    }

    public static function replace(Document $document, ?string $nodeId, ?stdClass $design): Document
    {
        if ($nodeId === null) {
            return new Document($document->nodes, self::extensions($document->extensions, $design));
        }
        self::find($document, $nodeId);
        $walk = function (array $nodes) use (&$walk, $nodeId, $design): array {
            return array_map(fn (Node $node): Node => new Node($node->id, $node->type, $node->version, $node->props, $node->translations, $node->visibility, $walk($node->children), $node->id === $nodeId ? self::extensions($node->extensions, $design) : $node->extensions), $nodes);
        };

        return new Document($walk($document->nodes), $document->extensions);
    }

    public static function shape(mixed $payload, bool $node): bool
    {
        if (! $payload instanceof stdClass || ($payload->version ?? null) !== 1 || array_diff(array_keys(get_object_vars($payload)), $node ? ['version', 'base', 'responsive'] : ['version', 'theme', 'base', 'responsive']) !== []) {
            return false;
        }
        if (! $node) {
            $theme = $payload->theme ?? null;
            if (! $theme instanceof stdClass || array_diff(array_keys(get_object_vars($theme)), ['id', 'version', 'preset']) !== [] || ! is_string($theme->id ?? null) || ! is_int($theme->version ?? null) || ! is_string($theme->preset ?? null)) {
                return false;
            }
            try {
                new ThemeIdentity($theme->id, $theme->version, $theme->preset);
            } catch (InvalidArgumentException) {
                return false;
            }
        }
        $maps = [];
        if (property_exists($payload, 'base')) {
            $maps[] = $payload->base;
        }
        if (property_exists($payload, 'responsive')) {
            if (! $payload->responsive instanceof stdClass) {
                return false;
            }
            foreach ($payload->responsive as $breakpoint => $map) {
                if (! preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $breakpoint) || $breakpoint === 'base') {
                    return false;
                }
                $maps[] = $map;
            }
        }
        foreach ($maps as $map) {
            if (! $map instanceof stdClass) {
                return false;
            }
            foreach ($map as $key => $value) {
                if (! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $key) || ! is_string($value) || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $value)) {
                    return false;
                }
            }
        }

        return true;
    }
}
