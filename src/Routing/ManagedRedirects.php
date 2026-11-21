<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Routing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageRedirect;
use SiteBuilder\Core\Models\PageSlug;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ManagedRedirects
{
    public const GATE = 'manageSiteBuilderRedirects';

    public const STATUSES = [301, 302, 307, 308];

    public function __construct(private LocaleCatalog $locales, private PageUrlResolver $urls, private MissingPaths $missing) {}

    /** Page publication and manual redirect edits must agree on ownership of a locale/path. */
    public static function lockRoutes(): void
    {
        // Hosts upgrading their package before publishing migrations can still edit pages.
        if (Schema::hasTable('site_builder_route_locks')) {
            DB::table('site_builder_route_locks')->where('name', 'redirects')->lockForUpdate()->first();
        }
    }

    public static function version(PageRedirect $rule): string
    {
        return hash('sha256', json_encode([$rule->id, $rule->locale, $rule->from_slug, (string) $rule->page_id, $rule->status, $rule->getRawOriginal('updated_at')], JSON_THROW_ON_ERROR));
    }

    public function save(Authenticatable $actor, string $locale, string $source, int $target, int $status, ?int $id = null, ?string $version = null): PageRedirect
    {
        $this->authorizeManager($actor);
        $source = str_starts_with($source, '/') ? substr($source, 1) : $source;
        if (! $this->locales->has($locale) || ! RedirectPath::usable($source)) {
            throw ValidationException::withMessages(['source' => 'Choose a configured language and enter a local path, such as previous/services or old.html.']);
        }
        if (! in_array($status, self::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Choose a supported redirect status.']);
        }
        // An explicit host route always wins over the builder fallback.
        try {
            $route = Route::getRoutes()->match(Request::create(url(RedirectPath::forDisplay($locale, $source)), 'GET'));
            if ($route->getName() !== 'site-builder.page') {
                throw ValidationException::withMessages(['source' => 'This path belongs to a host route.']);
            }
        } catch (NotFoundHttpException) {
            throw ValidationException::withMessages(['source' => 'The builder does not serve this path.']);
        }

        return DB::transaction(function () use ($actor, $locale, $source, $target, $status, $id, $version): PageRedirect {
            self::lockRoutes();
            $rule = $id === null ? new PageRedirect : $this->editable($actor, $id, $version);
            $page = Page::query()->where('status', 'published')->find($target);
            if ($page === null || $this->urls->path($page, $locale) === null) {
                throw ValidationException::withMessages(['target' => 'Choose a published page with a URL in this language.']);
            }
            $this->authorizePage($actor, $page);
            if (PageSlug::query()->where(['locale' => $locale, 'slug' => $source])->exists()) {
                throw ValidationException::withMessages(['source' => 'A page owns this URL. Choose a different source.']);
            }
            $rule->fill(['locale' => $locale, 'from_slug' => $source, 'page_id' => $target, 'status' => $status]);
            try {
                $rule->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['source' => 'A redirect already exists for this URL. Open it to change its destination.']);
            }
            $this->missing->forget($locale, $source);

            return $rule->refresh();
        }, 3);
    }

    public function remove(Authenticatable $actor, int $id, string $version): void
    {
        $this->authorizeManager($actor);
        DB::transaction(function () use ($actor, $id, $version): void {
            self::lockRoutes();
            $this->editable($actor, $id, $version)->delete();
        }, 3);
    }

    private function editable(Authenticatable $actor, int $id, ?string $version): PageRedirect
    {
        $rule = PageRedirect::query()->lockForUpdate()->find($id);
        if ($rule === null) {
            throw ValidationException::withMessages(['source' => 'The redirect was removed. Refresh the list.']);
        }
        $this->authorizePage($actor, $rule->page()->withoutGlobalScope('active')->firstOrFail());
        if ($version === null || ! hash_equals(self::version($rule), $version)) {
            throw ValidationException::withMessages(['source' => 'The redirect changed. Reopen it before saving or removing it.']);
        }

        return $rule;
    }

    private function authorizePage(Authenticatable $actor, Page $page): void
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('view', $page);
        $gate->authorize('publish', $page);
    }

    public function authorizeManager(Authenticatable $actor): void
    {
        $gate = Gate::forUser($actor);
        $gate->authorize(self::GATE);
        $gate->authorize('viewAny', Page::class);
        if (! $this->missing->available()) {
            throw ValidationException::withMessages(['source' => 'Install the core route-reporting migration before managing redirects.']);
        }
    }
}
