<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Content;

use InvalidArgumentException;
use JsonSerializable;
use stdClass;

final readonly class Node implements JsonSerializable
{
    /** @param list<Node> $children */
    public function __construct(
        public string $id,
        public string $type,
        public int $version = 1,
        public JsonObject $props = new JsonObject,
        public JsonObject $translations = new JsonObject,
        public string $visibility = 'visible',
        public array $children = [],
        public JsonObject $extensions = new JsonObject,
    ) {
        if (! array_is_list($children) || array_any($children, fn (mixed $child): bool => ! $child instanceof self)) {
            throw new InvalidArgumentException('Children must be an ordered list of nodes.');
        }
    }

    public function jsonSerialize(): stdClass
    {
        return (object) [
            'id' => $this->id, 'type' => $this->type, 'version' => $this->version,
            'props' => $this->props->toObject(), 'translations' => $this->translations->toObject(),
            'visibility' => $this->visibility, 'children' => $this->children,
            'extensions' => $this->extensions->toObject(),
        ];
    }
}
