<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Persistence;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;

/** Host principals stay opaque: never coerce a UUID, ULID or bigint through PHP integers. */
final readonly class ActorIdentity
{
    public function __construct(public string $id, public string $type) {}

    public static function from(Authenticatable $actor): self
    {
        $id = $actor->getAuthIdentifier();
        if (! self::validId($id) || strlen($actor::class) > 255) {
            throw new AuthorizationException('Page commands require a bounded, nonempty actor identity.');
        }

        return new self((string) $id, $actor::class);
    }

    public static function validId(mixed $id): bool
    {
        return (is_int($id) && $id > 0) || (is_string($id) && $id !== '0' && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,190}$/D', $id) === 1);
    }

    public function holds(?string $type, string $id): bool
    {
        if ($this->id !== $id) {
            return false;
        }
        if ($type !== null) {
            return $this->type === $type;
        }

        // Pre-upgrade leases had no model discriminator. Only the explicitly configured
        // former actor domain can reclaim one, and PageLocks also requires its tab token.
        $legacy = config('site-builder.auth.legacy_actor_type');
        if ($legacy === null) {
            $guard = config('auth.defaults.guard');
            $provider = config('auth.guards.'.$guard.'.provider');
            $legacy = is_string($provider) ? config('auth.providers.'.$provider.'.model') : null;
        }

        return is_string($legacy) && $this->type === $legacy;
    }
}
