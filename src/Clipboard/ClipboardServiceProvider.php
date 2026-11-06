<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Clipboard;

use Illuminate\Support\ServiceProvider;

final class ClipboardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(ClipboardDomainResolver::class, MainDomainResolver::class);
        $this->app->bindIf(ClipboardExportPolicy::class, ContentOnlyExportPolicy::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, PruneCommand::class]);
            $this->publishesMigrations([__DIR__.'/../../database/clipboard-migrations' => database_path('migrations')], 'site-builder-clipboard-migrations');
        }
    }
}
