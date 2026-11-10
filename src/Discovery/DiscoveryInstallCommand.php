<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Discovery;

use Illuminate\Console\Command;

final class DiscoveryInstallCommand extends Command
{
    protected $signature = 'site-builder:discovery-install';

    protected $description = 'Publish additive discovery index migrations; run migrate and discovery-rebuild afterwards';

    public function handle(): int
    {
        return $this->call('vendor:publish', ['--tag' => 'site-builder-discovery-migrations']);
    }
}
