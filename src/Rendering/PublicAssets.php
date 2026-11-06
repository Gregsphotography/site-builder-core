<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use Illuminate\Support\HtmlString;

/** A scoped response collector. Fragment hosts own an explicit scope around render AND emit. */
final class PublicAssets
{
    private array $requested = [];

    private array $emitted = [];

    public function __construct(private PublicAssetRegistry $registry) {}

    public function scope(callable $render): mixed
    {
        $previous = [$this->requested, $this->emitted];
        $this->requested = $this->emitted = [];
        try {
            return $render();
        } finally {
            [$this->requested, $this->emitted] = $previous;
        }
    }

    public function request(string $id): void
    {
        $this->registry->get($id);
        $this->requested[$id] = true;
    }

    public function html(): HtmlString
    {
        $html = '';
        foreach (array_keys($this->requested) as $id) {
            if (isset($this->emitted[$id])) {
                continue;
            }
            $asset = $this->registry->get($id);
            $html .= $asset->kind === 'module' ? '<script type="module" src="'.e($asset->url).'"></script>' : '<link rel="stylesheet" href="'.e($asset->url).'">';
            $this->emitted[$id] = true;
        }

        return new HtmlString($html);
    }
}
