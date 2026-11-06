<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use SiteBuilder\Core\Models\Setting;

/** @extends Factory<Setting> */
final class SettingFactory extends Factory
{
    protected $model = Setting::class;

    public function definition(): array
    {
        return ['namespace' => fake()->unique()->slug(3), 'data' => new \stdClass];
    }
}
