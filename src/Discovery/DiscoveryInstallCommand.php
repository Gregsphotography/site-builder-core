<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Discovery;

use Illuminate\Console\Command;
use SiteBuilder\Core\Installation\MigrationPublisher;

final class DiscoveryInstallCommand extends Command
{
    protected $signature = 'site-builder:discovery-install';

    protected $description = 'Publish additive discovery index migrations; run migrate and discovery-rebuild afterwards';

    public function handle(MigrationPublisher $migrations): int
    {
        $files = $migrations->publish(__DIR__.'/../../database/discovery-migrations', database_path('migrations'));
        $this->components->info(count($files).' discovery migration(s) published. Run migrate and discovery-rebuild explicitly.');

        return self::SUCCESS;
    }
}
