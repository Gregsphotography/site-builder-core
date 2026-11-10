<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use SiteBuilder\Core\Rendering\RenderContext;

interface BreadcrumbProvider
{
    /** @return list<array{label:string,url:?string}> */
    public function items(RenderContext $context): array;
}
