<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageSlug;

/** @extends Factory<PageSlug> */
final class PageSlugFactory extends Factory
{
    protected $model = PageSlug::class;

    public function definition(): array
    {
        return ['page_id' => Page::factory(), 'locale' => 'en', 'slug' => fake()->unique()->slug(3)];
    }
}
