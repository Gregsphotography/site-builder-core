<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use SiteBuilder\Core\Persistence\ActorIdentity;

final class SitePartAccess
{
    public function __construct(private Gate $gate) {}

    public function authorize(Authenticatable $actor, string $ability, SitePart|array|string $target): ActorIdentity
    {
        $identity = ActorIdentity::from($actor);
        $this->gate->forUser($actor)->authorize($ability, $target);

        return $identity;
    }
}
