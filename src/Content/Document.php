<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Content;

use InvalidArgumentException;
use JsonSerializable;
use stdClass;

final readonly class Document implements JsonSerializable
{
    public const string FORMAT = 'laravel-site-builder/document';

    public const int VERSION = 1;

    /** @param list<Node> $nodes */
    public function __construct(public array $nodes = [], public JsonObject $extensions = new JsonObject)
    {
        if (! array_is_list($nodes) || array_any($nodes, fn (mixed $node): bool => ! $node instanceof Node)) {
            throw new InvalidArgumentException('Nodes must be an ordered list.');
        }
    }

    public function jsonSerialize(): stdClass
    {
        return (object) ['format' => self::FORMAT, 'version' => self::VERSION, 'nodes' => $this->nodes, 'extensions' => $this->extensions->toObject()];
    }
}
