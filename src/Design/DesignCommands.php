<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Design;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Composition\CompositionResolver;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\DraftHistory\DraftHistory;
use SiteBuilder\Core\DraftHistory\HistoryContext;
use SiteBuilder\Core\DraftHistory\LogicalAction;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\MutationResult;
use SiteBuilder\Core\Persistence\PageAccess;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;
use SiteBuilder\Core\SiteParts\ContentDependencies;

final class DesignCommands
{
    public function __construct(private PageAccess $access, private PageLocks $locks, private DesignOperations $operations, private DocumentValidator $validator, private CanonicalJson $json, private RenderFeatureRegistry $features) {}

    public function set(Authenticatable $actor, int $pageId, string $lease, int $version, DesignTarget $target, string $key, string $option, ?HistoryContext $history = null): MutationResult
    {
        return $this->patch($actor, $pageId, $lease, $version, $target, [$key => $option], $history);
    }

    public function patch(Authenticatable $actor, int $pageId, string $lease, int $version, DesignTarget $target, array $changes, ?HistoryContext $history = null): MutationResult
    {
        return $this->mutate($actor, $pageId, $lease, $version, fn ($document, $context) => $this->operations->patch($document, $target, $changes, $context), $history);
    }

    public function reset(Authenticatable $actor, int $pageId, string $lease, int $version, DesignTarget $target, ?string $key = null, ?HistoryContext $history = null): MutationResult
    {
        return $this->mutate($actor, $pageId, $lease, $version, fn ($document, $context) => $this->operations->reset($document, $target, $key, $context), $history);
    }

    public function resetScope(Authenticatable $actor, int $pageId, string $lease, int $version, DesignTarget $target, ?HistoryContext $history = null): MutationResult
    {
        return $this->mutate($actor, $pageId, $lease, $version, fn ($document, $context) => $this->operations->resetScope($document, $target, $context), $history);
    }

    public function themeReport(Authenticatable $actor, int $pageId, ?ThemeIdentity $theme): DesignCompatibilityReport
    {
        $page = Page::query()->findOrFail($pageId);
        $this->access->authorize($actor, 'update', $page);

        return $this->operations->report($page->draft_content ?? new Document, $theme, $this->context($page));
    }

    public function setTheme(Authenticatable $actor, int $pageId, string $lease, int $version, ?ThemeIdentity $theme, bool $confirmation = false, ?HistoryContext $history = null): MutationResult
    {
        return $this->mutate($actor, $pageId, $lease, $version, function ($document, $context) use ($theme, $confirmation) {
            $report = $this->operations->report($document, $theme, $context);
            if (! $confirmation && (! $report->compatible || $report->changedDefaults !== [])) {
                throw ValidationException::withMessages(['theme' => 'Review and confirm the theme compatibility/default changes before applying.']);
            }

            return $this->operations->setTheme($document, $theme, $context);
        }, $history);
    }

    private function context(Page $page): RenderContext
    {
        return new RenderContext(config('app.locale', 'en'), 'preview', $page->id, $page->layout);
    }

    private function mutate(Authenticatable $actor, int $pageId, string $token, int $version, callable $operation, ?HistoryContext $history = null): MutationResult
    {
        return DB::transaction(function () use ($actor, $pageId, $token, $version, $operation, $history): MutationResult {
            $page = Page::query()->lockForUpdate()->findOrFail($pageId);
            $identity = $this->access->authorize($actor, 'update', $page);
            $this->locks->assertHeld($page, $identity, $token);
            if ($page->write_version !== $version) {
                throw new WriteConflict('stale_version', $page->write_version);
            }
            app(CompositionResolver::class)->lockMutation($page->draft_content, $page->draft_content, $this->context($page), $actor);
            if ($history !== null) {
                app(DraftHistory::class)->assertCurrent($history, $actor, $page, $token);
            }
            $before = $page->draft_content;
            $old = $before ?? new Document;
            $context = $this->context($page);
            $next = $operation($old, $context);
            $this->features->assertPreserved($old, $next);
            $diagnostics = $this->validator->validate($next, context: $context);
            if (! $this->json->equals($old, $next)) {
                $page->forceFill(['draft_content' => $next, 'write_version' => $version + 1])->save();
            }
            if ($history !== null) {
                app(DraftHistory::class)->recordAccepted($history, $actor, $page, $token, $before, $page->draft_content, new LogicalAction('design', 'Change design'));
            }

            app(ContentDependencies::class)->syncPage($page);

            return new MutationResult($page->fresh(), $this->json->digest($page->fresh()->draft_content), $diagnostics);
        }, 3);
    }
}
