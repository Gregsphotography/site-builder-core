<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use Illuminate\Support\HtmlString;

final readonly class RenderResult
{
    public function __construct(public HtmlString $html, public array $diagnostics, public array $structuredData = [], public ?HtmlString $headerSlot = null, public ?HtmlString $footerSlot = null) {}
}
