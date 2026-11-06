<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Composition\CompositionData;
use SiteBuilder\Core\Composition\CompositionResolver;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\DraftHistory\HistoryRecord;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\SavedSections\SavedSectionVersion;

/** Rebuildable index. Destructive decisions additionally inspect current authoritative payloads. */
final class ContentDependencies
{
    public function __construct(private CanonicalJson $json, private DocumentCodec $codec) {}

    public function available(): bool
    {
        return Schema::hasTable('site_builder_content_dependencies');
    }

    public function syncPage(Page $page): void
    {
        if (! $this->available()) {
            return;
        }
        $this->removeOwner('page', (string) $page->id);
        if ($page->exists) {
            foreach (['draft' => $page->draft_content, 'published' => $page->published_content] as $scope => $document) {
                if ($document !== null) {
                    $this->index('page', (string) $page->id, $scope, (string) $page->write_version, $document);
                }
            }
            foreach ($page->revisions()->get() as $revision) {
                $this->removeOwner('page-revision', (string) $revision->id);
                if ($revision->content !== null) {
                    $this->index('page-revision', (string) $revision->id, 'content', (string) ($revision->published_from_version ?? 0), $revision->content);
                }
            }
        }
        DB::table('site_builder_content_dependencies')->where('owner_kind', 'page-revision')->whereNotIn('owner_id', DB::table('site_builder_page_revisions')->select('id'))->delete();
    }

    public function syncPart(SitePart $part): void
    {
        if (! $this->available()) {
            return;
        }
        $this->removeOwner('site-part', $part->id);
        if ($part->exists) {
            $this->index('site-part', $part->id, 'draft', (string) $part->write_version, $part->draft_payload->validationDocument());
            foreach ($part->revisions()->get() as $revision) {
                $this->removeOwner('site-part-revision', $revision->id);
                $this->index('site-part-revision', $revision->id, 'published', (string) $revision->sequence, $revision->payload->validationDocument());
            }
        }
    }

    public function syncDocument(string $kind, string $id, string $scope, string $version, Document $document): void
    {
        if (! $this->available()) {
            return;
        }
        $this->removeOwner($kind, $id);
        $this->index($kind, $id, $scope, $version, $document);
    }

    public function syncSavedVersion(SavedSectionVersion $version): void
    {
        $this->syncDocument('saved-version', $version->id, 'content', (string) $version->version, $version->document);
    }

    public function syncHistory(HistoryRecord $history): void
    {
        if (! $this->available()) {
            return;
        }
        $this->removeOwner('history', $history->id);
        if (! $history->expires_at->isFuture()) {
            return;
        }
        foreach ($history->checkpoints as $position => $checkpoint) {
            if ($checkpoint['document'] === null) {
                continue;
            }
            $document = $history->owner_kind === 'site-part' ? SitePartPayload::decode($checkpoint['document'])->validationDocument() : $this->codec->decode($checkpoint['document']);
            $this->index('history', $history->id, 'checkpoint', $history->epoch.':'.$position, $document);
        }
    }

    public function removeOwner(string $kind, string $id): void
    {
        if ($this->available()) {
            DB::table('site_builder_content_dependencies')->where('owner_kind', $kind)->where('owner_id', $id)->delete();
        }
    }

    public function assertUnusedResource(string $resourceId): void
    {
        $uses = $this->authoritativeUses($resourceId);
        if ($uses !== []) {
            throw ValidationException::withMessages(['sitePart' => 'This resource has retained content/history references and cannot be deleted. Archive it or review its impact first.']);
        }
        // Remove stale index edges only after the independent payload proof.
        DB::table('site_builder_content_dependencies')->where('resource_id', $resourceId)->delete();
    }

    public function assertUnusedRevision(string $revisionId): void
    {
        $uses = $this->authoritativeUses(null, $revisionId);
        if ($uses !== []) {
            throw ValidationException::withMessages(['revision' => 'This immutable revision is pinned by retained content/history and cannot be pruned.']);
        }
        DB::table('site_builder_content_dependencies')->where('revision_id', $revisionId)->delete();
    }

    public function authoritativeUses(?string $resourceId = null, ?string $revisionId = null): array
    {
        return $this->fresh(function (Connection $connection) use ($resourceId, $revisionId): array {
            $uses = [];
            foreach ($this->facts($connection) as $fact) {
                foreach (CompositionData::references($fact['document']) as $path => $entry) {
                    if (($resourceId === null || $entry['reference']->resourceId === $resourceId) && ($revisionId === null || $entry['reference']->revisionId === $revisionId)) {
                        $uses[] = [...$fact, 'path' => $path, 'reference' => $entry['reference']];
                    }
                }
            }

            return $uses;
        });
    }

    public function rebuild(): array
    {
        return $this->audit(true, true);
    }

    public function audit(bool $rebuild = false, bool $currentTransaction = false): array
    {
        if (! $this->available()) {
            return ['available' => false, 'references' => 0, 'issues' => ['Install site-part dependency storage first.']];
        }

        return DB::transaction(function () use ($rebuild, $currentTransaction): array {
            SitePart::query()->orderBy('id')->lockForUpdate()->get();
            $facts = $currentTransaction ? $this->facts(DB::connection()) : $this->fresh(fn (Connection $connection) => $this->facts($connection));
            $expected = [];
            $issues = [];
            foreach ($facts as $fact) {
                try {
                    foreach ($this->rows($fact['kind'], $fact['id'], $fact['scope'], $fact['version'], $fact['document']) as $row) {
                        app(CompositionResolver::class)->revision($row['reference']);
                        unset($row['reference']);
                        $expected[$row['id']] = $row;
                    }
                } catch (\Throwable $exception) {
                    $issues[] = $fact['kind'].':'.$fact['id'].' '.$exception->getMessage();
                }
            }
            if ($rebuild && $issues === []) {
                DB::table('site_builder_content_dependencies')->delete();
                foreach (array_chunk(array_values($expected), 100) as $batch) {
                    DB::table('site_builder_content_dependencies')->insert($batch);
                }
            }
            $actual = DB::table('site_builder_content_dependencies')->pluck('id')->all();
            $missing = array_diff(array_keys($expected), $actual);
            $stale = array_diff($actual, array_keys($expected));

            return ['available' => true, 'references' => count($expected), 'missing' => count($missing), 'stale' => count($stale), 'rebuilt' => $rebuild && $issues === [], 'issues' => $issues];
        }, 3);
    }

    private function index(string $kind, string $id, string $scope, string $version, Document $document): void
    {
        foreach ($this->rows($kind, $id, $scope, $version, $document) as $row) {
            unset($row['reference']);
            DB::table('site_builder_content_dependencies')->insertOrIgnore($row);
        }
    }

    private function rows(string $kind, string $id, string $scope, string $version, Document $document): array
    {
        $rows = [];
        $digest = $this->json->digest($document);
        foreach (CompositionData::references($document) as $path => $entry) {
            $reference = $entry['reference'];
            $rows[] = ['id' => $this->json->digest([$kind, $id, $scope, $version, $path, $reference]), 'owner_kind' => $kind, 'owner_id' => $id, 'scope' => $scope, 'owner_version' => $version, 'owner_digest' => $digest, 'source_path' => $path, 'resource_id' => $reference->resourceId, 'revision_id' => $reference->revisionId, 'digest' => $reference->digest, 'reference' => $reference];
        }

        return $rows;
    }

    private function facts(Connection $connection): array
    {
        $facts = [];
        $add = function (string $kind, mixed $id, string $scope, mixed $version, ?Document $document) use (&$facts): void {
            if ($document !== null) {
                $facts[] = ['kind' => $kind, 'id' => (string) $id, 'scope' => $scope, 'version' => (string) $version, 'document' => $document];
            }
        };
        $decode = fn (?string $json): ?Document => $json === null ? null : $this->codec->decode($json);
        foreach ($connection->table('site_builder_pages')->orderBy('id')->cursor() as $page) {
            $add('page', $page->id, 'draft', $page->write_version, $decode($page->draft_content));
            $add('page', $page->id, 'published', $page->write_version, $decode($page->published_content));
        }
        foreach ($connection->table('site_builder_page_revisions')->orderBy('id')->cursor() as $revision) {
            $add('page-revision', $revision->id, 'content', $revision->published_from_version ?? 0, $decode($revision->content));
        }
        if ($connection->getSchemaBuilder()->hasTable('site_builder_site_parts')) {
            foreach ($connection->table('site_builder_site_parts')->orderBy('id')->cursor() as $part) {
                $add('site-part', $part->id, 'draft', $part->write_version, SitePartPayload::decode($part->draft_payload)->validationDocument());
            }
            foreach ($connection->table('site_builder_site_part_revisions')->orderBy('id')->cursor() as $revision) {
                $add('site-part-revision', $revision->id, 'published', $revision->sequence, SitePartPayload::decode($revision->payload)->validationDocument());
            }
        }
        if ($connection->getSchemaBuilder()->hasTable('site_builder_saved_section_versions')) {
            foreach ($connection->table('site_builder_saved_section_versions')->orderBy('id')->cursor() as $version) {
                $add('saved-version', $version->id, 'content', $version->version, $decode($version->document));
            }
        }
        if ($connection->getSchemaBuilder()->hasTable('site_builder_draft_histories')) {
            foreach ($connection->table('site_builder_draft_histories')->where('expires_at', '>', now())->orderBy('id')->cursor() as $history) {
                foreach (json_decode($history->checkpoints, true, flags: JSON_THROW_ON_ERROR) as $position => $checkpoint) {
                    if ($checkpoint['document'] !== null) {
                        $document = $history->owner_kind === 'site-part' ? SitePartPayload::decode($checkpoint['document'])->validationDocument() : $decode($checkpoint['document']);
                        $add('history', $history->id, 'checkpoint', $history->epoch.':'.$position, $document);
                    }
                }
            }
        }

        return $facts;
    }

    private function fresh(callable $read): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            return $read($connection);
        }
        // Never take parent page locks while holding a target resource lock. A
        // separate same-database autocommit reader avoids an older RR snapshot.
        $readerConfig = $connection->getConfig();
        $readerConfig = array_replace($readerConfig, is_array($readerConfig['write'] ?? null) ? $readerConfig['write'] : []);
        unset($readerConfig['read'], $readerConfig['write']);
        $readerConfig['name'] = 'site_builder_dependency_reader';
        // Build directly through the factory: DatabaseManager::build may purge
        // an existing connection when its config already carries that name.
        $reader = app('db.factory')->make($readerConfig, 'site_builder_dependency_reader');
        try {
            if ($connection->getDatabaseName() !== $reader->getDatabaseName() || $connection->selectOne('SELECT @@server_uuid AS uuid', [], false)->uuid !== $reader->selectOne('SELECT @@server_uuid AS uuid', [], false)->uuid) {
                throw new \RuntimeException('Dependency audit reader must target the exact same database.');
            }

            return $read($reader);
        } finally {
            $reader->disconnect();
        }
    }
}
