<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use InvalidArgumentException;

final readonly class ReferenceMap
{
    private function __construct(private array $entries) {}

    public static function fromArray(array $entries): self
    {
        if (! array_is_list($entries) || count($entries) > 10000) {
            throw new InvalidArgumentException('Reference mappings must be a bounded occurrence list.');
        }
        $indexed = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), ['nodeId', 'path', 'kind', 'original', 'value', 'keepUnverified']) !== [] || array_diff(['nodeId', 'path', 'kind', 'original', 'value'], array_keys($entry)) !== [] || ! is_string($entry['nodeId']) || ! is_string($entry['path']) || ! in_array($entry['kind'], ['page', 'media', 'revision'], true) || (isset($entry['keepUnverified']) && ! is_bool($entry['keepUnverified']))) {
                throw new InvalidArgumentException('Each reference mapping must identify an exact declared occurrence.');
            }
            if ($entry['kind'] === 'revision') {
                $entry['original'] = PublishedPartReference::from($entry['original'])->jsonSerialize();
                $entry['value'] = $entry['value'] === null ? null : PublishedPartReference::from($entry['value'])->jsonSerialize();
            }
            $key = self::key($entry['nodeId'], $entry['path']);
            if (isset($indexed[$key])) {
                throw new InvalidArgumentException('Reference mappings cannot repeat an occurrence.');
            }
            $indexed[$key] = $entry;
        }

        return new self($indexed);
    }

    public static function key(string $nodeId, string $path): string
    {
        return hash('sha256', json_encode([$nodeId, $path], JSON_THROW_ON_ERROR));
    }

    public function entry(string $nodeId, string $path): ?array
    {
        return $this->entries[self::key($nodeId, $path)] ?? null;
    }

    public function all(): array
    {
        return $this->entries;
    }
}
