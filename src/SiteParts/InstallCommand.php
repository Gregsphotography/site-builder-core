<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use DateTimeImmutable;
use Illuminate\Console\Command;
use SiteBuilder\Core\Installation\MigrationPublisher;

final class InstallCommand extends Command
{
    protected $signature = 'site-builder:site-parts-install';

    protected $description = 'Publish optional site-part, dependency and typed history migrations; never run them automatically';

    public function handle(MigrationPublisher $migrations): int
    {
        $timestamp = new DateTimeImmutable;
        $history = $migrations->publish(__DIR__.'/../../database/history-migrations', database_path('migrations'), $timestamp);
        $parts = $migrations->publish(__DIR__.'/../../database/site-parts-migrations', database_path('migrations'), $timestamp->modify('+1 second'));
        $this->components->info((count($history) + count($parts)).' site-part/history migration(s) published. Run php artisan migrate explicitly.');

        return self::SUCCESS;
    }
}
