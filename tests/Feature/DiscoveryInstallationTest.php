<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use SiteBuilder\Core\Tests\TestCase;

class DiscoveryInstallationTest extends TestCase
{
    private string $installationPath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $this->installationPath = sys_get_temp_dir().'/site-builder-discovery-install-'.Str::uuid();
        $app->useDatabasePath($this->installationPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->installationPath);
        parent::tearDown();
    }

    public function test_repeated_install_preserves_pending_and_applied_migration_identities(): void
    {
        $this->assertFalse(Schema::hasTable('site_builder_content_index'));
        $this->artisan('site-builder:discovery-install')->assertExitCode(0);
        $files = glob($this->installationPath.'/migrations/*_create_site_builder_content_index.php');
        $this->assertCount(1, $files);
        $renamed = $this->installationPath.'/migrations/2020_01_01_000000_create_site_builder_content_index.php';
        rename($files[0], $renamed);
        $digest = hash_file('sha256', $renamed);

        $this->artisan('site-builder:discovery-install')->assertExitCode(0);
        $this->assertSame([$renamed], glob($this->installationPath.'/migrations/*.php'));
        $this->assertSame($digest, hash_file('sha256', $renamed));
        $this->assertFalse(Schema::hasTable('site_builder_content_index'));
        $this->artisan('migrate', ['--path' => $this->installationPath.'/migrations', '--realpath' => true, '--force' => true])->assertExitCode(0);
        foreach (['site_builder_content_index', 'site_builder_content_tags', 'site_builder_content_index_lock'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->artisan('site-builder:discovery-install')->assertExitCode(0);
        $this->artisan('migrate', ['--path' => $this->installationPath.'/migrations', '--realpath' => true, '--force' => true])->assertExitCode(0);
        $this->assertSame([$renamed], glob($this->installationPath.'/migrations/*.php'));
        $this->assertSame($digest, hash_file('sha256', $renamed));
    }
}
