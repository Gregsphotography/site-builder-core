<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Discovery;

use Illuminate\Console\Command;
use SiteBuilder\Core\Rendering\LocaleCatalog;

final class DiscoveryDoctorCommand extends Command
{
    protected $signature = 'site-builder:discovery-doctor';

    protected $description = 'Inspect discovery storage, registered sources and results page configuration';

    public function handle(ContentIndex $index, ContentSources $sources, LocaleCatalog $locales): int
    {
        $this->line('Discovery storage: '.($index->available() ? 'installed' : 'missing'));
        $this->line('Registered sources: '.implode(', ', array_keys($sources->options())));
        $this->line('Results page: '.(config('site-builder.discovery.results_page') ? 'configured' : 'not configured'));

        if (! $index->available()) {
            return 1;
        }
        $healthy = true;
        try {
            foreach ($locales->all() as $locale) {
                foreach ($index->audit($locale) as $source => $counts) {
                    $this->line($locale.' / '.$source.': '.$counts['missing'].' missing, '.$counts['stale'].' stale.');
                    $healthy = $healthy && $counts['missing'] === 0 && $counts['stale'] === 0;
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('A registered source could not be audited. Check private application logs and restore it before rebuilding.');

            return 1;
        }

        return $healthy ? 0 : 1;
    }
}
