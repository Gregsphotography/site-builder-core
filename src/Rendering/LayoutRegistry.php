<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use InvalidArgumentException;

final class LayoutRegistry
{
    private array $views = [];

    private array $regions = [];

    public function keys(): array
    {
        return array_keys($this->views);
    }

    public function register(string $key, string $view, array $regions = ['body']): void
    {
        if ($key === '' || $view === '' || isset($this->views[$key])) {
            throw new InvalidArgumentException('Layouts require a unique key and trusted view.');
        }
        if (array_diff($regions, ['header', 'body', 'footer']) !== [] || ! in_array('body', $regions, true)) {
            throw new InvalidArgumentException('Layouts declare only trusted header/body/footer regions, including body.');
        }
        $this->views[$key] = $view;
        $this->regions[$key] = array_values(array_unique($regions));
    }

    public function supports(?string $key, string $region): bool
    {
        $key ??= config('site-builder.layout', 'site-builder::layouts.site');
        $regions = $this->regions[$key] ?? ($key === 'site-builder::layouts.site' ? ['header', 'body', 'footer'] : ['body']);

        return in_array($region, $regions, true);
    }

    public function view(?string $key): string
    {
        $configured = config('site-builder.layout', 'site-builder::layouts.site');
        $view = $key === null ? (is_string($configured) ? ($this->views[$configured] ?? $configured) : null) : ($this->views[$key] ?? null);
        if (! is_string($view) || ! view()->exists($view)) {
            throw new InvalidArgumentException('Unregistered or unavailable layout.');
        }

        return $view;
    }
}
