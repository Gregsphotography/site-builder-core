<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

use Illuminate\Support\Facades\Gate;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\SiteParts\SitePart;

final class CoreSetupContributor implements SetupContributor
{
    public function __construct(private SetupInspection $inspection, private SetupFilesystem $files, private SetupRegistry $registry) {}

    public function id(): string
    {
        return 'core';
    }

    public function discover(): SetupDiscovery
    {
        return $this->inspection->core();
    }

    public function plan(SetupAnswers $answers, SetupDiscovery $discovery): SetupContribution
    {
        $settings = ['locales' => json_decode(json_encode($answers->get('locales')), true), 'layout' => $answers->get('presentation.layout'),
            'routes' => ['enabled' => $answers->get('routes.enabled'), 'prefix' => $answers->get('routes.prefix'), 'preview_prefix' => $answers->get('routes.previewPrefix'), 'public' => true, 'preview' => true, 'middleware' => ['web']],
            'site_parts' => ['exchange' => $answers->get('features.siteParts')], 'saved_sections' => ['exchange' => $answers->get('features.savedSections')]];
        $code = "<?php\n\nreturn ".var_export($settings, true).";\n";
        $setup = "<?php\n\n// Setup-owned intent. Actor keys are operator-selected data, never inferred privileges.\nreturn ['version' => 1, 'answers' => json_decode(".var_export(json_encode($answers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true).", false, 32, JSON_THROW_ON_ERROR)];\n";
        $files = [new SetupFile('config/site-builder.php', $code, 'Configure the selected locales, safe route subtree and layout.', manualIfExisting: true),
            new SetupFile('config/site-builder-setup.php', $setup, 'Store versioned operator intent and selected grants.', redacted: true),
            ...SetupContribution::assets(__DIR__.'/../../resources/dist', 'public/vendor/site-builder')];
        $namespace = app()->getNamespace();
        if (! preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+$/D', $namespace)) {
            throw new SetupFailure('namespace-unavailable', 'The application namespace cannot be generated safely.', 2);
        }
        if ($answers->get('registration.mode') === 'provider-stub') {
            $provider = "<?php\n\nnamespace ".$namespace."Providers;\n\nfinal class SiteBuilderSetupServiceProvider extends \\Illuminate\\Support\\ServiceProvider\n{\n    public function boot(): void\n    {\n        app(\\SiteBuilder\\Core\\Installation\\SetupRuntime::class)->boot();\n    }\n}\n";
            $files[] = new SetupFile('app/Providers/SiteBuilderSetupServiceProvider.php', $provider, 'Generate additive provider; bootstrap registration is a manual step.');
        }
        if (in_array($answers->get('authorization.mode'), ['allowlist', 'deny'], true)) {
            foreach (['Page', 'SitePart'] as $name) {
                $files[] = new SetupFile('app/Policies/SiteBuilder/'.$name.'Policy.php', "<?php\n\nnamespace ".$namespace."Policies\\SiteBuilder;\n\nfinal class ".$name.'Policy extends \\SiteBuilder\\Core\\Installation\\Setup'.$name."Policy {}\n", 'Generate isolated default-deny builder policy.');
            }
        }
        $sources = $answers->get('migrations') === 'publish' ? [__DIR__.'/../../database/migrations'] : [];
        foreach (['savedSections' => 'saved-sections', 'history' => 'history', 'clipboard' => 'clipboard', 'siteParts' => 'site-parts'] as $feature => $folder) {
            if ($answers->get('migrations') === 'publish' && $answers->get('features.'.$feature)) {
                $sources[] = __DIR__.'/../../database/'.$folder.'-migrations';
            }
        }

        return new SetupContribution($files, $sources);
    }

    public function checks(SetupAnswers $answers, SetupDiscovery $discovery): array
    {
        $facts = $discovery->facts;
        $checks = [new DoctorCheck('runtime.php', PHP_VERSION_ID >= 80500 && PHP_VERSION_ID < 90000 ? 'pass' : 'unavailable', 'php-range', 'Supported PHP range is ^8.5.'),
            new DoctorCheck('runtime.laravel', version_compare($facts['laravel'], '13.40.0', '>=') && version_compare($facts['laravel'], '14.0.0', '<') ? 'pass' : 'unavailable', 'laravel-range', 'Supported Laravel range is ^13.40.'),
            new DoctorCheck('packages.core', 'pass', 'core-installed', 'Core installation capabilities are registered.'), $this->inspection->layoutCheck($answers)];
        $locales = json_decode(json_encode(config('site-builder.locales')));
        $localeMatch = app(CanonicalJson::class)->equals($locales, $answers->get('locales'));
        $checks[] = new DoctorCheck('config.locales', $localeMatch ? 'pass' : 'action', $localeMatch ? 'locales-ready' : 'locale-config-required', 'Content locale map must match the explicit selection.', $localeMatch ? null : 'Merge all selected locales, labels and directions into builder config.');
        foreach (['public' => $answers->get('routes.prefix'), 'preview' => $answers->get('routes.previewPrefix')] as $name => $prefix) {
            $publicPrefix = $answers->get('routes.prefix');
            $previewPrefix = $answers->get('routes.previewPrefix');
            $selectedOverlap = $publicPrefix !== '' && ($publicPrefix === $previewPrefix || str_starts_with($previewPrefix.'/', $publicPrefix.'/') || str_starts_with($publicPrefix.'/', $previewPrefix.'/'));
            $overlap = $answers->get('routes.enabled') && ($selectedOverlap || array_any($facts['routes'], fn ($route) => $this->inspection->overlaps($prefix, $route)));
            $configKey = $name === 'public' ? 'prefix' : 'preview_prefix';
            $routeName = $name === 'public' ? 'site-builder.page' : 'site-builder.preview';
            $expectedUri = $name === 'preview' ? $prefix.'/{page}/{locale}' : ($prefix === '' ? '{fallbackPlaceholder}' : $prefix.'/{siteBuilderPath?}');
            $registered = array_values(array_filter($facts['routes'], fn ($route) => $route['name'] === $routeName && $this->inspection->builderRoute($route)));
            $routeMatches = $answers->get('routes.enabled') ? count($registered) === 1 && $registered[0]['uri'] === $expectedUri && in_array('GET', $registered[0]['methods'], true) : $registered === [];
            $match = $routeMatches && config('site-builder.routes.enabled', true) === $answers->get('routes.enabled') && config('site-builder.routes.'.$configKey, $name === 'public' ? '' : '_site-builder/preview') === $prefix;
            $checks[] = new DoctorCheck('routes.'.$name, $overlap || ! $match ? 'action' : ($answers->get('routes.enabled') ? 'pass' : 'not-applicable'), $overlap ? 'route-conflict' : ($match ? 'routes-ready' : 'route-config-required'), $overlap ? 'An existing GET route or catch-all overlaps the selected builder subtree.' : 'Actual registered routes and configuration must match the explicit selection.', $overlap ? 'Choose a disjoint subtree or complete explicit manual route integration.' : ($match ? null : 'Merge reviewed route settings and rebuild cached routes explicitly.'));
        }
        $checks[] = new DoctorCheck('assets.core', $this->inspection->matchesAssets(__DIR__.'/../../resources/dist', 'public/vendor/site-builder') ? 'pass' : 'action', 'core-assets', 'Installed core compiled assets must be published.', 'Apply reviewed asset publication when hashes differ.');
        foreach (['core' => true, 'saved' => $answers->get('features.savedSections'), 'clipboard' => $answers->get('features.clipboard'), 'history' => $answers->get('features.history'), 'site-parts' => $answers->get('features.siteParts')] as $feature => $selected) {
            $status = ! $selected ? 'not-applicable' : (! $facts['schema']['available'] ? 'unavailable' : (! empty($facts['schema'][$feature]) ? 'pass' : 'action'));
            $checks[] = new DoctorCheck('schema.'.$feature, $status, $status === 'unavailable' ? 'database-unavailable' : 'schema-state', $status === 'unavailable' ? 'Database inspection is unavailable; connection details are not disclosed.' : 'Selected builder schema readiness.', $status === 'action' ? 'Publish then explicitly run the reviewed package migrations.' : null);
        }
        $checks = [...$checks, ...$this->authorization($answers, $discovery)];
        $guard = array_key_exists($answers->get('authorization.guard'), $facts['guards']);
        $checks[] = new DoctorCheck('auth.guard', $guard ? 'pass' : 'unavailable', $guard ? 'guard-ready' : 'guard-unavailable', 'The selected authentication guard must already exist.', $guard ? null : 'Select a configured host guard before setup.');
        $active = app()->bound('site-builder.setup.active');
        $checks[] = new DoctorCheck('installation.manual-actions', $active ? 'pass' : 'action', $active ? 'provider-active' : 'provider-registration-required', $active ? 'Explicit setup runtime integration is active.' : 'Setup provider/runtime registration remains a host-owned step.', $active ? null : 'Register the generated provider in bootstrap/providers.php, or call SetupRuntime::boot() from an existing trusted provider.');

        return $checks;
    }

    private function authorization(SetupAnswers $answers, SetupDiscovery $discovery): array
    {
        $checks = [];
        foreach (['page' => Page::class, 'site-parts' => SitePart::class] as $name => $model) {
            if ($name === 'site-parts' && ! $answers->get('features.siteParts')) {
                $checks[] = new DoctorCheck('auth.'.$name, 'not-applicable', 'feature-declined', 'Site-part authoring was not selected.');

                continue;
            }
            $probe = $this->registry->check('auth.'.$name, $answers);
            if ($probe !== null) {
                $checks[] = $probe;

                continue;
            }
            $policy = Gate::getPolicyFor($model);
            $mode = $answers->get('authorization.mode');
            $ready = false;
            if ($mode === 'allowlist' && $policy instanceof SetupPagePolicy && $name === 'page') {
                $actors = $this->registry->selectedActors($answers);
                $ready = $actors !== [] && array_all($actors, fn ($actor) => array_all(['view', 'create', 'update', 'preview', 'publish'], fn ($ability) => app(SetupAuthorization::class)->allows($actor, $ability, 'page')));
            } elseif ($mode === 'allowlist' && $policy instanceof SetupSitePartPolicy && $name === 'site-parts') {
                $actors = $this->registry->selectedActors($answers);
                $grants = (array) $answers->get('authorization.sitePartAbilities');
                $ready = $actors !== [] && $grants !== [] && array_all($grants, fn ($abilities, $kind) => array_diff(['view', 'create', 'update', 'preview', 'publish', 'use'], $abilities) === [] && array_all($actors, fn ($actor) => array_all(['view', 'create', 'update', 'preview', 'publish', 'use'], fn ($ability) => app(SetupAuthorization::class)->allows($actor, $ability, 'site-part', $kind))));
            }
            $checks[] = new DoctorCheck('auth.'.$name, $ready ? 'pass' : 'action', $ready ? 'explicit-grants-ready' : 'authorization-workflow-required', $ready ? 'Explicit existing-actor grants are active; publication still requires its own command.' : 'Authoring readiness needs explicit policies/grants and a real owner workflow.', $ready ? null : 'Complete host policy integration. Existing context-sensitive policies can register a read-only authorization workflow probe.');
        }

        return $checks;
    }
}
