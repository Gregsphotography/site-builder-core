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
use SiteBuilder\Core\DraftHistory\DraftHistory;
use SiteBuilder\Core\DraftHistory\HistoryContext;
use SiteBuilder\Core\DraftHistory\HistoryRecord;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use Symfony\Component\Process\Process;

#[Group('mysql-race')]
class MySqlDraftHistoryConcurrencyTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('History process races require isolated MySQL 8.4.');
        }
        $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/history-migrations'), '--realpath' => true, '--force' => true])->assertExitCode(0);
        Gate::define('update', fn (): bool => true);
    }

    private function race(Page $page, string $token, HistoryContext $context, string $mode): array
    {
        $configuration = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => (string) $configuration['host'], 'DB_PORT' => (string) $configuration['port'], 'DB_SOCKET' => (string) $configuration['unix_socket'], 'DB_DATABASE' => $configuration['database'], 'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => (string) $configuration['password'], 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $autoload = dirname((new ReflectionClass(Application::class))->getFileName(), 6).'/autoload.php';
        $bootstrap = getenv('SITE_BUILDER_TEST_BOOTSTRAP') ?: __DIR__.'/../Fixtures/worker-app/bootstrap/app.php';
        $barrier = sys_get_temp_dir().'/site-builder-history-race-'.Str::uuid();
        mkdir($barrier, 0700);
        $processes = [];
        $results = [];
        try {
            foreach ([1, 2, 3] as $worker) {
                $process = new Process([PHP_BINARY, '-d', 'auto_prepend_file=', __DIR__.'/../Fixtures/history-worker.php', $autoload, $bootstrap, (string) $page->id, $token, (string) $page->write_version, json_encode($context->toArray(), JSON_THROW_ON_ERROR), $mode, $barrier, (string) $worker], null, $environment);
                $process->setTimeout(25);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (count(glob($barrier.'/ready-*')) < 3) {
                if (microtime(true) > $deadline || array_any($processes, fn ($process): bool => ! $process->isRunning())) {
                    $this->fail('History workers did not reach barrier: '.implode('\n', array_map(fn ($process) => $process->getErrorOutput().$process->getOutput(), $processes)));
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

    public function test_competing_accepted_writes_record_exactly_one_atomic_transition_and_duplicate_undo_moves_once(): void
    {
        $actor = new GenericUser(['id' => 'history-actor']);
        $page = Page::factory()->create(['draft_content' => null]);
        $token = app(PageLocks::class)->acquire($actor, $page->id)->token;
        $history = app(DraftHistory::class);
        $session = $history->open($actor, $page->id, $token, (string) Str::uuid());
        $results = $this->race($page, $token, $session->context, 'edit');
        $this->assertSame(1, $results['accepted'] ?? 0);
        $this->assertSame(2, $results['stale_version'] ?? 0);
        $page = $page->fresh();
        $this->assertSame(1, $page->write_version);
        $row = HistoryRecord::findOrFail($session->context->id);
        $this->assertCount(2, $row->checkpoints);
        $this->assertSame(1, $row->cursor);
        $this->assertSame(1, $row->sequence);
        $session = $history->status($actor, $page->id, $token, $session->context);
        $results = $this->race($page, $token, $session->context, 'undo');
        $this->assertSame(1, $results['accepted'] ?? 0);
        $this->assertSame(2, $results['stale_version'] ?? 0);
        $this->assertSame(2, $page->fresh()->write_version);
        $this->assertNull($page->fresh()->draft_content);
        $row = $row->fresh();
        $this->assertSame(0, $row->cursor);
        $this->assertSame(2, $row->sequence);
        $this->assertCount(2, $row->checkpoints);
        $this->assertSame(0, $page->revisions()->count());
    }
}
