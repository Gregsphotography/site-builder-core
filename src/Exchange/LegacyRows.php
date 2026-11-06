<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Exchange;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use Opis\JsonSchema\Validator;
use SiteBuilder\Core\Content\DocumentCodec;

final class LegacyRows
{
    public const FORMAT = 'laravel-site-builder/legacy-rows';

    private const TABLES = ['pages' => 'atelier_pages', 'slugs' => 'atelier_page_slugs', 'redirects' => 'atelier_page_redirects', 'revisions' => 'atelier_page_revisions', 'settings' => 'atelier_settings', 'menus' => 'atelier_menus'];

    private ?string $legacyActorType = null;

    public function __construct(private ExchangeSchema $schema, private LegacyDocuments $documents, private DocumentCodec $codec) {}

    /** Explicit host assertion: this one model is the legacy numeric actor domain. */
    public function allowActorType(string $type): self
    {
        if (strlen($type) > 255 || ! preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $type) || ($this->legacyActorType !== null && $this->legacyActorType !== $type)) {
            throw new InvalidArgumentException('Legacy reverse conversion can map exactly one explicit numeric actor model.');
        }
        $this->legacyActorType = $type;

        return $this;
    }

    /** SELECT-only capture; no legacy models, user tables or application bootstrap are involved. */
    public function capture(Connection $connection, string $namespace): string
    {
        if ($connection->transactionLevel() !== 0) {
            throw new InvalidArgumentException('Capture needs its own consistent read transaction.');
        }
        if ($connection->getDriverName() === 'mysql') {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $connection->statement('SET TRANSACTION READ ONLY');
        }
        $connection->beginTransaction();
        try {
            $tables = [];
            $columns = [];
            foreach ($this->schema->tables() as $kind => $definition) {
                $table = self::TABLES[$kind] ?? $definition['table'];
                $columns[$table] = $connection->getSchemaBuilder()->getColumnListing($table);
                $tables[$table] = $connection->table($table)->orderBy('id')->get()->map(fn (object $row): array => get_object_vars($row))->all();
            }
            $source = ['namespace' => $namespace, 'captured_at' => now()->toAtomString(), 'application_name' => config('app.name'), 'application_timezone' => config('app.timezone'), 'locales' => array_keys(config('site-builder.locales')), 'connection_fingerprint' => ConnectionIdentity::fingerprint($connection)];
            if ($connection->getDriverName() === 'mysql') {
                $source['database'] = (array) $connection->selectOne('SELECT VERSION() AS version, DATABASE() AS schema_name, @@server_uuid AS server_uuid, @@session.time_zone AS session_timezone, @@system_time_zone AS system_timezone');
            }

            return json_encode(['format' => self::FORMAT, 'version' => 1, 'source' => $source, 'columns' => $columns, 'tables' => $tables], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        } finally {
            $connection->rollBack();
        }
    }

    public function convert(string $json): Snapshot
    {
        if (strlen($json) > 64 * 1024 * 1024) {
            throw new InvalidArgumentException('The legacy row envelope exceeds the 64 MiB limit.');
        }
        $envelope = json_decode($json, flags: JSON_THROW_ON_ERROR);
        $format = json_decode(file_get_contents(__DIR__.'/../../resources/schema/legacy-rows-v1.schema.json'), flags: JSON_THROW_ON_ERROR);
        if (! (new Validator)->validate($envelope, $format)->isValid()) {
            throw new InvalidArgumentException('Unsupported or malformed legacy-rows-v1 envelope.');
        }
        $input = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (($input['format'] ?? null) !== self::FORMAT || ($input['version'] ?? null) !== 1 || ! is_array($input['tables'] ?? null) || ! is_array($input['source'] ?? null)) {
            throw new InvalidArgumentException('Unsupported legacy row format/version.');
        }
        $tables = [];
        $provenance = [];
        $allowed = [];
        foreach ($this->schema->tables() as $kind => $definition) {
            $legacyTable = self::TABLES[$kind] ?? $definition['table'];
            $allowed[] = $legacyTable;
            if (! array_key_exists($legacyTable, $input['tables'])) {
                throw new InvalidArgumentException('The source snapshot is missing a declared table: '.$legacyTable);
            }
            if (isset($input['columns'][$legacyTable])) {
                $columns = array_map(fn (mixed $column): string => is_array($column) ? $column['Field'] : $column, $input['columns'][$legacyTable]);
                $nativeOnly = match ($kind) {
                    'pages' => ['write_version', 'published_from_version'],
                    'revisions' => ['scope', 'origin', 'published_from_version', 'created_by_type'],
                    'settings' => ['namespace', 'revision'],
                    'menus' => ['revision'],
                    default => [],
                };
                $expected = array_diff($definition['columns'], $nativeOnly);
                if (array_diff($columns, $expected) !== [] || array_diff($expected, $columns) !== []) {
                    throw new InvalidArgumentException('Unreviewed legacy schema columns in '.$legacyTable.'.');
                }
            }
            $tables[$kind] = [];
            foreach ($input['tables'][$legacyTable] as $row) {
                $original = $row;
                $provenance[$kind][(string) $row['id']] = $this->schema->digest($kind, $original);
                if ($kind === 'pages') {
                    foreach (['draft_content', 'published_content'] as $column) {
                        $document = $this->documents->decode($row[$column]);
                        $row[$column] = $document === null ? null : $this->codec->encode($document);
                    }
                    $row['write_version'] = '0';
                    $row['published_from_version'] = null;
                } elseif ($kind === 'revisions') {
                    $document = $this->documents->decode($row['content']);
                    $row['content'] = $document === null ? null : $this->codec->encode($document);
                    $row += ['scope' => 'content', 'origin' => 'legacy', 'published_from_version' => null, 'created_by_type' => null];
                } elseif ($kind === 'settings') {
                    $row += ['namespace' => 'site', 'revision' => '0'];
                } elseif ($kind === 'menus') {
                    $row += ['revision' => '0'];
                }
                $tables[$kind][] = $this->schema->normalize($kind, $row);
            }
        }
        if (array_diff(array_keys($input['tables']), $allowed) !== []) {
            throw new InvalidArgumentException('Undeclared source tables must be reviewed; users, leases and business records are never inferred.');
        }
        $source = $input['source'];
        $source['mapper_version'] = 1;
        if (isset($source['database']['server_uuid'], $source['database']['schema_name'])) {
            $source['connection_fingerprint'] = hash('sha256', json_encode(['mysql', $source['database']['server_uuid'], $source['database']['schema_name']], JSON_THROW_ON_ERROR));
        }

        return new Snapshot($source, $tables, $provenance, (object) ($input['extensions'] ?? []));
    }

    /** Builder rows only. Host facts remain in their existing tables. */
    public function reverse(Snapshot $snapshot): array
    {
        if (get_object_vars($snapshot->extensions()) !== []) {
            throw new InvalidArgumentException('Snapshot extensions have no lossless legacy representation.');
        }
        $result = [];
        foreach ($snapshot->tables() as $kind => $rows) {
            if (in_array($kind, ['saved_sections', 'saved_section_versions'], true)) {
                throw new InvalidArgumentException('The optional saved-section dataset has no lossless legacy representation. Use its native v3 backup.');
            }
            $result[self::TABLES[$kind] ?? $this->schema->table($kind)['table']] = array_map(function (array $row) use ($kind): array {
                if ($kind === 'pages') {
                    foreach (['draft_content', 'published_content'] as $column) {
                        $row[$column] = $row[$column] === null ? null : $this->documents->encode($this->codec->decode($row[$column]));
                    }
                    unset($row['write_version'], $row['published_from_version']);
                } elseif ($kind === 'revisions') {
                    $author = (string) $row['created_by'];
                    if ((($row['created_by_type'] ?? null) !== null && $row['created_by_type'] !== $this->legacyActorType) || ($row['created_by'] !== null && (! preg_match('/^(0|[1-9][0-9]{0,19})$/D', $author) || (strlen($author) === 20 && strcmp($author, '18446744073709551615') > 0)))) {
                        throw new InvalidArgumentException('Opaque or model-scoped authorship has no lossless legacy representation. Review an explicit rollback mapping.');
                    }
                    $row['content'] = $row['content'] === null ? null : $this->documents->encode($this->codec->decode($row['content']));
                    unset($row['scope'], $row['origin'], $row['published_from_version'], $row['created_by_type']);
                } elseif ($kind === 'settings') {
                    if ($row['namespace'] !== 'site') {
                        throw new InvalidArgumentException('An additional settings namespace has no legacy table representation.');
                    }
                    unset($row['namespace'], $row['revision']);
                } elseif ($kind === 'menus') {
                    unset($row['revision']);
                }

                return $row;
            }, $rows);
        }

        return $result;
    }
}
