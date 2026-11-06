<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Exchange;

use Closure;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use SiteBuilder\Core\Content\CanonicalJson;

final class ExchangeSchema
{
    private array $tables = [];

    /** @var list<Closure> */
    private array $inspectors = [];

    private array $planningScopes = [];

    private array $insertPreparers = [];

    private array $afterRows = [];

    public function __construct(private CanonicalJson $json)
    {
        $this->register('pages', 'site_builder_pages', ['id', 'title', 'type', 'status', 'layout', 'draft_content', 'published_content', 'seo', 'schema', 'data', 'published_at', 'write_version', 'published_from_version', 'created_at', 'updated_at'], ['draft_content', 'published_content', 'seo', 'schema', 'data'], ['id', 'write_version', 'published_from_version']);
        $this->register('slugs', 'site_builder_page_slugs', ['id', 'page_id', 'locale', 'slug', 'created_at', 'updated_at'], [], ['id', 'page_id'], [['locale', 'slug'], ['page_id', 'locale']]);
        $this->register('redirects', 'site_builder_page_redirects', ['id', 'locale', 'from_slug', 'page_id', 'status', 'created_at', 'updated_at'], [], ['id', 'page_id', 'status'], [['locale', 'from_slug']]);
        $this->register('revisions', 'site_builder_page_revisions', ['id', 'page_id', 'scope', 'content', 'created_by', 'created_by_type', 'label', 'created_at', 'published_from_version', 'origin'], ['content'], ['id', 'page_id', 'published_from_version']);
        $this->register('settings', 'site_builder_settings', ['id', 'namespace', 'data', 'revision', 'created_at', 'updated_at'], ['data'], ['id', 'revision'], [['namespace']]);
        $this->register('menus', 'site_builder_menus', ['id', 'location', 'items', 'revision', 'created_at', 'updated_at'], ['items'], ['id', 'revision'], [['location']]);
    }

    public function register(string $kind, string $table, array $columns, array $json = [], array $integers = [], array $unique = [], string $identity = 'bigint'): self
    {
        if (! preg_match('/^[a-z][a-z0-9_.-]{0,31}$/D', $kind) || isset($this->tables[$kind])) {
            throw new InvalidArgumentException('Exchange kinds must be unique, bounded identifiers.');
        }
        foreach ([$table, ...$columns] as $identifier) {
            if (! preg_match('/^[a-z][a-z0-9_]*$/D', $identifier)) {
                throw new InvalidArgumentException('Exchange table/column identifiers must be declared by trusted host code.');
            }
        }
        if (! in_array($identity, ['bigint', 'uuid'], true) || ! in_array('id', $columns, true) || array_diff([...$json, ...$integers, ...array_merge([], ...$unique)], $columns) !== []) {
            throw new InvalidArgumentException('Exchange columns and constraints must be explicit.');
        }
        $this->tables[$kind] = compact('table', 'columns', 'json', 'integers', 'unique', 'identity');

        return $this;
    }

    /** Add host page columns without teaching core their business meaning. */
    public function extendPages(array $columns, array $json = [], array $integers = [], array $unique = []): self
    {
        $page = $this->tables['pages'];
        if (array_intersect($columns, $page['columns']) !== []) {
            throw new InvalidArgumentException('Host extensions cannot replace core page columns.');
        }
        unset($this->tables['pages']);
        $rest = $this->tables;
        $this->tables = [];
        $this->register('pages', $page['table'], [...$page['columns'], ...$columns], [...$page['json'], ...$json], [...$page['integers'], ...$integers], [...$page['unique'], ...$unique]);
        $this->tables += $rest;

        return $this;
    }

    public function tables(): array
    {
        return $this->tables;
    }

    public function table(string $kind): array
    {
        return $this->tables[$kind] ?? throw new InvalidArgumentException('Unregistered exchange kind.');
    }

    public function inspectUsing(Closure $inspector): void
    {
        $this->inspectors[] = $inspector;
    }

    /** Trusted scopes may resolve incoming immutable graphs without database fallback. */
    public function aroundPlanningUsing(Closure $scope): void
    {
        $this->planningScopes[] = $scope;
    }

    public function planning(Snapshot $snapshot, Connection $target, bool $lock, Closure $plan): array
    {
        foreach (array_reverse($this->planningScopes) as $scope) {
            $next = $plan;
            $plan = fn (): array => $scope($snapshot, $target, $lock, $next);
        }

        return $plan();
    }

    /** Only code-registered adapters can stage circular pointers. Final rows must match exactly. */
    public function prepareInsertUsing(Closure $prepare): void
    {
        $this->insertPreparers[] = $prepare;
    }

    public function prepareInsert(string $kind, array $row, Snapshot $snapshot, Connection $target): array
    {
        foreach ($this->insertPreparers as $prepare) {
            $row = $prepare($kind, $row, $snapshot, $target);
        }

        return $row;
    }

    public function afterRowsUsing(Closure $callback): void
    {
        $this->afterRows[] = $callback;
    }

    public function afterRows(Snapshot $snapshot, Connection $target, array $report): void
    {
        foreach ($this->afterRows as $callback) {
            $callback($snapshot, $target, $report);
        }
    }

    public function inspect(Snapshot $snapshot): array
    {
        $result = ['diagnostics' => [], 'metrics' => []];
        foreach ($this->inspectors as $inspector) {
            $inspection = $inspector($snapshot);
            $result['diagnostics'] = [...$result['diagnostics'], ...($inspection['diagnostics'] ?? [])];
            $result['metrics'] = array_replace_recursive($result['metrics'], $inspection['metrics'] ?? []);
        }

        return $result;
    }

    public function normalize(string $kind, array $row): array
    {
        foreach ($this->table($kind)['integers'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (string) $row[$key];
            }
        }
        if ($kind === 'revisions' && isset($row['created_by']) && (is_int($row['created_by']) || is_string($row['created_by']))) {
            $row['created_by'] = (string) $row['created_by'];
        }

        return $row;
    }

    public function digest(string $kind, array $row): string
    {
        $row = $this->normalize($kind, $row);
        // v1 had no discriminator; an unknown legacy author remains precisely the
        // same historical fact and digest after the additive schema migration.
        if ($kind === 'revisions' && ($row['created_by_type'] ?? null) === null) {
            unset($row['created_by_type']);
        }
        foreach ($this->table($kind)['json'] as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = (object) ['json' => json_decode($row[$column], flags: JSON_THROW_ON_ERROR)];
            }
        }

        return $this->json->digest($row);
    }
}
