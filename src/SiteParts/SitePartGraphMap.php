<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use InvalidArgumentException;
use SiteBuilder\Core\Content\CanonicalJson;

/** Explicit code-driven mapping coordinates, never an authorization capability. */
final readonly class SitePartGraphMap
{
    private function __construct(private array $identities, private array $occurrences) {}

    public static function fromArray(array $identities, array $occurrences = []): self
    {
        $maps = [];
        $total = 0;
        foreach ($identities as $kind => $entries) {
            if (! is_string($kind) || ! preg_match('/^[a-z][a-z0-9_.-]{0,31}$/D', $kind) || ! is_array($entries) || ! array_is_list($entries)) {
                throw new InvalidArgumentException('Graph identities require named dataset kinds and explicit source/target lists.');
            }
            $maps[$kind] = [];
            $targets = [];
            foreach ($entries as $entry) {
                if (++$total > 100000 || ! is_array($entry) || array_diff(array_keys($entry), ['source', 'target']) !== [] || array_diff(['source', 'target'], array_keys($entry)) !== [] || (! is_string($entry['source']) && ! is_int($entry['source'])) || (! is_string($entry['target']) && ! is_int($entry['target']))) {
                    throw new InvalidArgumentException('Graph identity maps must be bounded explicit source/target pairs.');
                }
                $source = (string) $entry['source'];
                $target = (string) $entry['target'];
                if ($source === '' || $target === '' || strlen($source) > 191 || strlen($target) > 191 || isset($maps[$kind][$source]) || isset($targets[$target])) {
                    throw new InvalidArgumentException('Each typed identity map must be one-to-one without repeated sources/targets.');
                }
                $maps[$kind][$source] = $target;
                $targets[$target] = true;
            }
        }
        if (! array_is_list($occurrences) || count($occurrences) > 10000) {
            throw new InvalidArgumentException('Graph occurrence choices must be a bounded list.');
        }
        $choices = [];
        foreach ($occurrences as $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), ['document', 'nodeId', 'path', 'kind', 'original', 'value']) !== [] || array_diff(['document', 'nodeId', 'path', 'kind', 'original', 'value'], array_keys($entry)) !== [] || ! is_string($entry['document']) || strlen($entry['document']) > 512 || ! is_string($entry['nodeId']) || strlen($entry['nodeId']) > 128 || ! is_string($entry['path']) || strlen($entry['path']) > 4096 || ! in_array($entry['kind'], ['page', 'media', 'revision'], true)) {
                throw new InvalidArgumentException('A graph choice must identify one exact typed document occurrence.');
            }
            $key = self::key($entry['document'], $entry['nodeId'], $entry['path']);
            if (isset($choices[$key])) {
                throw new InvalidArgumentException('Graph occurrence choices cannot repeat.');
            }
            $choices[$key] = $entry;
        }

        return new self($maps, $choices);
    }

    public function identities(): array
    {
        return $this->identities;
    }

    public function target(string $kind, int|string $source): string
    {
        return $this->identities[$kind][(string) $source] ?? throw new InvalidArgumentException('Every captured identity needs an explicit typed mapping.');
    }

    public function choice(string $document, string $nodeId, string $path): array
    {
        return $this->occurrences[self::key($document, $nodeId, $path)] ?? throw new InvalidArgumentException('Every declared reference occurrence needs an explicit graph choice.');
    }

    public function occurrences(): array
    {
        return $this->occurrences;
    }

    public function toArray(): array
    {
        $identities = [];
        foreach ($this->identities as $kind => $entries) {
            $identities[$kind] = [];
            foreach ($entries as $source => $target) {
                $identities[$kind][] = ['source' => (string) $source, 'target' => $target];
            }
        }

        return ['identities' => $identities, 'occurrences' => array_values($this->occurrences)];
    }

    public static function key(string $document, string $nodeId, string $path): string
    {
        return (new CanonicalJson)->digest([$document, $nodeId, $path]);
    }
}
