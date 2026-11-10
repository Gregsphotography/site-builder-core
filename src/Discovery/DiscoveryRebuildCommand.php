<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Discovery;

use Illuminate\Console\Command;
use SiteBuilder\Core\Rendering\LocaleCatalog;

final class DiscoveryRebuildCommand extends Command
{
    protected $signature = 'site-builder:discovery-rebuild {--locale=}';

    protected $description = 'Rebuild the public discovery projection in the current host scope';

    public function handle(ContentIndex $index, LocaleCatalog $locales): int
    {
        if (! $index->available()) {
            $this->error('Run site-builder:discovery-install and migrate first.');

            return 1;
        }
        $chosen = $this->option('locale');
        if ($chosen !== null && ! $locales->has($chosen)) {
            $this->error('Unknown discovery locale.');

            return 1;
        }
        foreach ($chosen === null ? $locales->all() : [$chosen] as $locale) {
            $this->line($locale.': '.$index->rebuild($locale).' public records indexed.');
        }

        return 0;
    }
}
