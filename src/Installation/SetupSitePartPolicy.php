<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

use Illuminate\Contracts\Auth\Authenticatable;
use SiteBuilder\Core\SiteParts\SitePart;

class SetupSitePartPolicy
{
    public function viewAny(Authenticatable $actor): bool
    {
        return array_any(['section', 'header', 'footer', 'design'], fn ($kind) => $this->permit($actor, 'view', $kind));
    }

    public function create(Authenticatable $actor, string $kind): bool
    {
        return $this->permit($actor, 'create', $kind);
    }

    public function view(Authenticatable $actor, SitePart $part): bool
    {
        return $this->permit($actor, 'view', $part->kind);
    }

    public function update(Authenticatable $actor, SitePart $part): bool
    {
        return $this->permit($actor, 'update', $part->kind);
    }

    public function preview(Authenticatable $actor, SitePart $part): bool
    {
        return $this->permit($actor, 'preview', $part->kind);
    }

    public function publish(Authenticatable $actor, SitePart $part): bool
    {
        return $this->permit($actor, 'publish', $part->kind);
    }

    public function restore(Authenticatable $actor, SitePart $part): bool
    {
        return $this->permit($actor, 'restore', $part->kind);
    }

    public function delete(Authenticatable $actor, SitePart $part): bool
    {
        return $this->permit($actor, 'delete', $part->kind);
    }

    public function use(Authenticatable $actor, SitePart $part): bool
    {
        return $this->permit($actor, 'use', $part->kind);
    }

    public function withdraw(Authenticatable $actor, SitePart $part): bool
    {
        return $this->permit($actor, 'withdraw', $part->kind);
    }

    private function permit(Authenticatable $actor, string $ability, string $kind): bool
    {
        return app(SetupAuthorization::class)->allows($actor, $ability, 'site-part', $kind);
    }
}
