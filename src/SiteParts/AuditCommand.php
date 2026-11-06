<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Illuminate\Console\Command;

final class AuditCommand extends Command
{
    protected $signature = 'site-builder:dependencies-audit {--rebuild : Rebuild only after validating all authoritative payload references}';

    protected $description = 'Audit immutable content dependencies without changing authored content';

    public function handle(ContentDependencies $dependencies): int
    {
        $report = $dependencies->audit((bool) $this->option('rebuild'));
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $report['available'] && $report['issues'] === [] && ($report['missing'] ?? 0) === 0 && ($report['stale'] ?? 0) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
