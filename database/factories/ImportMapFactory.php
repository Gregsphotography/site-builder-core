<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use SiteBuilder\Core\Models\ImportMap;
use SiteBuilder\Core\Models\ImportRun;

/** @extends Factory<ImportMap> */
final class ImportMapFactory extends Factory
{
    protected $model = ImportMap::class;

    public function definition(): array
    {
        return ['run_id' => ImportRun::factory(), 'source' => 'fixture', 'kind' => 'page', 'source_id' => fake()->unique()->uuid(), 'target_id' => '1', 'source_digest' => hash('sha256', 'source'), 'target_digest' => hash('sha256', 'target')];
    }
}
