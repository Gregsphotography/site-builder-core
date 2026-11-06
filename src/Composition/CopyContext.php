<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

final readonly class CopyContext
{
    public function __construct(public int $pageId, public string $locale, public ?CopyOwner $owner = null)
    {
        if ($owner !== null && (($owner->kind === 'page' && $owner->id !== $pageId) || ($owner->kind === 'site-part' && $pageId !== 0))) {
            throw new \InvalidArgumentException('Copy context and typed owner disagree.');
        }
    }
}
