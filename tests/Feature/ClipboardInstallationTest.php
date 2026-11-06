<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Clipboard\ClipboardCommands;
use SiteBuilder\Core\Tests\TestCase;

class ClipboardInstallationTest extends TestCase
{
    private string $installationPath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $this->installationPath = sys_get_temp_dir().'/site-builder-clipboard-install-'.Str::uuid();
        $app->useDatabasePath($this->installationPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->installationPath);
        parent::tearDown();
    }

    public function test_boot_is_read_only_and_optional_install_is_idempotent_without_running_schema_changes(): void
    {
        $clipboard = app(ClipboardCommands::class);
        $this->assertFalse($clipboard->available());
        $this->assertFalse(is_dir($this->installationPath));
        $this->assertSame(0, $clipboard->prune());
        try {
            $clipboard->capture(new GenericUser(['id' => 7]), 1, (string) Str::uuid(), 0, 'root', (string) Str::uuid(), 'en');
            $this->fail('Missing optional storage must yield an actionable installation diagnostic.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('site-builder:clipboard-install', $exception->getMessage());
        }
        $this->artisan('site-builder:clipboard-install')->assertExitCode(0);
        $paths = glob($this->installationPath.'/migrations/*_create_site_builder_clipboard_tables.php');
        $this->assertCount(1, $paths);
        $digest = hash_file('sha256', $paths[0]);
        $this->artisan('site-builder:clipboard-install')->assertExitCode(0);
        $this->assertSame($paths, glob($this->installationPath.'/migrations/*_create_site_builder_clipboard_tables.php'));
        $this->assertSame($digest, hash_file('sha256', $paths[0]));
        $this->assertFalse(Schema::hasTable('site_builder_clipboard_snapshots'));
        $this->assertFalse(Schema::hasTable('site_builder_pages'));
    }
}
