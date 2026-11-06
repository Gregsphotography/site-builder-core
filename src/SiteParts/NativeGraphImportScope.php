<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Exchange\ConnectionIdentity;
use SiteBuilder\Core\Exchange\ExchangeSchema;
use SiteBuilder\Core\Exchange\Snapshot;
use SiteBuilder\Core\Persistence\ActorIdentity;
use Throwable;

/** Internal process-local write admission. Never serialized, signed into, or read from a file. */
final class NativeGraphImportScope
{
    private array $frames = [];

    public function __construct(private ExchangeSchema $schema, private CanonicalJson $json) {}

    public function run(Authenticatable $actor, Snapshot $original, SitePartGraphMap $map, Snapshot $mapped, Connection $target, Closure $apply, Closure $reapprove): mixed
    {
        $identity = ActorIdentity::from($actor);
        $source = $original->fingerprint($this->schema, $this->json);
        $mapDigest = $this->json->digest($map->toArray());
        if (data_get($mapped->source(), 'graph_mapping.source_fingerprint') !== $source || data_get($mapped->source(), 'graph_mapping.map_digest') !== $mapDigest || data_get($mapped->source(), 'graph_mapping.source_namespace') !== $original->source()['namespace']) {
            throw new InvalidArgumentException('Runtime graph admission must match the freshly approved original source and exact map.');
        }
        $this->frames[] = ['actor' => $actor, 'actorId' => $identity->id, 'actorType' => $identity->type, 'source' => $source, 'map' => $mapDigest, 'snapshot' => $this->json->digest($mapped), 'target' => $target, 'database' => ConnectionIdentity::fingerprint($target), 'reapprove' => $reapprove];
        try {
            return $apply();
        } finally {
            array_pop($this->frames);
        }
    }

    public function allows(Snapshot $snapshot, Connection $target): bool
    {
        $frame = $this->frames[array_key_last($this->frames)] ?? null;
        if ($frame === null || $frame['target'] !== $target) {
            return false;
        }
        try {
            return hash_equals($frame['database'], ConnectionIdentity::fingerprint($target))
                && hash_equals($frame['snapshot'], $this->json->digest($snapshot))
                && data_get($snapshot->source(), 'graph_mapping.source_fingerprint') === $frame['source']
                && data_get($snapshot->source(), 'graph_mapping.map_digest') === $frame['map'];
        } catch (Throwable) {
            return false;
        }
    }

    /** Call after all native apply locks and immediately before any run/content writes. */
    public function reapprove(Snapshot $snapshot, Connection $target): void
    {
        if (! $this->allows($snapshot, $target)) {
            throw new AuthorizationException('The exact mapped graph lacks a current runtime write scope.');
        }
        $frame = $this->frames[array_key_last($this->frames)];
        $identity = ActorIdentity::from($frame['actor']);
        if ($identity->id !== $frame['actorId'] || $identity->type !== $frame['actorType']) {
            throw new AuthorizationException('The graph import actor changed after approval.');
        }
        $fresh = ($frame['reapprove'])();
        if (! $fresh instanceof Snapshot || ! hash_equals($frame['snapshot'], $this->json->digest($fresh))) {
            throw new AuthorizationException('Fresh policy approval did not produce the exact pinned mapped graph.');
        }
    }

    public static function required(Snapshot $snapshot): bool
    {
        return array_key_exists('graph_mapping', $snapshot->source()) || str_starts_with($snapshot->source()['namespace'], 'graph-map:');
    }
}
