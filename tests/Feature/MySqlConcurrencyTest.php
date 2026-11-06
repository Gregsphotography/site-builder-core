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
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageLock;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use Symfony\Component\Process\Process;

#[Group('mysql-race')]
class MySqlConcurrencyTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real process concurrency is exercised on MySQL 8.4; SQLite covers command behavior.');
        }
    }

    private function race(Page $page, string $mode, ?string $token = null): array
    {
        $configuration = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => (string) $configuration['host'], 'DB_PORT' => (string) $configuration['port'], 'DB_SOCKET' => (string) $configuration['unix_socket'], 'DB_DATABASE' => $configuration['database'], 'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => (string) $configuration['password'], 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $autoload = dirname((new ReflectionClass(Application::class))->getFileName(), 6).'/autoload.php';
        $bootstrap = getenv('SITE_BUILDER_TEST_BOOTSTRAP') ?: __DIR__.'/../Fixtures/worker-app/bootstrap/app.php';
        $barrier = sys_get_temp_dir().'/site-builder-race-'.Str::uuid();
        mkdir($barrier, 0700);
        $processes = [];
        $results = [];
        try {
            foreach ([1, 2, 3] as $worker) {
                $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/concurrent-worker.php', $autoload, $bootstrap, (string) $page->id, $token === null ? (string) $worker : '7', $token ?? (string) Str::uuid(), $mode, $barrier, (string) $worker], null, $environment);
                $process->setTimeout(25);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (count(glob($barrier.'/ready-*')) < 3) {
                if (microtime(true) > $deadline || array_any($processes, fn ($process): bool => ! $process->isRunning())) {
                    $this->fail('Workers did not reach the shared barrier: '.implode('\n', array_map(fn ($process) => $process->getErrorOutput().$process->getOutput(), $processes)));
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

        return array_count_values($results);
    }

    public function test_three_simultaneous_processes_have_exactly_one_lease_winner(): void
    {
        $page = Page::factory()->create();
        $results = $this->race($page, 'acquire');
        $this->assertSame(['accepted' => 1, 'lease_held' => 2], ['accepted' => $results['accepted'] ?? 0, 'lease_held' => $results['lease_held'] ?? 0]);
        $this->assertSame(1, PageLock::query()->where('page_id', $page->id)->count());
        $this->assertSame(0, $page->fresh()->write_version);
    }

    public function test_simultaneous_autosaves_with_the_same_version_accept_only_one_document(): void
    {
        Gate::define('update', fn (): bool => true);
        $page = Page::factory()->create();
        $lease = app(PageLocks::class)->acquire(new GenericUser(['id' => 7]), $page->id);
        $results = $this->race($page, 'draft', $lease->token);
        $this->assertSame(1, $results['accepted'] ?? 0);
        $this->assertSame(2, $results['stale_version'] ?? 0);
        $this->assertSame(1, $page->fresh()->write_version);
        $this->assertContains($page->fresh()->draft_content->extensions->get('worker'), ['1', '2', '3']);
        $this->assertNull($page->fresh()->published_content);
    }

    public function test_simultaneous_publications_create_exactly_one_revision(): void
    {
        Gate::define('update', fn (): bool => true);
        $page = Page::factory()->create();
        $lease = app(PageLocks::class)->acquire(new GenericUser(['id' => 7]), $page->id);
        $results = $this->race($page, 'publish', $lease->token);
        $this->assertSame(1, $results['accepted'] ?? 0);
        $this->assertSame(2, $results['stale_version'] ?? 0);
        $this->assertSame(1, $page->fresh()->write_version);
        $this->assertSame(1, $page->revisions()->count());
        $this->assertSame('published', $page->fresh()->status);
    }
}
