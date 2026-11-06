<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Persistence;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use SiteBuilder\Core\Models\Page;

final class PageAccess
{
    public function __construct(private Gate $gate) {}

    public function authorize(Authenticatable $actor, string $ability, Page|string $page): ActorIdentity
    {
        $identity = ActorIdentity::from($actor);
        $this->gate->forUser($actor)->authorize($ability, $page);

        return $identity;
    }
}
