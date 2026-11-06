<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Snapshots;

use InvalidArgumentException;

final readonly class SnapshotSelection implements \JsonSerializable
{
    public function __construct(public string $provider, public string $sourceId, public string $sourceVersion, public string $digest, public string $locale, public SnapshotDestination $destination, public int $expectedWriteVersion)
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $provider) || $sourceId === '' || strlen($provider.':'.$sourceId) > 191 || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,190}$/D', $sourceVersion) || ! preg_match('/^[a-f0-9]{64}$/D', $digest) || ! preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,11}$/D', $locale) || $expectedWriteVersion < 0) {
            throw new InvalidArgumentException('Pin a bounded snapshot source, locale, destination and owner version.');
        }
    }

    public static function fromArray(array $value): self
    {
        if (array_diff(array_keys($value), ['format', 'version', 'provider', 'sourceId', 'sourceVersion', 'digest', 'locale', 'destination', 'expectedWriteVersion']) !== [] || count($value) !== 9 || ($value['format'] ?? null) !== 'laravel-site-builder/snapshot-selection' || ($value['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported snapshot selection.');
        }
        $destination = $value['destination'] ?? null;
        if (! is_array($destination) || count($destination) !== 3 || array_diff(array_keys($destination), ['kind', 'id', 'nodeId']) !== [] || ! is_string($destination['kind'] ?? null) || (! is_int($destination['id'] ?? null) && ! is_string($destination['id'] ?? null)) || ! is_string($destination['nodeId'] ?? null) || ! is_int($value['expectedWriteVersion'] ?? null)) {
            throw new InvalidArgumentException('Invalid snapshot destination or owner version.');
        }
        foreach (['provider', 'sourceId', 'sourceVersion', 'digest', 'locale'] as $key) {
            if (! is_string($value[$key] ?? null)) {
                throw new InvalidArgumentException('Invalid snapshot selection value.');
            }
        }

        return new self($value['provider'], $value['sourceId'], $value['sourceVersion'], $value['digest'], $value['locale'], new SnapshotDestination(...$destination), $value['expectedWriteVersion']);
    }

    public function jsonSerialize(): array
    {
        return ['format' => 'laravel-site-builder/snapshot-selection', 'version' => 1, 'provider' => $this->provider, 'sourceId' => $this->sourceId, 'sourceVersion' => $this->sourceVersion, 'digest' => $this->digest, 'locale' => $this->locale, 'destination' => $this->destination->jsonSerialize(), 'expectedWriteVersion' => $this->expectedWriteVersion];
    }
}
