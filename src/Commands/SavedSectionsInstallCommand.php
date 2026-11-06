<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Commands;

use Illuminate\Console\Command;
use SiteBuilder\Core\Installation\MigrationPublisher;

final class SavedSectionsInstallCommand extends Command
{
    protected $signature = 'site-builder:saved-sections-install';

    protected $description = 'Publish optional saved-section migrations without changing database content';

    public function handle(MigrationPublisher $migrations): int
    {
        $published = $migrations->publish(__DIR__.'/../../database/saved-sections-migrations', database_path('migrations'));
        $this->components->info(count($published).' saved-section migration(s) published. Run php artisan migrate explicitly.');

        return self::SUCCESS;
    }
}
