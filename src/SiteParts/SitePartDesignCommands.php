<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Design\DesignCompatibilityReport;
use SiteBuilder\Core\Design\DesignData;
use SiteBuilder\Core\Design\DesignOperations;
use SiteBuilder\Core\Design\DesignTarget;
use SiteBuilder\Core\Design\ThemeIdentity;
use SiteBuilder\Core\DraftHistory\HistoryContext;
use SiteBuilder\Core\DraftHistory\LogicalAction;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;

final class SitePartDesignCommands
{
    public function __construct(private SitePartCommands $commands, private SitePartAccess $access, private DesignOperations $operations, private DocumentValidator $validator) {}

    public function set(Authenticatable $actor, string $id, string $lease, int $version, DesignTarget $target, string $key, string $option, ?HistoryContext $history = null): SitePartMutationResult
    {
        return $this->patch($actor, $id, $lease, $version, $target, [$key => $option], $history);
    }

    public function patch(Authenticatable $actor, string $id, string $lease, int $version, DesignTarget $target, array $changes, ?HistoryContext $history = null): SitePartMutationResult
    {
        return $this->mutate($actor, $id, $lease, $version, fn ($document) => $this->operations->patch($document, $target, $changes, $this->commands->context()), $history);
    }

    public function reset(Authenticatable $actor, string $id, string $lease, int $version, DesignTarget $target, ?string $key = null, ?HistoryContext $history = null): SitePartMutationResult
    {
        return $this->mutate($actor, $id, $lease, $version, fn ($document) => $this->operations->reset($document, $target, $key, $this->commands->context()), $history);
    }

    public function resetScope(Authenticatable $actor, string $id, string $lease, int $version, DesignTarget $target, ?HistoryContext $history = null): SitePartMutationResult
    {
        return $this->mutate($actor, $id, $lease, $version, fn ($document) => $this->operations->resetScope($document, $target, $this->commands->context()), $history);
    }

    public function themeReport(Authenticatable $actor, string $id, ?ThemeIdentity $theme): DesignCompatibilityReport
    {
        $part = SitePart::query()->findOrFail($id);
        $this->access->authorize($actor, 'update', $part);

        return $this->operations->report($part->draft_payload->validationDocument(), $theme, $this->commands->context());
    }

    public function setTheme(Authenticatable $actor, string $id, string $lease, int $version, ?ThemeIdentity $theme, bool $confirmation = false, ?HistoryContext $history = null): SitePartMutationResult
    {
        return $this->mutate($actor, $id, $lease, $version, function ($document) use ($theme, $confirmation) {
            $report = $this->operations->report($document, $theme, $this->commands->context());
            if (! $confirmation && (! $report->compatible || $report->changedDefaults !== [])) {
                throw ValidationException::withMessages(['theme' => 'Review and explicitly confirm the site-part theme change.']);
            }

            return $this->operations->setTheme($document, $theme, $this->commands->context());
        }, $history);
    }

    private function mutate(Authenticatable $actor, string $id, string $lease, int $version, callable $operation, ?HistoryContext $history): SitePartMutationResult
    {
        return $this->commands->mutate($actor, $id, $lease, $version, 'update', function (SitePart $part) use ($operation): void {
            $before = $part->draft_payload->validationDocument();
            $after = $operation($before);
            app(RenderFeatureRegistry::class)->assertPreserved($before, $after);
            $this->validator->validate($after, context: $this->commands->context());
            if ($part->kind === 'design') {
                $design = DesignData::payload($after->extensions);
                if ($design === null || ! isset($design->theme)) {
                    throw ValidationException::withMessages(['theme' => 'A site-design resource requires an explicit pinned theme; choose another theme or archive the resource.']);
                }
                $part->draft_payload = SitePartPayload::decode(json_encode((object) ['format' => SitePartPayload::FORMAT, 'version' => 1, 'kind' => 'design', 'design' => $design], JSON_THROW_ON_ERROR));
            } else {
                $part->draft_payload = SitePartPayload::document($part->kind, $after);
            }
        }, history: $history, action: new LogicalAction('design', 'Change site-part design'));
    }
}
