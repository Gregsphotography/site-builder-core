<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Rendering\RenderContext;

interface DocumentAuthoringConstraint
{
    public function authorize(Authenticatable $actor, Document $document, RenderContext $context): void;
}
