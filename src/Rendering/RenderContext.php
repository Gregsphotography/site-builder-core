<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use InvalidArgumentException;

final readonly class RenderContext
{
    public function __construct(public string $locale, public string $mode = 'public', public ?int $pageId = null, public ?string $layout = null, public ?PublicQuery $query = null)
    {
        if (! in_array($mode, ['public', 'preview'], true)) {
            throw new InvalidArgumentException('Unknown rendering mode.');
        }
    }
}
