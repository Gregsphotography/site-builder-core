<?php

declare(strict_types=1);

namespace SiteBuilder\Core;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use SiteBuilder\Core\Clipboard\ClipboardServiceProvider;
use SiteBuilder\Core\Commands\ExportCommand;
use SiteBuilder\Core\Commands\ImportCommand;
use SiteBuilder\Core\Commands\InstallCommand;
use SiteBuilder\Core\Commands\SavedSectionsInstallCommand;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\ReferenceScanner;
use SiteBuilder\Core\Composition\CompositionRenderFeature;
use SiteBuilder\Core\Composition\CompositionResolver;
use SiteBuilder\Core\Composition\GlobalReferenceDefinition;
use SiteBuilder\Core\Composition\StartingPointRegistry;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Contracts\MediaResolver;
use SiteBuilder\Core\Contracts\SiteMetadataProvider;
use SiteBuilder\Core\Design\DesignCatalog;
use SiteBuilder\Core\Design\DesignRenderFeature;
use SiteBuilder\Core\DraftHistory\PruneCommand;
use SiteBuilder\Core\Exchange\ExchangeSchema;
use SiteBuilder\Core\Exchange\LegacyDocuments;
use SiteBuilder\Core\Rendering\LayoutRegistry;
use SiteBuilder\Core\Rendering\PathMediaResolver;
use SiteBuilder\Core\Rendering\RenderFeatureRegistry;
use SiteBuilder\Core\Rendering\StoredSiteMetadata;
use SiteBuilder\Core\Routing\SiteRoutes;
use SiteBuilder\Core\SavedSections\SavedSectionExchange;
use SiteBuilder\Core\SiteParts\AuditCommand;
use SiteBuilder\Core\SiteParts\NativeGraphImportScope;
use SiteBuilder\Core\SiteParts\SitePartExchange;
use SiteBuilder\Core\Snapshots\SnapshotSourceRegistry;

final class SiteBuilderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Installation\SetupFilesystem::class);
        $this->app->singleton(Installation\SetupRegistry::class, function ($app): Installation\SetupRegistry {
            $registry = new Installation\SetupRegistry;
            $files = $app->make(Installation\SetupFilesystem::class);
            $registry->register(new Installation\CoreSetupContributor(new Installation\SetupInspection($files, $registry), $files, $registry));

            return $registry;
        });
        $this->app->singleton(NativeGraphImportScope::class);
        $this->app->register(ClipboardServiceProvider::class);
        $this->mergeConfigFrom(__DIR__.'/../config/site-builder.php', 'site-builder');
        $this->app->singleton(ComponentRegistry::class);
        $this->app->afterResolving(ComponentRegistry::class, fn (ComponentRegistry $registry) => $registry->register(GlobalReferenceDefinition::class));
        $this->app->singleton(ReferenceScanner::class);
        $this->app->singleton(Discovery\ContentTextRegistry::class);
        $this->app->singleton(Discovery\ContentSources::class, function ($app): Discovery\ContentSources {
            $sources = new Discovery\ContentSources;
            $sources->register('builder.pages', $app->make(Discovery\PublishedPagesSource::class));

            return $sources;
        });
        $this->app->tag([Discovery\DiscoveryConstraint::class], Contracts\DocumentConstraint::class);
        $this->app->singleton(StartingPointRegistry::class);
        $this->app->singleton(SnapshotSourceRegistry::class);
        $this->app->singleton(DesignCatalog::class);
        $this->app->singleton(CompositionResolver::class);
        $this->app->singleton(RenderFeatureRegistry::class, fn ($app): RenderFeatureRegistry => (new RenderFeatureRegistry($app->make(CanonicalJson::class)))->register($app->make(DesignRenderFeature::class))->register($app->make(CompositionRenderFeature::class)));
        $this->app->singleton(CanonicalJson::class);
        $this->app->singleton(LayoutRegistry::class);
        $this->app->singleton(Rendering\PublicAssetRegistry::class);
        $this->app->scoped(Rendering\PublicAssets::class);
        $this->app->tag([Composition\OverlayConstraint::class], Contracts\DocumentConstraint::class);
        $this->app->afterResolving(Rendering\PublicAssetRegistry::class, function ($registry): void {
            $digest = substr(hash_file('sha256', __DIR__.'/../resources/dist/overlays.js'), 0, 16);
            if (! $registry->has('core.overlays')) {
                $registry->register('core.overlays', new Rendering\PublicAsset('module', '/vendor/site-builder/overlays.js?v='.$digest));
            }
        });
        $this->app->singleton(ExchangeSchema::class);
        $this->app->afterResolving(ExchangeSchema::class, function (ExchangeSchema $schema): void {
            if (config('site-builder.site_parts.exchange', false)) {
                SitePartExchange::register($schema);
            }
            if (config('site-builder.saved_sections.exchange', false)) {
                SavedSectionExchange::register($schema, (bool) config('site-builder.saved_sections.preserve_actor_ownership', false));
            }
        });
        $this->app->singleton(LegacyDocuments::class);
        $this->app->bindIf(MediaResolver::class, PathMediaResolver::class);
        $this->app->bindIf(SiteMetadataProvider::class, StoredSiteMetadata::class);
        $this->app->bindIf(Contracts\BreadcrumbProvider::class, Rendering\PublishedBreadcrumbs::class);
        $this->app->singleton(DocumentCodec::class, fn ($app): DocumentCodec => new DocumentCodec(
            maxDepth: (int) $app['config']->get('site-builder.documents.max_depth', 32),
            maxNodes: (int) $app['config']->get('site-builder.documents.max_nodes', 1000),
            maxBytes: (int) $app['config']->get('site-builder.documents.max_bytes', 2097152),
        ));
    }

    public function boot(): void
    {
        foreach ([Models\Page::class, Models\PageSlug::class, SiteParts\SitePartRevision::class] as $model) {
            $model::observe(Discovery\DiscoveryObserver::class);
        }
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'site-builder');
        Blade::directive('siteBuilderAssets', fn (): string => '<?php echo app(\\SiteBuilder\\Core\\Rendering\\PublicAssets::class)->html(); ?>');
        $this->app->booted(function (): void {
            if (config('site-builder.routes.enabled', true) && ! $this->app->routesAreCached()) {
                SiteRoutes::register();
            }
        });
        if ($this->app->runningInConsole()) {
            $this->commands([Discovery\DiscoveryInstallCommand::class, Discovery\DiscoveryRebuildCommand::class, Discovery\DiscoveryDoctorCommand::class, Commands\SetupCommand::class, Commands\DoctorCommand::class, Commands\CanonicalCheckCommand::class, InstallCommand::class, ExportCommand::class, ImportCommand::class, SavedSectionsInstallCommand::class, DraftHistory\InstallCommand::class, PruneCommand::class, SiteParts\InstallCommand::class, AuditCommand::class, SiteParts\PruneCommand::class]);
            $this->publishes([__DIR__.'/../config/site-builder.php' => config_path('site-builder.php')], 'site-builder-config');
            $this->publishesMigrations([__DIR__.'/../database/discovery-migrations' => database_path('migrations')], 'site-builder-discovery-migrations');
            $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'site-builder-migrations');
            $this->publishesMigrations([__DIR__.'/../database/site-parts-migrations' => database_path('migrations')], 'site-builder-site-parts-migrations');
            $this->publishesMigrations([__DIR__.'/../database/history-migrations' => database_path('migrations')], 'site-builder-history-migrations');
            $this->publishesMigrations([__DIR__.'/../database/saved-sections-migrations' => database_path('migrations')], 'site-builder-saved-sections-migrations');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/site-builder')], 'site-builder-views');
            $this->publishes([__DIR__.'/../resources/dist' => public_path('vendor/site-builder')], ['site-builder-assets', 'site-builder-assets-update']);
        }
    }
}
