<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use SiteBuilder\Core\Commands\SetupCommand;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Installation\CoreSetupContributor;
use SiteBuilder\Core\Installation\SetupAnswers;
use SiteBuilder\Core\Installation\SetupContribution;
use SiteBuilder\Core\Installation\SetupFailure;
use SiteBuilder\Core\Installation\SetupFilesystem;
use SiteBuilder\Core\Installation\SetupInspection;
use SiteBuilder\Core\Installation\SetupManager;
use SiteBuilder\Core\Installation\SetupRegistry;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

class SetupTest extends DatabaseTestCase
{
    private SetupFilesystem $files;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/site-builder-setup-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->files = new class($this->directory) extends SetupFilesystem
        {
            public ?string $failAt = null;

            public function write(string $path, string $bytes, ?string $expected, int $mode = 0644): void
            {
                if ($path === $this->failAt) {
                    throw new SetupFailure('simulated-write-failure', 'Simulated write failure.', 70);
                }
                parent::write($path, $bytes, $expected, $mode);
            }
        };
        app()->instance(SetupFilesystem::class, $this->files);
        config(['site-builder.routes.prefix' => 'builder']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function answers(): SetupAnswers
    {
        $value = json_decode(file_get_contents(__DIR__.'/../Fixtures/setup-custom.json'));
        $value->presentation->layout = 'site-builder::layouts.site';
        $value->registration->mode = 'provider-stub';
        $value->authorization->guard = 'web';
        $value->migrations = 'publish';

        return new SetupAnswers($value);
    }

    private function fingerprint(): array
    {
        $result = [];
        foreach (File::allFiles($this->directory) as $file) {
            $result[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }
        ksort($result);

        return $result;
    }

    public function test_plan_and_doctor_are_versioned_deterministic_and_read_only(): void
    {
        $manager = app(SetupManager::class);
        $before = $this->fingerprint();
        $schema = Schema::getConnection()->getDriverName() === 'mysql'
            ? Schema::getConnection()->getDatabaseName() : 'main';
        $tables = Schema::getTableListing($schema, false);
        $plan = $manager->plan($this->answers());
        $this->assertSame('ready-to-apply', $plan->report['status'], json_encode(app(SetupInspection::class)->core()->facts['routes']));
        $this->assertSame($plan->report['digest'], $manager->plan($this->answers())->report['digest']);
        $projection = $plan->report;
        unset($projection['digest']);
        $this->assertSame(app(CanonicalJson::class)->digest($projection), $plan->report['digest']);
        $doctor = $manager->doctor($this->answers());
        $this->assertSame('needs-action', $doctor['status']);
        $this->assertSame($before, $this->fingerprint());
        $this->assertSame($tables, Schema::getTableListing($schema, false));
        $this->assertStringNotContainsString($this->directory, json_encode([$plan, $doctor]));
    }

    public function test_answers_are_strict_and_do_not_expose_mutable_internal_objects(): void
    {
        $value = $this->answers()->jsonSerialize();
        $answers = new SetupAnswers($value);
        $digest = $answers->digest();
        $value->routes->prefix = 'modified';
        $answers->get('routes')->prefix = 'also-modified';
        $this->assertSame($digest, $answers->digest());
        $value = $answers->jsonSerialize();
        $value->password = 'never-disclose-this';
        try {
            new SetupAnswers($value);
            $this->fail('Unknown credential fields must be rejected.');
        } catch (SetupFailure $failure) {
            $this->assertSame(64, $failure->exitStatus);
            $this->assertStringNotContainsString('never-disclose-this', $failure->getMessage());
        }
        $value = $answers->jsonSerialize();
        $value->version = 2;
        $this->expectException(SetupFailure::class);
        new SetupAnswers($value);
    }

    public function test_laravel_cache_housekeeping_is_ignored_without_being_changed(): void
    {
        mkdir($this->directory.'/bootstrap/cache', 0755, true);
        file_put_contents($this->directory.'/bootstrap/cache/.gitignore', "*\n!.gitignore\n");
        file_put_contents($this->directory.'/bootstrap/cache/config.php', '<?php return [];');
        $before = $this->fingerprint();
        $manager = app(SetupManager::class);
        $this->assertSame('ready-to-apply', $manager->plan($this->answers())->report['status']);
        $manager->doctor($this->answers());
        $this->assertSame($before, $this->fingerprint());
        $this->assertSame([], app(SetupInspection::class)->core()->facts['files']);
    }

    public function test_apply_is_additive_idempotent_and_keeps_receipts_private(): void
    {
        $this->files->write('app/Providers/HostProvider.php', '<?php // host', null);
        $this->files->write('public/vendor/unrelated.txt', 'host asset', null);
        $manager = app(SetupManager::class);
        $plan = $manager->plan($this->answers());
        $receipt = $manager->apply($plan);
        $this->assertSame('complete', $receipt['status']);
        $this->assertSame('needs-action', $receipt['readiness']);
        $this->assertSame('<?php // host', $this->files->read('app/Providers/HostProvider.php'));
        $this->assertSame('host asset', $this->files->read('public/vendor/unrelated.txt'));
        $owned = $this->files->owned();
        $before = array_map(fn ($path) => $this->files->hash($path), array_keys($owned));
        $repeat = $manager->apply($manager->plan($this->answers()));
        $this->assertSame(['unchanged'], array_values(array_unique(array_column($repeat['outcomes'], 'status'))));
        $this->assertSame($before, array_map(fn ($path) => $this->files->hash($path), array_keys($owned)));
        $receiptPath = $this->directory.'/storage/app/site-builder/setup/receipts/'.$receipt['runId'].'.json';
        $this->assertSame(0600, fileperms($receiptPath) & 0777);
        $this->assertSame(0700, fileperms(dirname($receiptPath)) & 0777);
        $this->assertSame(0, Page::query()->count());
    }

    public function test_existing_config_stays_manual_and_matching_legacy_assets_are_not_claimed(): void
    {
        $this->files->write('config/site-builder.php', '<?php return ["host" => true];', null);
        $source = new \ReflectionClass(CoreSetupContributor::class);
        $assets = SetupContribution::assets(dirname($source->getFileName()).'/../../resources/dist', 'public/vendor/site-builder');
        $asset = $assets[0];
        $this->files->write($asset->path, $asset->contents, null);
        $manager = app(SetupManager::class);
        $plan = $manager->plan($this->answers());
        $this->assertSame('manual', array_column($plan->report['operations'], 'action', 'path')['config/site-builder.php']);
        $manager->apply($plan);
        $this->assertSame('<?php return ["host" => true];', $this->files->read('config/site-builder.php'));
        $this->assertArrayNotHasKey($asset->path, $this->files->owned());
    }

    public function test_reviewed_plan_rejects_host_drift_before_any_writes(): void
    {
        $manager = app(SetupManager::class);
        $plan = $manager->plan($this->answers());
        $this->files->write('routes/web.php', '<?php // new host route', null);
        $before = $this->fingerprint();
        try {
            $manager->apply($plan);
            $this->fail('Stale plan accepted.');
        } catch (SetupFailure $failure) {
            $this->assertSame('plan-changed', $failure->reason);
            $this->assertSame(3, $failure->exitStatus);
        }
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_changed_owned_files_block_reapplication_without_reclaiming_them(): void
    {
        $manager = app(SetupManager::class);
        $manager->apply($manager->plan($this->answers()));
        file_put_contents($this->directory.'/config/site-builder.php', '<?php // host manual merge');
        $plan = $manager->plan($this->answers());
        $this->assertSame('blocked', $plan->report['status']);
        $this->expectException(SetupFailure::class);
        $manager->apply($plan);
    }

    public function test_partial_asset_failure_records_completed_work_and_resumes_without_duplicates(): void
    {
        $manager = app(SetupManager::class);
        $plan = $manager->plan($this->answers());
        $asset = array_values(array_filter($plan->report['operations'], fn ($op) => str_starts_with($op['path'] ?? '', 'public/')))[0]['path'];
        $this->files->failAt = $asset;
        try {
            $manager->apply($plan);
            $this->fail('Failure was not surfaced.');
        } catch (SetupFailure $failure) {
            $this->assertSame(70, $failure->exitStatus);
        }
        $partial = $manager->lastReceipt;
        $this->assertSame('partial', $partial['status']);
        $this->assertContains('applied', array_column($partial['outcomes'], 'status'));
        $this->assertContains('failed', array_column($partial['outcomes'], 'status'));
        $this->assertContains('pending', array_column($partial['outcomes'], 'status'));
        $configHash = $this->files->hash('config/site-builder-setup.php');
        $this->files->failAt = null;
        $resumed = $manager->apply($manager->plan($this->answers()), $plan->report['digest'], $partial['runId']);
        $this->assertSame('complete', $resumed['status']);
        $this->assertSame($partial['runId'], $resumed['runId']);
        $this->assertSame($configHash, $this->files->hash('config/site-builder-setup.php'));
    }

    public function test_resume_refuses_external_schema_changes(): void
    {
        $manager = app(SetupManager::class);
        $plan = $manager->plan($this->answers());
        $this->files->failAt = 'config/site-builder-setup.php';
        try {
            $manager->apply($plan);
        } catch (SetupFailure) {
        }
        Schema::table('site_builder_pages', fn ($table) => $table->string('host_change')->nullable());
        $this->files->failAt = null;
        try {
            $manager->apply($manager->plan($this->answers()), resume: $manager->lastReceipt['runId']);
            $this->fail('Changed schema accepted.');
        } catch (SetupFailure $failure) {
            $this->assertSame('resume-changed', $failure->reason);
        }
    }

    public function test_migration_failure_is_journalled_and_resume_runs_only_selected_package_paths(): void
    {
        $manager = app(SetupManager::class);
        $plan = $manager->plan($this->answers(), true);
        $kernel = Artisan::getFacadeRoot();
        $mock = \Mockery::mock(Kernel::class);
        Artisan::swap($mock);
        $mock->shouldReceive('call')->once()->with('migrate', \Mockery::on(fn ($options) => $options['--path'] === $plan->migrationPaths && ! $options['--force'] && $options['--no-interaction']), \Mockery::type(BufferedOutput::class))->andReturn(1);
        try {
            $manager->apply($plan);
            $this->fail('Migration failure accepted.');
        } catch (SetupFailure $failure) {
            $this->assertSame(70, $failure->exitStatus);
        }
        $this->assertSame('migration-failed', array_column($manager->lastReceipt['outcomes'], 'code', 'id')['run-migrations']);
        $mock->shouldReceive('call')->once()->with('migrate', \Mockery::on(fn ($options) => $options['--path'] === $plan->migrationPaths), \Mockery::type(BufferedOutput::class))->andReturn(0);
        $receipt = $manager->apply($manager->plan($this->answers(), true), resume: $manager->lastReceipt['runId']);
        $this->assertSame('complete', $receipt['status']);
        Artisan::swap($kernel);
    }

    public function test_symlink_escape_is_rejected_without_touching_target(): void
    {
        $outside = sys_get_temp_dir().'/site-builder-outside-'.bin2hex(random_bytes(8));
        mkdir($outside);
        symlink($outside, $this->directory.'/config');
        try {
            app(SetupManager::class)->plan($this->answers());
            $this->fail('Symlink accepted.');
        } catch (SetupFailure $failure) {
            $this->assertSame(3, $failure->exitStatus);
            $this->assertSame([], File::files($outside));
        } finally {
            unlink($this->directory.'/config');
            rmdir($outside);
        }
    }

    public function test_route_patterns_and_reserved_names_are_blocking(): void
    {
        Route::get('builder/{any}', fn () => 'host')->where('any', '.*');
        $plan = app(SetupManager::class)->plan($this->answers());
        $this->assertSame('blocked', $plan->report['status']);
        $this->assertContains('route-conflict', array_map(fn ($check) => $check->code, $plan->report['checks']));
        $this->assertSame([], $this->fingerprint());
    }

    public function test_doctor_checks_actual_cached_route_registration_and_explicit_disabling(): void
    {
        $manager = app(SetupManager::class);
        $report = $manager->doctor($this->answers());
        $checks = array_column(array_map(fn ($check) => $check->jsonSerialize(), $report['checks']), null, 'id');
        // The application booted its original root route before the test changed the config prefix.
        $this->assertSame('action', $checks['routes.public']['status']);
        $value = $this->answers()->jsonSerialize();
        $value->routes->enabled = false;
        config(['site-builder.routes.enabled' => false]);
        $report = $manager->doctor(new SetupAnswers($value));
        $checks = array_column(array_map(fn ($check) => $check->jsonSerialize(), $report['checks']), null, 'id');
        $this->assertSame('action', $checks['routes.public']['status']);
        $this->assertSame('action', $checks['routes.preview']['status']);
        $this->assertSame([], $this->fingerprint());
    }

    public function test_cli_invalid_modes_versions_and_missing_answers_fail_before_writes(): void
    {
        $this->artisan('site-builder:setup', ['--plan' => true, '--apply' => true, '--no-interaction' => true])->assertExitCode(64);
        $this->artisan('site-builder:setup', ['--run-migrations' => true, '--no-interaction' => true])->assertExitCode(64);
        $this->artisan('site-builder:setup', ['--profile' => 'custom', '--plan' => true, '--json' => true, '--no-interaction' => true])->assertExitCode(64);
        $this->artisan('site-builder:setup', ['--apply' => true, '--resume' => '../oops', '--no-interaction' => true])->assertExitCode(64);
        $this->assertSame([], $this->fingerprint());
    }

    public function test_production_migrations_need_force_before_writes(): void
    {
        $manager = app(SetupManager::class);
        $plan = $manager->plan($this->answers(), true);
        app()->instance('env', 'production');
        try {
            $manager->apply($plan);
            $this->fail('Production migrations ran without force.');
        } catch (SetupFailure $failure) {
            $this->assertSame('production-force-required', $failure->reason);
        }
        $this->assertSame([], $this->fingerprint());
    }

    public function test_real_console_error_output_is_stderr_and_does_not_corrupt_json_stdout(): void
    {
        $command = app(SetupCommand::class);
        $command->setLaravel(app());
        $tester = new CommandTester($command);
        $tester->execute(['--plan' => true, '--apply' => true, '--json' => true], ['interactive' => false, 'capture_stderr_separately' => true]);
        $this->assertSame(64, $tester->getStatusCode());
        $this->assertSame('', $tester->getDisplay());
        $this->assertStringContainsString('conflicting-mode:', $tester->getErrorOutput());
        $this->assertSame([], $this->fingerprint());
    }

    public function test_interactive_wizard_declines_privileges_and_finishes_a_read_only_plan(): void
    {
        $command = app(SetupCommand::class);
        $command->setLaravel(app());
        $tester = new CommandTester($command);
        $tester->setInputs(['existing', 'en', 'English', 'ltr', 'yes', 'builder', '_site-builder/preview', 'site-builder::layouts.site', 'no', 'no', 'no', 'no', 'no', 'no', 'existing', 'web', 'provider-stub', 'publish']);
        $tester->execute(['--profile' => 'custom', '--plan' => true], ['interactive' => true]);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('ready-to-apply', $tester->getDisplay());
        $this->assertStringContainsString('authorization-workflow-required', $tester->getDisplay());
        $this->assertSame([], $this->fingerprint());
    }

    public function test_opt_in_sample_remains_a_single_draft_across_repeats_and_resumes(): void
    {
        $actor = new GenericUser(['id' => 'fixture-actor']);
        Gate::policy(Page::class, SetupSamplePolicy::class);
        app(SetupRegistry::class)->sampleActor(fn () => $actor);
        $value = $this->answers()->jsonSerialize();
        $value->features->sampleDraft = true;
        $answers = new SetupAnswers($value);
        $manager = app(SetupManager::class);
        $receipt = $manager->apply($manager->plan($answers));
        $this->assertSame('complete', $receipt['status']);
        $page = Page::query()->sole();
        $this->assertSame('draft', $page->status);
        $this->assertNull($page->published_content);
        $manager->apply($manager->plan($answers));
        $this->assertSame(1, Page::query()->count());
        $manager->apply($manager->plan($answers), resume: $receipt['runId']);
        $this->assertSame(1, Page::query()->count());
    }

    public function test_concurrent_apply_is_rejected_before_mutating_reviewed_files(): void
    {
        $manager = app(SetupManager::class);
        $plan = $manager->plan($this->answers());
        $lock = $this->files->lock();
        try {
            $before = $this->fingerprint();
            try {
                $manager->apply($plan);
                $this->fail('Concurrent apply accepted.');
            } catch (SetupFailure $failure) {
                $this->assertSame('setup-busy', $failure->reason);
                $this->assertSame(3, $failure->exitStatus);
            }
            $this->assertSame($before, $this->fingerprint());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_uncertain_sample_marker_refuses_duplicate_creation(): void
    {
        $actor = new GenericUser(['id' => 'fixture-actor']);
        Gate::policy(Page::class, SetupSamplePolicy::class);
        app(SetupRegistry::class)->sampleActor(fn () => $actor);
        $value = $this->answers()->jsonSerialize();
        $value->features->sampleDraft = true;
        $answers = new SetupAnswers($value);
        $manager = app(SetupManager::class);
        $this->files->state('storage/app/site-builder/setup/samples/'.$answers->digest().'.json', ['version' => 1, 'status' => 'pending']);
        try {
            $manager->apply($manager->plan($answers));
            $this->fail('Uncertain sample duplicated.');
        } catch (SetupFailure $failure) {
            $this->assertSame(70, $failure->exitStatus);
        }
        $this->assertSame('sample-uncertain', array_column($manager->lastReceipt['outcomes'], 'code', 'id')['sample-draft']);
        $this->assertSame(0, Page::query()->count());
    }
}

class SetupSamplePolicy
{
    public function create(Authenticatable $actor): bool
    {
        return true;
    }

    public function update(Authenticatable $actor, Page $page): bool
    {
        return true;
    }
}
