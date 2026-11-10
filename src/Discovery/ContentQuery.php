<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Discovery;

final readonly class ContentQuery
{
    public int $page;

    public int $perPage;

    public string $text;

    public function __construct(public string $source = 'builder.pages', string $text = '', public string $type = '', public string $tag = '', public string $sort = 'newest', int $page = 1, int $perPage = 12, public bool $search = false)
    {
        $this->page = max(1, min(10000, $page));
        $this->perPage = max(1, min(48, $perPage));
        $this->text = mb_substr(trim($text), 0, 200);
        if (! in_array($sort, ['newest', 'oldest', 'title', 'relevance'], true) || mb_strlen($type) > 255 || mb_strlen($tag) > 80) {
            throw new \InvalidArgumentException('Unsupported content query.');
        }
    }
}
