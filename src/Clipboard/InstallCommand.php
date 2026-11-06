<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Clipboard;

use Illuminate\Console\Command;
use SiteBuilder\Core\Installation\MigrationPublisher;

final class InstallCommand extends Command
{
    protected $signature = 'site-builder:clipboard-install';

    protected $description = 'Publish optional transient clipboard migrations; run migrate explicitly';

    public function handle(MigrationPublisher $migrations): int
    {
        $files = $migrations->publish(__DIR__.'/../../database/clipboard-migrations', database_path('migrations'));
        $this->components->info(count($files).' clipboard migration(s) published. Run php artisan migrate explicitly.');

        return self::SUCCESS;
    }
}
