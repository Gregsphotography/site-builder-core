<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Clipboard;

use Illuminate\Console\Command;

final class PruneCommand extends Command
{
    protected $signature = 'site-builder:clipboard-prune';

    protected $description = 'Remove expired transient clipboard snapshots without changing pages';

    public function handle(ClipboardCommands $clipboard): int
    {
        $this->components->info($clipboard->prune().' expired clipboard snapshot(s) removed.');

        return self::SUCCESS;
    }
}
