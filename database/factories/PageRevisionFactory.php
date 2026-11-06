<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageRevision;

/** @extends Factory<PageRevision> */
final class PageRevisionFactory extends Factory
{
    protected $model = PageRevision::class;

    public function definition(): array
    {
        return ['page_id' => Page::factory(), 'scope' => 'content', 'content' => new Document, 'created_by' => null, 'label' => null, 'created_at' => now(), 'origin' => 'native'];
    }
}
