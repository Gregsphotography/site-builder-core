<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Discovery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Derived public data. All index writers share one database lock, including rebuilds. */
final class ContentIndex
{
    public function __construct(private ContentSources $sources) {}

    public function available(): bool
    {
        return Schema::hasTable('site_builder_content_index') && Schema::hasTable('site_builder_content_tags') && Schema::hasTable('site_builder_content_index_lock');
    }

    public static function normalize(string $text): string
    {
        return mb_strtolower(Str::ascii($text), 'UTF-8');
    }

    /** Explicit read-only repair diagnosis in the current server-derived scope. */
    public function audit(string $locale): array
    {
        $context = $this->sources->context($locale);
        $report = [];
        foreach (array_keys($this->sources->options()) as $source) {
            $expected = [];
            foreach ($this->sources->get($source)->items($context) as $item) {
                $expected[$item->id] = $item->fingerprint();
            }
            $actual = DB::table('site_builder_content_index')->where('scope', $context->scope)->where('locale', $locale)->where('source', $source)->pluck('fingerprint', 'item_id')->all();
            $report[$source] = ['missing' => count(array_diff_key($expected, $actual)), 'stale' => count(array_diff_key($actual, $expected)) + count(array_filter($actual, fn ($fingerprint, $id) => isset($expected[$id]) && ! hash_equals($expected[$id], $fingerprint), ARRAY_FILTER_USE_BOTH))];
        }

        return $report;
    }

    public function discard(string $source, string $locale, ?array $ids = null): void
    {
        $context = $this->sources->context($locale);
        $this->write(fn () => $this->remove($source, $context, $ids));
    }

    public function rebuild(string $locale, ?string $onlySource = null): int
    {
        $context = $this->sources->context($locale);

        return $this->write(function () use ($context, $onlySource): int {
            $count = 0;
            foreach (array_keys($this->sources->options()) as $source) {
                if ($onlySource !== null && $source !== $onlySource) {
                    continue;
                }
                $this->remove($source, $context);
                foreach ($this->sources->get($source)->items($context) as $item) {
                    $this->put($source, $item, $context);
                    $count++;
                }
            }

            return $count;
        });
    }

    /** Host adapters call this after committing a visibility/content change. */
    public function refresh(string $source, array $ids, string $locale): void
    {
        if (! $this->available() || $this->sources->get($source) === null) {
            return;
        }
        $context = $this->sources->context($locale);
        $this->write(function () use ($source, $ids, $context): void {
            foreach (array_chunk(array_values(array_unique(array_map(strval(...), $ids))), 100) as $batch) {
                $this->remove($source, $context, $batch);
                foreach ($this->sources->get($source)->hydrate($batch, $context) as $item) {
                    $this->put($source, $item, $context);
                }
            }
        });
    }

    public function query(ContentQuery $query, string $locale): ContentResults
    {
        if (! $this->available()) {
            return new ContentResults(available: false);
        }
        $context = $this->sources->context($locale);
        $sources = $query->source === '*' ? array_keys($this->sources->options()) : ($this->sources->get($query->source) === null ? [] : [$query->source]);
        if ($sources === []) {
            return new ContentResults(available: false);
        }
        $base = DB::table('site_builder_content_index')->where('scope', $context->scope)->where('locale', $locale)->whereIn('source', $sources)->where($query->search ? 'searchable' : 'listable', true);
        $types = (clone $base)->distinct()->orderBy('type')->pluck('type')->all();
        $tags = DB::table('site_builder_content_tags')->whereIn('content_id', (clone $base)->select('id'))->distinct()->orderBy('tag')->limit(100)->pluck('tag')->all();
        if ($query->type !== '') {
            $base->where('type', $query->type);
        }
        if ($query->tag !== '') {
            $base->whereIn('id', DB::table('site_builder_content_tags')->select('content_id')->where('tag', $query->tag));
        }
        $words = array_values(array_filter(preg_split('/\s+/u', self::normalize($query->text)) ?: []));
        foreach ($words as $word) {
            $base->whereRaw("search_text LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%']);
        }
        $total = (clone $base)->count();
        if ($query->sort === 'relevance' && $words !== []) {
            $scores = $bindings = [];
            foreach ($words as $word) {
                $scores[] = "CASE WHEN title LIKE ? ESCAPE '!' THEN 100 WHEN summary LIKE ? ESCAPE '!' THEN 10 ELSE 1 END";
                $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
                $bindings = [...$bindings, $needle, $needle];
            }
            $base->orderByRaw('('.implode(' + ', $scores).') DESC', $bindings);
        } elseif ($query->sort === 'title') {
            $base->orderBy('title');
        } else {
            $dateColumn = DB::connection()->getQueryGrammar()->wrap('date');
            $base->orderByRaw('CASE WHEN '.$dateColumn.' IS NULL THEN 1 ELSE 0 END');
            $base->orderBy('date', $query->sort === 'oldest' ? 'asc' : 'desc');
        }
        $rows = $base->orderBy('id')->offset(($query->page - 1) * $query->perPage)->limit($query->perPage)->get();
        $hydrated = [];
        try {
            foreach ($rows->groupBy('source') as $source => $batch) {
                $hydrated[$source] = $this->sources->get($source)->hydrate($batch->pluck('item_id')->all(), $context);
            }
        } catch (\Throwable $exception) {
            report($exception);

            return new ContentResults(available: false);
        }
        $items = [];
        foreach ($rows as $row) {
            $item = $hydrated[$row->source][$row->item_id] ?? null;
            if ($item instanceof ContentItem && $item->locale === $locale && ($query->search ? $item->searchable : $item->listable) && hash_equals($row->fingerprint, $item->fingerprint())) {
                $items[] = $item;
            }
        }

        return new ContentResults($items, $total, $query->page, $query->perPage, $types, $tags);
    }

    private function write(\Closure $action): mixed
    {
        if (! $this->available()) {
            throw new \RuntimeException('Install discovery storage before indexing.');
        }

        return DB::transaction(function () use ($action): mixed {
            DB::table('site_builder_content_index_lock')->where('id', 1)->lockForUpdate()->first();

            return $action();
        }, 3);
    }

    private function remove(string $source, ContentContext $context, ?array $ids = null): void
    {
        $rows = DB::table('site_builder_content_index')->where('source', $source)->where('scope', $context->scope)->where('locale', $context->locale)->when($ids !== null, fn ($query) => $query->whereIn('item_id', $ids));
        DB::table('site_builder_content_tags')->whereIn('content_id', (clone $rows)->select('id'))->delete();
        $rows->delete();
    }

    private function put(string $source, ContentItem $item, ContentContext $context): void
    {
        if ($item->locale !== $context->locale) {
            throw new \InvalidArgumentException('A source returned content for another locale.');
        }
        $id = hash('sha256', json_encode([$context->scope, $source, $item->id, $item->locale], JSON_THROW_ON_ERROR));
        DB::table('site_builder_content_index')->updateOrInsert(['id' => $id], ['scope' => $context->scope, 'source' => $source, 'item_id' => $item->id, 'locale' => $item->locale, 'fingerprint' => $item->fingerprint(), 'type' => $item->type, 'title' => self::normalize($item->title), 'summary' => self::normalize(implode(' ', $item->tags).' '.$item->excerpt), 'search_text' => self::normalize($item->title.' '.implode(' ', $item->tags).' '.$item->excerpt.' '.$item->body), 'date' => $item->date, 'listable' => $item->listable, 'searchable' => $item->searchable]);
        foreach (array_unique($item->tags) as $tag) {
            DB::table('site_builder_content_tags')->insertOrIgnore(['id' => hash('sha256', $id.':'.$tag), 'content_id' => $id, 'tag' => $tag]);
        }
    }
}
