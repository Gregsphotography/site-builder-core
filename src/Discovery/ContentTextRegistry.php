<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Discovery;

use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Rendering\RenderContext;

/** Host components opt in explicitly; arbitrary text properties can contain private configuration. */
final class ContentTextRegistry
{
    private array $extractors = [];

    public function register(string $type, \Closure $extractor): void
    {
        if (isset($this->extractors[$type]) || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D', $type)) {
            throw new \InvalidArgumentException('Choose a unique registered component type for its public text extractor.');
        }
        $this->extractors[$type] = $extractor;
    }

    public function text(Node $node, array $props, RenderContext $context): string
    {
        $text = isset($this->extractors[$node->type]) ? ($this->extractors[$node->type])($node, $props, $context) : '';
        if (! is_string($text)) {
            throw new \LogicException('Public text extractors must return a string.');
        }

        return $text;
    }
}
