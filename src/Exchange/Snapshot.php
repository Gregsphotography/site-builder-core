<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Exchange;

use InvalidArgumentException;
use JsonSerializable;
use Opis\JsonSchema\Validator;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\JsonObject;
use stdClass;

/** JSON columns remain serialized JSON strings; SQL null remains PHP/JSON null. */
final readonly class Snapshot implements JsonSerializable
{
    public const FORMAT = 'laravel-site-builder/export';

    public const VERSION = 3;

    private JsonObject $payload;

    public function __construct(array $source, array $tables, array $provenance = [], array|stdClass $extensions = [], private int $version = self::VERSION)
    {
        if (! in_array($version, [1, 2, self::VERSION], true)) {
            throw new InvalidArgumentException('Unsupported native exchange version.');
        }
        $source['namespace'] ??= '';
        if (! is_string($source['namespace']) || ! preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $source['namespace'])) {
            throw new InvalidArgumentException('A bounded source namespace is required.');
        }
        foreach ($tables as &$rows) {
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100000) {
                throw new InvalidArgumentException('Exchange tables must be bounded row lists.');
            }
            foreach ($rows as &$row) {
                if (! is_array($row) || ! isset($row['id'])) {
                    throw new InvalidArgumentException('Every exchange row requires an identity.');
                }
                $row = (object) $row;
            }
            unset($row);
        }
        unset($rows);
        foreach ($provenance as $kind => $map) {
            if (! array_key_exists($kind, $tables) || ! is_array($map)) {
                throw new InvalidArgumentException('Provenance must refer to registered snapshot rows.');
            }
            foreach ($map as $digest) {
                if (! is_string($digest) || ! preg_match('/^[a-f0-9]{64}$/D', $digest)) {
                    throw new InvalidArgumentException('Provenance digests must be SHA-256 values.');
                }
            }
        }
        $this->payload = new JsonObject(['format' => self::FORMAT, 'version' => $version, 'source' => (object) $source, 'tables' => (object) $tables, 'provenance' => (object) array_map(fn (array $map): stdClass => (object) $map, $provenance), 'extensions' => (object) $extensions]);
    }

    public static function decode(string $json): self
    {
        if (strlen($json) > 64 * 1024 * 1024) {
            throw new InvalidArgumentException('The exchange file exceeds the 64 MiB limit.');
        }
        $data = json_decode($json, flags: JSON_THROW_ON_ERROR);
        $version = $data instanceof stdClass ? ($data->version ?? null) : null;
        if (! in_array($version, [1, 2, self::VERSION], true)) {
            throw new InvalidArgumentException('Unsupported native exchange format/version.');
        }
        $schema = json_decode(file_get_contents(__DIR__.'/../../resources/schema/export-v'.$version.'.schema.json'), flags: JSON_THROW_ON_ERROR);
        if (! (new Validator)->validate($data, $schema)->isValid()) {
            throw new InvalidArgumentException('The native exchange envelope does not conform to export-v'.$version.'.schema.json.');
        }
        if (! $data instanceof stdClass || ($data->format ?? null) !== self::FORMAT || ! ($data->source ?? null) instanceof stdClass || ! ($data->tables ?? null) instanceof stdClass || ! ($data->extensions ?? new stdClass) instanceof stdClass) {
            throw new InvalidArgumentException('Unsupported or malformed native exchange format/version.');
        }
        if (array_diff(array_keys(get_object_vars($data)), ['format', 'version', 'source', 'tables', 'provenance', 'extensions']) !== []) {
            throw new InvalidArgumentException('Unknown top-level exchange fields require an explicit format migration.');
        }
        $tables = [];
        foreach ($data->tables as $kind => $rows) {
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100000) {
                throw new InvalidArgumentException('Exchange tables must be bounded lists of rows.');
            }
            $tables[$kind] = array_map(fn (mixed $row): array => $row instanceof stdClass ? get_object_vars($row) : throw new InvalidArgumentException('Exchange rows must be objects.'), $rows);
        }
        $provenance = [];
        foreach ($data->provenance ?? new stdClass as $kind => $map) {
            if (! $map instanceof stdClass) {
                throw new InvalidArgumentException('Invalid source provenance map.');
            }
            $provenance[$kind] = get_object_vars($map);
        }

        return new self(get_object_vars($data->source), $tables, $provenance, $data->extensions ?? new stdClass, $version);
    }

    public function source(): array
    {
        return get_object_vars($this->payload->get('source'));
    }

    public function version(): int
    {
        return $this->version;
    }

    public function tables(): array
    {
        $tables = [];
        foreach ($this->payload->get('tables') as $kind => $rows) {
            $tables[$kind] = array_map(function (stdClass $row) use ($kind): array {
                $values = get_object_vars($row);
                if ($this->version === 1 && $kind === 'revisions') {
                    $values += ['created_by_type' => null];
                }

                return $values;
            }, $rows);
        }

        return $tables;
    }

    public function provenance(string $kind, string $id): ?string
    {
        return $this->payload->get('provenance')->{$kind}->{$id} ?? null;
    }

    public function extensions(): stdClass
    {
        return $this->payload->get('extensions');
    }

    public function fingerprint(ExchangeSchema $schema, CanonicalJson $json): string
    {
        $digests = [];
        foreach ($this->tables() as $kind => $rows) {
            foreach ($rows as $row) {
                $digests[$kind][(string) $row['id']] = $schema->digest($kind, $row);
            }
        }
        $source = $this->source();
        unset($source['captured_at']);

        return $json->digest(['format' => self::FORMAT, 'version' => $this->version, 'source' => $source, 'rows' => $digests, 'extensions' => $this->extensions()]);
    }

    public function jsonSerialize(): stdClass
    {
        return $this->payload->toObject();
    }

    public function encode(): string
    {
        return json_encode($this, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
