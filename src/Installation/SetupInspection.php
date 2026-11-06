<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Rendering\LayoutRegistry;
use SiteBuilder\Core\Routing\PageController;
use SiteBuilder\Core\Routing\PreviewController;
use SiteBuilder\Core\SiteParts\SitePart;

final class SetupInspection
{
    public function __construct(private SetupFilesystem $files, private SetupRegistry $registry) {}

    public function packages(): array
    {
        $result = [];
        foreach (['core', 'components', 'filament'] as $name) {
            $package = 'gregsphotography/site-builder-'.$name;
            if (InstalledVersions::isInstalled($package)) {
                $result[$name] = InstalledVersions::getPrettyVersion($package) ?? 'development';
            }
        }

        return ['core' => $result['core'] ?? 'development', ...$result];
    }

    public function files(string $directory): array
    {
        $path = $this->files->path($directory.'/setup-inspection');
        $directory = dirname($path);
        $result = [];
        if (! is_dir($directory)) {
            return [];
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($this->files->root) + 1);
            if (str_starts_with($relative, 'bootstrap/cache/') || in_array($file->getFilename(), ['.gitignore', '.gitkeep', '.DS_Store'], true)) {
                continue;
            }
            $result[$relative] = $this->files->hash($relative);
            if (count($result) > 5000) {
                throw new SetupFailure('inspection-limit', 'The application inspection file limit was exceeded.', 2);
            }
        }
        ksort($result);

        return $result;
    }

    public function core(): SetupDiscovery
    {
        $files = [];
        foreach (['app/Providers', 'app/Policies', 'config', 'routes', 'bootstrap', 'database/migrations'] as $directory) {
            foreach ($this->files($directory) as $path => $hash) {
                if (! str_starts_with($path, 'bootstrap/cache/')) {
                    $files[$path] = $hash;
                }
            }
        }
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $routes[] = ['uri' => $route->uri(), 'name' => $route->getName(), 'action' => $route->getActionName(), 'methods' => $route->methods(), 'domain' => $route->getDomain(), 'fallback' => $route->isFallback];
        }
        $schema = [];
        try {
            $builder = DB::connection()->getSchemaBuilder();
            foreach (['core' => ['pages', 'page_slugs', 'page_redirects', 'page_revisions', 'page_locks', 'settings', 'menus', 'import_runs', 'import_map'], 'media' => ['media_assets'], 'saved' => ['saved_sections', 'saved_section_versions'], 'clipboard' => ['clipboard_owners', 'clipboard_snapshots'], 'history' => ['draft_histories'], 'site-parts' => ['site_parts', 'site_part_revisions', 'site_part_locks', 'content_dependencies']] as $feature => $tables) {
                foreach ($tables as $table) {
                    $name = 'site_builder_'.$table;
                    $schema['tables'][$name] = $builder->hasTable($name) ? $builder->getColumnListing($name) : null;
                }
                $schema[$feature] = array_all($tables, fn ($table) => $schema['tables']['site_builder_'.$table] !== null);
            }
            $schema['migrations'] = $builder->hasTable(config('database.migrations.table', 'migrations')) ? DB::table(config('database.migrations.table', 'migrations'))->orderBy('migration')->pluck('migration')->all() : [];
            $schema['available'] = true;
        } catch (\Throwable) {
            $schema = ['available' => false];
        }
        $connection = config('database.connections.'.config('database.default'), []);
        $facts = ['php' => PHP_VERSION, 'laravel' => app()->version(), 'packages' => $this->packages(), 'files' => $files, 'routes' => $routes, 'schema' => $schema,
            'databaseIdentity' => app(CanonicalJson::class)->digest(array_intersect_key($connection, array_flip(['driver', 'host', 'port', 'database']))),
            'guards' => config('auth.guards', []), 'actors' => $this->registry->actors(), 'policies' => [Page::class => $this->policy(Page::class), SitePart::class => $this->policy(SitePart::class)],
            'locales' => config('site-builder.locales'), 'layout' => config('site-builder.layout'), 'routeConfig' => config('site-builder.routes'), 'cached' => app()->configurationIsCached() || app()->routesAreCached()];

        return new SetupDiscovery($facts);
    }

    public function policy(string $model): ?string
    {
        $policy = Gate::getPolicyFor($model);

        return is_object($policy) ? $policy::class : null;
    }

    /** Conservative intersection: unresolved wildcard/catch-all overlap requires manual review. */
    public function overlaps(string $prefix, array $route): bool
    {
        if ($this->builderRoute($route)) {
            return false;
        }
        if (str_starts_with($route['name'] ?? '', 'site-builder.')) {
            return true;
        }
        if (array_intersect($route['methods'], ['GET', 'HEAD']) === []) {
            return false;
        }
        if ($prefix === '' || $route['fallback']) {
            return true;
        }
        $wanted = explode('/', trim($prefix, '/'));
        $existing = explode('/', trim($route['uri'], '/'));
        foreach ($wanted as $index => $segment) {
            $actual = $existing[$index] ?? null;
            if ($actual === null) {
                return false;
            }
            if (str_contains($actual, '{')) {
                return true;
            }
            if ($actual !== $segment) {
                return false;
            }
        }

        return true;
    }

    public function builderRoute(array $route): bool
    {
        $controller = ['site-builder.page' => PageController::class, 'site-builder.preview' => PreviewController::class][$route['name'] ?? ''] ?? null;

        return $controller !== null && in_array($route['action'] ?? null, [$controller, $controller.'@__invoke'], true);
    }

    public function matchesAssets(string $source, string $destination): bool
    {
        foreach (SetupContribution::assets($source, $destination) as $file) {
            if ($this->files->hash($file->path) !== hash('sha256', $file->contents)) {
                return false;
            }
        }

        return true;
    }

    public function layoutCheck(SetupAnswers $answers): DoctorCheck
    {
        try {
            $layout = $answers->get('presentation.layout');
            if ($layout === 'site-builder::layouts.site') {
                if (! view()->exists($layout)) {
                    throw new \InvalidArgumentException;
                }
            } else {
                app(LayoutRegistry::class)->view($layout);
            }
        } catch (\Throwable) {
            return new DoctorCheck('config.layout', 'unavailable', 'layout-unavailable', 'The selected trusted layout is not registered/readable.', 'Register the existing host layout or install the selected component pack.');
        }
        $matches = config('site-builder.layout') === $answers->get('presentation.layout');

        return new DoctorCheck('config.layout', $matches ? 'pass' : 'action', $matches ? 'layout-ready' : 'layout-config-required', 'Selected layout registration is available.', $matches ? null : 'Apply or manually merge the reviewed builder layout configuration.');
    }
}
