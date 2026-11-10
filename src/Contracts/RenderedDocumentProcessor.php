<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\RenderResult;

interface RenderedDocumentProcessor
{
    public function process(RenderResult $result, RenderContext $context): RenderResult;
}
