<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Discovery;

use SiteBuilder\Core\Rendering\SafeUrl;

/** A public projection, never a host model or an arbitrary record payload. */
final readonly class ContentItem
{
    public function __construct(
        public string $id,
        public string $locale,
        public string $title,
        public string $url,
        public string $excerpt = '',
        public string $body = '',
        public ?string $image = null,
        public string $alt = '',
        public ?string $date = null,
        public string $type = 'page',
        public array $tags = [],
        public string $subtitle = '',
        public bool $listable = true,
        public bool $searchable = true,
    ) {
        $safe = new SafeUrl;
        if ($id === '' || strlen($id) > 255 || ! $safe->canonical($url) || $url === '' || ($image !== null && ! $safe->valid($image, true)) || count($tags) > 32 || array_any($tags, fn ($tag) => ! is_string($tag) || mb_strlen($tag) > 80)) {
            throw new \InvalidArgumentException('Content sources must provide bounded public identifiers, tags and safe public URLs.');
        }
        $parsed = $date === null ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (($date !== null && ($parsed === false || $parsed->format('Y-m-d') !== $date)) || mb_strlen($title) > 512 || mb_strlen($excerpt) > 2000 || mb_strlen($body) > 100000 || mb_strlen($type) > 255 || mb_strlen($alt) > 2000 || mb_strlen($subtitle) > 255) {
            throw new \InvalidArgumentException('Content projections require bounded public fields and optional YYYY-MM-DD dates.');
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode(get_object_vars($this), JSON_THROW_ON_ERROR));
    }
}
