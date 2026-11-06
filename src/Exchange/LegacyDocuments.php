<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Exchange;

use InvalidArgumentException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use stdClass;

/** Version-1 legacy row adapter; never instantiates or imports legacy package classes. */
final class LegacyDocuments
{
    private array $nativeTypes = [];

    public function __construct(private ComponentRegistry $components, private DocumentCodec $codec) {}

    /** Explicitly declare types implemented by both the native host and retained legacy artifact. */
    public function allowNativeTypes(array $types): void
    {
        foreach ($types as $type) {
            if (! is_string($type) || $this->components->resolve($type) === null) {
                throw new InvalidArgumentException('Reverse adapters require explicitly registered component types.');
            }
            $this->nativeTypes[$type] = true;
        }
    }

    public function decode(?string $json): ?Document
    {
        if ($json === null) {
            return null;
        }
        $tree = json_decode($json, flags: JSON_THROW_ON_ERROR);
        if (! is_array($tree) || ! array_is_list($tree)) {
            throw new InvalidArgumentException('A nonnull legacy document must be an ordered node list. The raw source is retained.');
        }
        $document = new Document(array_map($this->node(...), $tree));
        $this->codec->encode($document);

        return $document;
    }

    private function node(mixed $source): Node
    {
        if (! $source instanceof stdClass || ! is_string($source->id ?? null) || ! is_string($source->type ?? null)) {
            throw new InvalidArgumentException('Legacy nodes require stable string IDs and types; identities are never repaired implicitly.');
        }
        $marker = ['mapped' => true, 'attributes_shape' => property_exists($source, 'attributes') ? ($source->attributes === null ? 'null' : ($source->attributes === [] ? 'empty-list' : 'object')) : 'missing',
            'children_shape' => property_exists($source, 'children') ? ($source->children === null ? 'null' : 'list') : 'missing'];
        $attributes = $source->attributes ?? new stdClass;
        if ($attributes === []) {
            $attributes = new stdClass;
        }
        if (! $attributes instanceof stdClass || (isset($source->children) && (! is_array($source->children) || ! array_is_list($source->children)))) {
            throw new InvalidArgumentException('Ambiguous legacy attributes/children require an explicit converter.');
        }
        if (property_exists($source, 'hidden')) {
            if (! is_bool($source->hidden)) {
                throw new InvalidArgumentException('Legacy visibility must be a boolean when present.');
            }
            $marker['hidden_present'] = true;
        }
        $extra = array_diff_key(get_object_vars($source), array_flip(['id', 'type', 'attributes', 'children', 'hidden']));
        if ($extra !== []) {
            $marker['node'] = (object) $extra;
        }
        $properties = $this->components->resolve($source->type)?->properties() ?? [];
        $props = [];
        $translations = [];
        foreach ($attributes as $key => $value) {
            if (($properties[$key]->translated ?? false) && $value instanceof stdClass && ! isset($value->type)) {
                $keys = array_keys(get_object_vars($value));
                if ($keys === []) {
                    $marker['empty_translation_maps'][] = $key;

                    continue;
                }
                if (array_filter($keys, fn (string $locale): bool => ! preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})?$/D', $locale)) !== []) {
                    throw new InvalidArgumentException('A translated property has ambiguous locale keys. Its raw source is retained.');
                }
                foreach ($value as $locale => $translated) {
                    $translations[$locale] ??= new stdClass;
                    $translations[$locale]->{$key} = $translated;
                }
            } else {
                $props[$key] = $value;
            }
        }

        return new Node($source->id, $source->type, 1, new JsonObject($props), new JsonObject($translations),
            ($source->hidden ?? false) ? 'hidden' : 'visible', array_map($this->node(...), $source->children ?? []), new JsonObject(['legacy.atelier' => (object) $marker]));
    }

    public function encode(?Document $document): ?string
    {
        if ($document === null) {
            return null;
        }
        if (get_object_vars($document->extensions->toObject()) !== []) {
            throw new InvalidArgumentException('Document extensions have no proven lossless legacy representation.');
        }
        $walk = function (Node $node) use (&$walk): stdClass {
            $extension = $node->extensions->toObject();
            if ($node->version !== 1 || array_diff(array_keys(get_object_vars($extension)), ['legacy.atelier']) !== []) {
                throw new InvalidArgumentException('Unsupported native node versions/extensions cannot be downgraded.');
            }
            $marker = $extension->{'legacy.atelier'} ?? new stdClass;
            if (! ($marker->mapped ?? false) && ! isset($this->nativeTypes[$node->type])) {
                throw new InvalidArgumentException('A native component requires an explicit reverse adapter implemented by the retained legacy artifact.');
            }
            $attributes = $node->props->toObject();
            $baseKeys = array_keys(get_object_vars($attributes));
            foreach ($node->translations->toObject() as $locale => $values) {
                foreach ($values as $key => $value) {
                    if (in_array($key, $baseKeys, true)) {
                        throw new InvalidArgumentException('Mixed base and translated values need an explicit lossless reverse converter.');
                    }
                    $attributes->{$key} ??= new stdClass;
                    $attributes->{$key}->{$locale} = $value;
                }
            }
            foreach ($marker->empty_translation_maps ?? [] as $key) {
                if (! property_exists($attributes, $key)) {
                    $attributes->{$key} = new stdClass;
                }
            }
            $result = clone ($marker->node ?? new stdClass);
            $result->id = $node->id;
            $result->type = $node->type;
            $shape = $marker->attributes_shape ?? 'object';
            if (get_object_vars($attributes) !== [] || $shape === 'object') {
                $result->attributes = $attributes;
            } elseif ($shape === 'null') {
                $result->attributes = null;
            } elseif ($shape === 'empty-list') {
                $result->attributes = [];
            }
            $children = array_map($walk, $node->children);
            if ($children !== [] || ($marker->children_shape ?? 'list') === 'list') {
                $result->children = $children;
            } elseif (($marker->children_shape ?? '') === 'null') {
                $result->children = null;
            }
            if ($node->visibility === 'hidden' || ($marker->hidden_present ?? false)) {
                $result->hidden = $node->visibility === 'hidden';
            }

            return $result;
        };

        return json_encode(array_map($walk, $document->nodes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
