<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use SiteBuilder\Core\Content\Diagnostic;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Rendering\RenderContext;

interface ValidatesComponentValues
{
    /** Raw declared values; diagnostic paths are relative to this value map. @return list<Diagnostic> */
    public function diagnostics(JsonObject $values, RenderContext $context, bool $publishing): array;
}
