<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Models\Page;

/** @extends Factory<Page> */
final class PageFactory extends Factory
{
    protected $model = Page::class;

    public function definition(): array
    {
        return ['title' => fake()->sentence(3), 'draft_content' => new Document, 'published_content' => null];
    }
}
