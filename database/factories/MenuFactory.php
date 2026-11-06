<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use SiteBuilder\Core\Models\Menu;

/** @extends Factory<Menu> */
final class MenuFactory extends Factory
{
    protected $model = Menu::class;

    public function definition(): array
    {
        return ['location' => fake()->unique()->slug(3), 'items' => []];
    }
}
