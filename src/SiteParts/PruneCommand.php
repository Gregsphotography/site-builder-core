<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PruneCommand extends Command
{
    protected $signature = 'site-builder:site-parts-prune {--keep=20 : Retain at least this many newest revisions per resource} {--apply : Explicitly remove only unreferenced, unpinned historical revisions}';

    protected $description = 'Review bounded immutable site-part retention; never prune a current or referenced revision';

    public function handle(SitePartCatalog $catalog, ContentDependencies $dependencies): int
    {
        if (! $catalog->available()) {
            $this->components->error('Install site-part storage first.');

            return self::FAILURE;
        }
        $keep = filter_var($this->option('keep'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($keep === false) {
            $this->components->error('The retention count must be 1–1000.');

            return self::FAILURE;
        }
        $report = ['dryRun' => ! $this->option('apply'), 'eligible' => 0, 'removed' => 0, 'protected' => 0];
        foreach (SitePart::query()->orderBy('id')->lazyById(100) as $candidate) {
            DB::transaction(function () use ($candidate, $keep, $dependencies, &$report): void {
                $part = SitePart::query()->lockForUpdate()->find($candidate->id);
                if ($part === null) {
                    return;
                }
                $revisions = $part->revisions()->lockForUpdate()->get();
                foreach ($revisions->skip($keep) as $revision) {
                    if ($part->current_revision_id === $revision->id) {
                        $report['protected']++;

                        continue;
                    }
                    try {
                        $uses = $dependencies->authoritativeUses(revisionId: $revision->id);
                        if ($uses !== []) {
                            $report['protected']++;

                            continue;
                        }
                        $report['eligible']++;
                        if ($this->option('apply')) {
                            $dependencies->assertUnusedRevision($revision->id);
                            $dependencies->removeOwner('site-part-revision', $revision->id);
                            $revision->delete();
                            $report['removed']++;
                        }
                    } catch (ValidationException) {
                        $report['protected']++;
                    }
                }
            }, 3);
        }
        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
