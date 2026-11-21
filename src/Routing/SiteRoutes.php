<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Routing;

use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use SiteBuilder\Core\Discovery\SearchController;
use SiteBuilder\Core\Publishing\ClientReviewController;

final class SiteRoutes
{
    public static function register(): void
    {
        Route::middleware(config('site-builder.routes.middleware', ['web']))->group(function (): void {
            if (config('site-builder.discovery.enabled', false)) {
                Route::get(self::publicPrefix().'/_site-builder/search/suggest', SearchController::class)->middleware('throttle:60,1')->name('site-builder.search.suggest');
            }
            if (config('site-builder.routes.preview', true)) {
                $review = self::previewPrefix().'/review/{review}';
                Route::get($review, [ClientReviewController::class, 'show'])->whereUuid('review')->name('site-builder.review');
                Route::post($review, [ClientReviewController::class, 'decide'])->whereUuid('review')->middleware('throttle:20,1')->name('site-builder.review.decide');
                Route::get($review.'/{locale}', [ClientReviewController::class, 'preview'])->whereUuid('review')->name('site-builder.review.preview');
                Route::get(self::previewPrefix().'/{page}/{locale}', PreviewController::class)->name('site-builder.preview');
            }
            if (config('site-builder.routes.public', true)) {
                $prefix = self::publicPrefix();
                if ($prefix !== '') {
                    // A reserved subtree must run before the host's catch-all 404.
                    // Explicit host routes registered before provider boot still win.
                    Route::get($prefix.'/{siteBuilderPath?}', PageController::class)->where('siteBuilderPath', '.*')->name('site-builder.page');

                    return;
                }
                $uri = trim($prefix.'/{fallbackPlaceholder}', '/');
                // Never replace a host's existing fallback with the same URI.
                foreach (Route::getRoutes()->getRoutes() as $route) {
                    if ($route->isFallback && $route->uri() === $uri) {
                        return;
                    }
                }
                Route::prefix($prefix)->group(fn () => Route::fallback(PageController::class)->name('site-builder.page'));
            }
        });
    }

    public static function publicPrefix(): string
    {
        return self::prefix(config('site-builder.routes.prefix', ''));
    }

    public static function previewPrefix(): string
    {
        return self::prefix(config('site-builder.routes.preview_prefix', '_site-builder/preview'));
    }

    private static function prefix(mixed $value): string
    {
        if (! is_string($value) || ($value !== '' && ! preg_match('~^/?[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*/?$~D', $value))) {
            throw new InvalidArgumentException('Builder route prefixes must be trusted slash-separated path segments.');
        }

        return ($trimmed = trim($value, '/')) === '' ? '' : '/'.$trimmed;
    }
}
