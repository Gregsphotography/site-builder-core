<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageRedirect;

/** @extends Factory<PageRedirect> */
final class PageRedirectFactory extends Factory
{
    protected $model = PageRedirect::class;

    public function definition(): array
    {
        return ['page_id' => Page::factory(), 'locale' => 'en', 'from_slug' => fake()->unique()->slug(3), 'status' => 301];
    }
}
