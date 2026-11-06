<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\SavedSections\SavedSectionCommands;
use SiteBuilder\Core\Tests\SavedSectionsTestCase;
use Symfony\Component\Process\Process;

#[Group('mysql-race')]
class MySqlSavedSectionConcurrencyTest extends SavedSectionsTestCase
{
    public function test_three_processes_create_one_accepted_next_saved_section_version(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Saved-section CAS races use real MySQL 8.4 processes.');
        }
        Gate::before(fn () => true);
        $actor = new GenericUser(['id' => 7]);
        $page = Page::factory()->create(['draft_content' => new Document([new Node('root', 'future.block')])]);
        $lease = app(PageLocks::class)->acquire($actor, $page->id);
        $section = app(SavedSectionCommands::class)->capture($actor, $page->id, $lease->token, 0, 'root', ['name' => 'Original']);
        $configuration = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => (string) $configuration['host'], 'DB_PORT' => (string) $configuration['port'], 'DB_SOCKET' => (string) $configuration['unix_socket'], 'DB_DATABASE' => $configuration['database'], 'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => (string) $configuration['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $autoload = dirname((new ReflectionClass(Application::class))->getFileName(), 6).'/autoload.php';
        $bootstrap = getenv('SITE_BUILDER_TEST_BOOTSTRAP') ?: __DIR__.'/../Fixtures/worker-app/bootstrap/app.php';
        $barrier = sys_get_temp_dir().'/site-builder-saved-race-'.Str::uuid();
        mkdir($barrier, 0700);
        $processes = [];
        $results = [];
        try {
            foreach ([1, 2, 3] as $worker) {
                $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/saved-section-worker.php', $autoload, $bootstrap, $section->id, $barrier, (string) $worker], null, $environment);
                $process->setTimeout(25);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (count(glob($barrier.'/ready-*')) < 3) {
                if (microtime(true) > $deadline || array_any($processes, fn ($process): bool => ! $process->isRunning())) {
                    $this->fail('Saved workers did not reach their barrier: '.implode('\n', array_map(fn ($process) => $process->getErrorOutput().$process->getOutput(), $processes)));
                }
                usleep(1000);
            }
            file_put_contents($barrier.'/go', 'go');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = trim($process->getOutput());
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($barrier.'/*') as $file) {
                unlink($file);
            }
            rmdir($barrier);
        }
        $counts = array_count_values($results);
        $this->assertSame(1, $counts['accepted'] ?? 0);
        $this->assertSame(2, $counts['stale_saved_section_version'] ?? 0);
        $this->assertSame(1, $section->fresh()->write_version);
        $this->assertSame(2, $section->fresh()->current_version);
        $this->assertSame(2, $section->versions()->count());
        $this->assertSame(0, $page->fresh()->write_version);
    }
}
