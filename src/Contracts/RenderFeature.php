<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Contracts;

use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Rendering\RenderContext;
use stdClass;

interface RenderFeature
{
    public function key(): string;

    public function version(): int;

    public function diagnostics(stdClass $payload, Document $document, ?Node $node, RenderContext $context): array;

    public function readable(stdClass $payload, bool $node): bool;

    public function projectSections(stdClass $payload): bool;
}
