<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use SiteBuilder\Core\Persistence\MutationResult;

final readonly class CompositionResult
{
    public function __construct(public MutationResult $mutation, public array $insertedIds, public SourceSnapshot $source, public ReferenceReport $references) {}
}
