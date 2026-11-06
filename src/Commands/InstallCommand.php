<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Commands;

use Illuminate\Console\Command;
use SiteBuilder\Core\Installation\MigrationPublisher;

final class InstallCommand extends Command
{
    protected $signature = 'site-builder:install';

    protected $description = 'Refresh compiled public assets and publish configuration/migrations while preserving host changes';

    public function handle(MigrationPublisher $migrations): int
    {
        foreach (['site-builder-config', 'site-builder-assets'] as $tag) {
            if ($this->call('vendor:publish', ['--tag' => $tag, '--force' => $tag === 'site-builder-assets']) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }
        $published = $migrations->publish(__DIR__.'/../../database/migrations', database_path('migrations'));
        $this->components->info(count($published).' new package migration(s) published. Existing migration history was preserved.');
        $this->components->info('Resources published. Run php artisan migrate to install the schema.');

        return self::SUCCESS;
    }
}
