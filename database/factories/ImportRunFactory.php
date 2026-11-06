<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use SiteBuilder\Core\Models\ImportRun;

/** @extends Factory<ImportRun> */
final class ImportRunFactory extends Factory
{
    protected $model = ImportRun::class;

    public function definition(): array
    {
        return ['source' => 'fixture', 'source_fingerprint' => hash('sha256', fake()->uuid()), 'format_version' => 1, 'mapper_version' => 1, 'status' => 'prepared', 'summary' => null, 'started_at' => now()];
    }
}
