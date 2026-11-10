<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Discovery;

use SiteBuilder\Core\Rendering\LocaleCatalog;

final class ContentSources
{
    private array $providers = [];

    private ?\Closure $scopeResolver = null;

    public function register(string $id, ContentSourceProvider $provider): void
    {
        if (! preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $id) || isset($this->providers[$id])) {
            throw new \InvalidArgumentException('Content source IDs must be unique trusted identifiers.');
        }
        $this->providers[$id] = $provider;
    }

    public function get(string $id): ?ContentSourceProvider
    {
        return $this->providers[$id] ?? null;
    }

    public function options(): array
    {
        return array_map(fn ($provider) => $provider->label(), $this->providers);
    }

    /** Trusted host integration; request/query parameters must never select a scope. */
    public function scopeUsing(\Closure $resolver): void
    {
        $this->scopeResolver = $resolver;
    }

    public function context(string $locale): ContentContext
    {
        if (! app(LocaleCatalog::class)->has($locale)) {
            throw new \InvalidArgumentException('Unknown discovery locale.');
        }
        $scope = $this->scopeResolver === null ? 'default' : ($this->scopeResolver)();
        if (! is_string($scope) || $scope === '' || strlen($scope) > 120) {
            throw new \InvalidArgumentException('Discovery scope must be a bounded server-derived identifier.');
        }

        return new ContentContext($locale, $scope);
    }
}
