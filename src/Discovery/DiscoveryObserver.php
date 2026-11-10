<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Discovery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageSlug;
use SiteBuilder\Core\Rendering\LocaleCatalog;

final class DiscoveryObserver
{
    public function saved(Model $model): void
    {
        $this->refresh($model);
    }

    public function deleted(Model $model): void
    {
        $this->refresh($model);
    }

    private function refresh(Model $model): void
    {
        if (! app(ContentIndex::class)->available()) {
            return;
        }
        $id = $model instanceof Page ? $model->id : ($model instanceof PageSlug ? $model->page_id : null);
        DB::afterCommit(function () use ($id): void {
            foreach (app(LocaleCatalog::class)->all() as $locale) {
                try {
                    if ($id === null) {
                        app(ContentIndex::class)->rebuild($locale, 'builder.pages');
                    } else {
                        app(ContentIndex::class)->refresh('builder.pages', [(string) $id], $locale);
                    }
                } catch (\Throwable $exception) {
                    report($exception);
                    try {
                        app(ContentIndex::class)->discard('builder.pages', $locale, $id === null ? null : [(string) $id]);
                    } catch (\Throwable $cleanup) {
                        report($cleanup);
                    }
                }
            }
        });
    }
}
