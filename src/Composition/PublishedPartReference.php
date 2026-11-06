<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use stdClass;

final readonly class PublishedPartReference implements JsonSerializable
{
    public function __construct(public string $resourceId, public string $revisionId, public string $digest)
    {
        if (! Str::isUuid($resourceId) || ! Str::isUuid($revisionId) || ! preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            throw new InvalidArgumentException('Published site-part references require exact resource/revision UUIDs and a SHA-256 digest.');
        }
    }

    public static function from(mixed $value): self
    {
        if (is_array($value)) {
            $value = (object) $value;
        }
        if (! $value instanceof stdClass || array_diff(array_keys(get_object_vars($value)), ['resourceId', 'revisionId', 'digest']) !== [] || ! is_string($value->resourceId ?? null) || ! is_string($value->revisionId ?? null) || ! is_string($value->digest ?? null)) {
            throw new InvalidArgumentException('Malformed immutable site-part reference.');
        }

        return new self($value->resourceId, $value->revisionId, $value->digest);
    }

    public function jsonSerialize(): stdClass
    {
        return (object) ['resourceId' => $this->resourceId, 'revisionId' => $this->revisionId, 'digest' => $this->digest];
    }
}
