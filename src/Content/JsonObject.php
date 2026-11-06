<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Content;

use JsonSerializable;
use stdClass;

/** An immutable JSON object; reads return fresh copies, including nested values. */
final readonly class JsonObject implements JsonSerializable
{
    private string $json;

    public function __construct(array|stdClass $values = [])
    {
        $this->json = json_encode((object) $values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function toObject(): stdClass
    {
        return json_decode($this->json, flags: JSON_THROW_ON_ERROR);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->toObject();

        return property_exists($values, $key) ? $values->{$key} : $default;
    }

    public function jsonSerialize(): stdClass
    {
        return $this->toObject();
    }
}
