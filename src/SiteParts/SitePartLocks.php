<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Persistence\ActorIdentity;

final class SitePartLocks
{
    public function __construct(private SitePartAccess $access) {}

    public function acquire(Authenticatable $actor, string $partId, ?string $token = null): SitePartLease
    {
        $token ??= (string) Str::uuid();
        $this->token($token);

        return DB::transaction(function () use ($actor, $partId, $token): SitePartLease {
            $part = SitePart::query()->lockForUpdate()->findOrFail($partId);
            $identity = $this->access->authorize($actor, 'update', $part);
            $record = $part->editLock()->first();
            $hash = hash('sha256', $token);
            if ($record !== null) {
                if ($record->expires_at->isFuture() && ($record->actor_id !== $identity->id || $record->actor_type !== $identity->type || ! hash_equals($record->token_hash, $hash))) {
                    throw new WriteConflict('lease_held', $part->write_version);
                }
                if (! $record->expires_at->isFuture() && hash_equals($record->token_hash, $hash)) {
                    throw new WriteConflict('lease_expired_use_new_token', $part->write_version);
                }
            }
            $record ??= new SitePartLock;
            $record->forceFill(['resource_id' => $partId, 'actor_id' => $identity->id, 'actor_type' => $identity->type, 'token_hash' => $hash, 'expires_at' => now()->addSeconds(max(1, (int) config('site-builder.locks.ttl_seconds', 300)))])->save();

            return new SitePartLease($partId, $token, $record->expires_at, $part->write_version);
        }, 3);
    }

    public function renew(Authenticatable $actor, string $partId, string $token): SitePartLease
    {
        return DB::transaction(function () use ($actor, $partId, $token): SitePartLease {
            $part = SitePart::query()->lockForUpdate()->findOrFail($partId);
            $record = $this->assertHeld($part, $this->access->authorize($actor, 'update', $part), $token);
            $record->expires_at = now()->addSeconds(max(1, (int) config('site-builder.locks.ttl_seconds', 300)));
            $record->save();

            return new SitePartLease($partId, $token, $record->expires_at, $part->write_version);
        }, 3);
    }

    public function release(Authenticatable $actor, string $partId, string $token): void
    {
        DB::transaction(function () use ($actor, $partId, $token): void {
            $part = SitePart::query()->lockForUpdate()->findOrFail($partId);
            $this->assertHeld($part, $this->access->authorize($actor, 'update', $part), $token)->delete();
        }, 3);
    }

    public function assertHeld(SitePart $part, ActorIdentity $identity, string $token): SitePartLock
    {
        $this->token($token);
        $record = $part->editLock()->first();
        if ($record === null || $record->actor_id !== $identity->id || $record->actor_type !== $identity->type || ! hash_equals($record->token_hash, hash('sha256', $token)) || ! $record->expires_at->isFuture()) {
            throw new WriteConflict('lease_lost', $part->write_version);
        }

        return $record;
    }

    private function token(string $token): void
    {
        if (! Str::isUuid($token)) {
            throw new InvalidArgumentException('A site-part lease token must be a UUID.');
        }
    }
}
