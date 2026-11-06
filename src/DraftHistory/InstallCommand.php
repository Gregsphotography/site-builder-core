<?php

declare(strict_types=1);

namespace SiteBuilder\Core\DraftHistory;

use Illuminate\Console\Command;
use SiteBuilder\Core\Installation\MigrationPublisher;

final class InstallCommand extends Command
{
    protected $signature = 'site-builder:history-install';

    protected $description = 'Publish optional transient draft-history migrations without changing the database';

    public function handle(MigrationPublisher $migrations): int
    {
        $published = $migrations->publish(__DIR__.'/../../database/history-migrations', database_path('migrations'));
        $this->components->info(count($published).' draft-history migration(s) published. Run php artisan migrate explicitly.');

        return self::SUCCESS;
    }
}
