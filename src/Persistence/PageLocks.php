<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Persistence;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageLock;

final class PageLocks
{
    public function __construct(private PageAccess $access) {}

    public function acquire(Authenticatable $actor, int $pageId, ?string $token = null): Lease
    {
        $token ??= (string) Str::uuid();
        $this->validateToken($token);

        return DB::transaction(function () use ($actor, $pageId, $token): Lease {
            $page = Page::query()->lockForUpdate()->findOrFail($pageId);
            $userId = $this->access->authorize($actor, 'update', $page);
            $lock = $page->editLock()->first();
            $hash = hash('sha256', $token);
            if ($lock !== null) {
                if ($lock->expires_at->isFuture()) {
                    if (! $userId->holds($lock->user_type, $lock->user_id) || ! hash_equals($lock->token_hash, $hash)) {
                        throw new WriteConflict('lease_held', $page->write_version);
                    }
                } elseif (hash_equals($lock->token_hash, $hash)) {
                    throw new WriteConflict('lease_expired_use_new_token', $page->write_version);
                }
            }
            $lock ??= new PageLock;
            $lock->forceFill(['page_id' => $pageId, 'user_id' => $userId->id, 'user_type' => $userId->type, 'token_hash' => $hash, 'expires_at' => now()->addSeconds(max(1, (int) config('site-builder.locks.ttl_seconds', 300)))])->save();

            return new Lease($pageId, $token, $lock->expires_at, $page->write_version);
        }, 3);
    }

    public function renew(Authenticatable $actor, int $pageId, string $token): Lease
    {
        return DB::transaction(function () use ($actor, $pageId, $token): Lease {
            $page = Page::query()->lockForUpdate()->findOrFail($pageId);
            $userId = $this->access->authorize($actor, 'update', $page);
            $lock = $this->assertHeld($page, $userId, $token);
            $lock->forceFill(['user_type' => $userId->type, 'expires_at' => now()->addSeconds(max(1, (int) config('site-builder.locks.ttl_seconds', 300)))])->save();

            return new Lease($pageId, $token, $lock->expires_at, $page->write_version);
        }, 3);
    }

    public function release(Authenticatable $actor, int $pageId, string $token): void
    {
        DB::transaction(function () use ($actor, $pageId, $token): void {
            $page = Page::query()->lockForUpdate()->findOrFail($pageId);
            $userId = $this->access->authorize($actor, 'update', $page);
            $this->assertHeld($page, $userId, $token)->delete();
        }, 3);
    }

    /** Caller must already hold the page row lock in its write transaction. */
    public function assertHeld(Page $page, ActorIdentity $userId, string $token): PageLock
    {
        $this->validateToken($token);
        $lock = $page->editLock()->first();
        if ($lock === null || ! $userId->holds($lock->user_type, $lock->user_id) || ! hash_equals($lock->token_hash, hash('sha256', $token)) || ! $lock->expires_at->isFuture()) {
            throw new WriteConflict('lease_lost', $page->write_version);
        }

        return $lock;
    }

    private function validateToken(string $token): void
    {
        if (! Str::isUuid($token)) {
            throw new InvalidArgumentException('A lease token must be a UUID.');
        }
    }
}
