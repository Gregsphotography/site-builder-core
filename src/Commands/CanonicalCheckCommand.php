<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Exchange\Exchange;
use SiteBuilder\Core\Exchange\ExchangeSchema;

final class CanonicalCheckCommand extends Command
{
    protected $signature = 'site-builder:canonical-check {--json}';

    protected $description = 'Read-only numeric preflight of registered content JSON; never rehashes or repairs stored state';

    public function handle(Exchange $exchange, ExchangeSchema $schema, CanonicalJson $json): int
    {
        $report = ['format' => 'laravel-site-builder/canonical-check', 'version' => 1, 'policy' => CanonicalJson::NUMERIC_POLICY, 'status' => 'compatible', 'checkedJsonValues' => 0, 'issues' => []];
        try {
            $json->encode(null);
            $connection = DB::connection();
            if ($connection->getDriverName() === 'pgsql') {
                $read = function () use ($connection, $schema): array {
                    $tables = [];
                    foreach ($schema->tables() as $kind => $definition) {
                        $tables[$kind] = $connection->table($definition['table'])->orderBy('id')->get()->map(fn (object $row): array => $schema->normalize($kind, get_object_vars($row)))->all();
                    }

                    return $tables;
                };
                if ($connection->transactionLevel() > 0) {
                    $tables = $read();
                } else {
                    $connection->beginTransaction();
                    try {
                        $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                        $tables = $read();
                    } finally {
                        $connection->rollBack();
                    }
                }
            } else {
                $tables = $exchange->export($connection, 'canonical-preflight')->tables();
            }
            foreach ($tables as $kind => $rows) {
                foreach ($rows as $row) {
                    foreach ($schema->table($kind)['json'] as $column) {
                        if (($row[$column] ?? null) === null) {
                            continue;
                        }
                        $report['checkedJsonValues']++;
                        try {
                            $json->encode(json_decode($row[$column], flags: JSON_THROW_ON_ERROR));
                        } catch (\InvalidArgumentException|\JsonException) {
                            $report['issues'][] = ['kind' => $kind, 'id' => (string) $row['id'], 'column' => $column, 'code' => 'canonical_value_requires_review'];
                        }
                    }
                }
            }
            if ($report['issues'] !== []) {
                $report['status'] = 'requires-review';
            }
        } catch (\Throwable) {
            $report['status'] = 'unavailable';
            $report['issues'] = [['code' => 'preflight_unavailable', 'action' => 'Check PHP serialize_precision=-1 and access to installed registered schema. No values or connection details are disclosed.']];
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Canonical numeric preflight: '.$report['status'].'. JSON values checked: '.$report['checkedJsonValues'].'. Issues: '.count($report['issues']).'.');
            $this->line('Use --json for private row/column locations. Preserve backups and review affected data explicitly; this command makes no changes.');
        }

        return match ($report['status']) {
            'compatible' => 0, 'requires-review' => 1, default => 2,
        };
    }
}
