<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use SiteBuilder\Core\SiteParts\SitePart;

final class SitePartTestPolicy
{
    public function viewAny(Authenticatable $actor): bool
    {
        return $actor->allowed === true;
    }

    public function create(Authenticatable $actor, string $kind): bool
    {
        return $actor->allowed === true && ($kind === 'section' || $actor->chrome === true);
    }

    public function before(Authenticatable $actor, string $ability, mixed ...$arguments): ?bool
    {
        if (in_array($ability, ['create', 'viewAny'], true)) {
            return null;
        }
        $part = $arguments[0] ?? null;

        return $actor->allowed === true && $part instanceof SitePart && ($part->kind === 'section' || $actor->chrome === true) && ($ability !== 'withdraw' || $actor->emergency === true);
    }

    public function view(Authenticatable $actor, SitePart $part): bool
    {
        return true;
    }

    public function update(Authenticatable $actor, SitePart $part): bool
    {
        return true;
    }

    public function publish(Authenticatable $actor, SitePart $part): bool
    {
        return true;
    }

    public function restore(Authenticatable $actor, SitePart $part): bool
    {
        return true;
    }

    public function preview(Authenticatable $actor, SitePart $part): bool
    {
        return true;
    }

    public function delete(Authenticatable $actor, SitePart $part): bool
    {
        return true;
    }

    public function use(Authenticatable $actor, SitePart $part): bool
    {
        return true;
    }

    public function withdraw(Authenticatable $actor, SitePart $part): bool
    {
        return true;
    }
}
