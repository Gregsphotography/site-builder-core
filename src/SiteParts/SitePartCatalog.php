<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Composition\PublishedPartReference;
use SiteBuilder\Core\Content\CanonicalJson;

final class SitePartCatalog
{
    public function __construct(private SitePartAccess $access, private Gate $gate, private CanonicalJson $json) {}

    public function available(): bool
    {
        return Schema::hasTable('site_builder_site_parts') && Schema::hasTable('site_builder_site_part_revisions') && Schema::hasTable('site_builder_content_dependencies');
    }

    public function find(Authenticatable $actor, string $id): SitePart
    {
        $part = SitePart::query()->findOrFail($id);
        $this->access->authorize($actor, 'view', $part);

        return $part;
    }

    public function reference(Authenticatable $actor, string $revisionId): PublishedPartReference
    {
        $revision = SitePartRevision::query()->findOrFail($revisionId);
        $part = SitePart::query()->findOrFail($revision->resource_id);
        $this->access->authorize($actor, 'use', $part);
        if ($part->archived_at !== null || $part->current_revision_id === null || $revision->withdrawn_at !== null || ! hash_equals($revision->digest, $this->json->digest($revision->payload))) {
            throw ValidationException::withMessages(['sitePart' => 'This published revision is not available for new use.']);
        }

        return $revision->reference();
    }

    public function searchPage(Authenticatable $actor, ?string $search = null, ?string $kind = null, ?string $cursor = null, int $limit = 30): array
    {
        $identity = $this->access->authorize($actor, 'viewAny', SitePart::class);
        $search = mb_substr(trim($search ?? ''), 0, 255);
        if ($kind !== null && ! in_array($kind, ['section', 'header', 'footer', 'design', 'overlay'], true)) {
            throw ValidationException::withMessages(['kind' => 'Unknown site-part kind.']);
        }
        $queryDigest = $this->json->digest([$identity->id, $identity->type, $search, $kind]);
        $after = null;
        if ($cursor !== null) {
            try {
                $data = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
                if (! hash_equals($queryDigest, $data['query']) || ! is_string($data['after'])) {
                    throw new \RuntimeException;
                }
                $after = $data['after'];
            } catch (\Throwable) {
                throw ValidationException::withMessages(['cursor' => 'The site-part cursor expired or belongs to another search/actor.']);
            }
        }
        $limit = max(1, min(100, $limit));
        $entries = collect();
        $last = null;
        $needle = mb_convert_case($search, MB_CASE_FOLD, 'UTF-8');
        $query = SitePart::query()->whereNull('archived_at')->when($kind, fn ($query) => $query->where('kind', $kind))->orderBy('id');
        if ($after !== null) {
            $query->where('id', '>', $after);
        }
        foreach ($query->lazyById(100) as $part) {
            if (! $this->gate->forUser($actor)->allows('view', $part) || ($needle !== '' && ! str_contains(mb_convert_case($part->name.' '.$part->description, MB_CASE_FOLD, 'UTF-8'), $needle))) {
                continue;
            }
            if ($entries->count() === $limit) {
                return ['entries' => $entries, 'nextCursor' => Crypt::encryptString(json_encode(['query' => $queryDigest, 'after' => $last], JSON_THROW_ON_ERROR))];
            }
            $entries->push($part);
            $last = $part->id;
        }

        return ['entries' => $entries, 'nextCursor' => null];
    }
}
