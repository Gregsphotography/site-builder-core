<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\SiteBuilderServiceProvider;
use SiteBuilder\Core\Tests\TestCase;

class ProviderTest extends TestCase
{
    public function test_provider_services_and_views_work_without_creating_schema_or_publishing_files(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $provider = new SiteBuilderServiceProvider($this->app);
        $provider->register();
        $provider->boot();
        $document = app(DocumentCodec::class)->decode('{"format":"laravel-site-builder/document","version":1,"nodes":[],"extensions":{}}');
        $this->assertInstanceOf(Document::class, $document);
        $this->assertSame([], $queries);
        Route::get('/foundation-probe', fn () => view('site-builder::layouts.site', ['slot' => 'Independent core', 'title' => 'Core foundation']));
        $this->get('/foundation-probe')->assertOk()->assertSee('Independent core')->assertDontSee('site-builder.js');
        $this->assertSame(32, config('site-builder.documents.max_depth'));
    }

    public function test_explicit_install_refreshes_old_assets_but_preserves_host_config_and_migration_history(): void
    {
        $directory = sys_get_temp_dir().'/site-builder-install-'.bin2hex(random_bytes(8));
        $paths = [config_path(), public_path(), database_path()];
        try {
            $this->app->useConfigPath($directory.'/config');
            $this->app->usePublicPath($directory.'/public');
            $this->app->useDatabasePath($directory.'/database');
            File::ensureDirectoryExists(config_path());
            File::ensureDirectoryExists(public_path('vendor/site-builder'));
            File::ensureDirectoryExists(database_path('migrations'));
            file_put_contents(config_path('site-builder.php'), '<?php return ["host" => true];');
            file_put_contents(public_path('vendor/site-builder/preview.js'), 'old preview protocol');
            $migration = database_path('migrations/2020_01_01_000000_create_site_builder_tables.php');
            file_put_contents($migration, '<?php return ["host_migration" => true];');
            (new SiteBuilderServiceProvider($this->app))->boot();

            $this->artisan('site-builder:install')->assertSuccessful();
            $this->assertSame('<?php return ["host" => true];', file_get_contents(config_path('site-builder.php')));
            $this->assertSame('<?php return ["host_migration" => true];', file_get_contents($migration));
            $this->assertCount(2, File::files(database_path('migrations')));
            foreach (['preview.js', 'site-builder.css'] as $asset) {
                $this->assertSame(file_get_contents(__DIR__.'/../../resources/dist/'.$asset), file_get_contents(public_path('vendor/site-builder/'.$asset)));
            }
        } finally {
            $this->app->useConfigPath($paths[0]);
            $this->app->usePublicPath($paths[1]);
            $this->app->useDatabasePath($paths[2]);
            (new SiteBuilderServiceProvider($this->app))->boot();
            File::deleteDirectory($directory);
        }
    }

    public function test_optional_saved_section_installer_only_publishes_and_preserves_existing_migration_files(): void
    {
        $directory = sys_get_temp_dir().'/site-builder-saved-install-'.bin2hex(random_bytes(8));
        $previous = database_path();
        try {
            $this->app->useDatabasePath($directory.'/database');
            $this->artisan('site-builder:saved-sections-install')->assertSuccessful();
            $files = File::files(database_path('migrations'));
            $this->assertCount(1, $files);
            $this->assertStringContainsString('create_site_builder_saved_sections_tables', $files[0]->getFilename());
            file_put_contents($files[0]->getPathname(), '<?php return ["host_edit" => true];');
            $this->artisan('site-builder:saved-sections-install')->assertSuccessful();
            $this->assertCount(1, File::files(database_path('migrations')));
            $this->assertSame('<?php return ["host_edit" => true];', file_get_contents($files[0]->getPathname()));
            $this->assertFalse(DB::getSchemaBuilder()->hasTable('site_builder_saved_sections'));
        } finally {
            $this->app->useDatabasePath($previous);
            File::deleteDirectory($directory);
        }
    }
}
