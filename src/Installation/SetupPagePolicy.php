<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

use Illuminate\Contracts\Auth\Authenticatable;
use SiteBuilder\Core\Models\Page;

class SetupPagePolicy
{
    public function viewAny(Authenticatable $actor): bool
    {
        return $this->permit($actor, 'view');
    }

    public function create(Authenticatable $actor): bool
    {
        return $this->permit($actor, 'create');
    }

    public function view(Authenticatable $actor, Page $page): bool
    {
        return $this->permit($actor, 'view');
    }

    public function update(Authenticatable $actor, Page $page): bool
    {
        return $this->permit($actor, 'update');
    }

    public function preview(Authenticatable $actor, Page $page): bool
    {
        return $this->permit($actor, 'preview');
    }

    public function publish(Authenticatable $actor, Page $page): bool
    {
        return $this->permit($actor, 'publish');
    }

    public function restore(Authenticatable $actor, Page $page): bool
    {
        return $this->permit($actor, 'restore');
    }

    public function delete(Authenticatable $actor, Page $page): bool
    {
        return $this->permit($actor, 'delete');
    }

    private function permit(Authenticatable $actor, string $ability): bool
    {
        return app(SetupAuthorization::class)->allows($actor, $ability, 'page');
    }
}
