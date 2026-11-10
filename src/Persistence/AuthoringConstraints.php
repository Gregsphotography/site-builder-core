<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Persistence;

use Illuminate\Contracts\Auth\Authenticatable;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Contracts\DocumentAuthoringConstraint;
use SiteBuilder\Core\Rendering\RenderContext;

final class AuthoringConstraints
{
    public function check(Authenticatable $actor, Document $document, RenderContext $context): void
    {
        foreach (app()->tagged(DocumentAuthoringConstraint::class) as $constraint) {
            $constraint->authorize($actor, $document, $context);
        }
    }
}
