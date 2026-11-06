<?php

declare(strict_types=1);

namespace SiteBuilder\Core\DraftHistory;

use Illuminate\Console\Command;

final class PruneCommand extends Command
{
    protected $signature = 'site-builder:history-prune';

    protected $description = 'Remove expired operational undo histories without changing page content';

    public function handle(DraftHistory $history): int
    {
        $this->components->info($history->prune().' expired draft-history session(s) removed.');

        return self::SUCCESS;
    }
}
