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
use SiteBuilder\Core\Clipboard\ClipboardCommands;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Tests\ClipboardTestCase;
use Symfony\Component\Process\Process;

#[Group('mysql-race')]
class MySqlClipboardConcurrencyTest extends ClipboardTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Clipboard CAS/quota races use separate real MySQL 8.4 processes.');
        }
        Gate::before(fn () => true);
    }

    private function page(): array
    {
        $page = Page::factory()->create(['draft_content' => new Document([new Node('root', 'future.block')])]);

        return [$page, app(PageLocks::class)->acquire(new GenericUser(['id' => 7]), $page->id)];
    }

    private function race(string $mode, array $pages, ?string $text = null): array
    {
        $configuration = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'APP_URL' => config('app.url'), 'DB_CONNECTION' => 'mysql', 'DB_HOST' => (string) $configuration['host'], 'DB_PORT' => (string) $configuration['port'], 'DB_SOCKET' => (string) $configuration['unix_socket'], 'DB_DATABASE' => $configuration['database'], 'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => (string) $configuration['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $autoload = dirname((new ReflectionClass(Application::class))->getFileName(), 6).'/autoload.php';
        $bootstrap = getenv('SITE_BUILDER_TEST_BOOTSTRAP') ?: __DIR__.'/../Fixtures/worker-app/bootstrap/app.php';
        $barrier = sys_get_temp_dir().'/site-builder-clipboard-race-'.Str::uuid();
        mkdir($barrier, 0700);
        file_put_contents($barrier.'/payload.json', $text ?? '');
        $processes = [];
        $results = [];
        try {
            foreach ($pages as $index => [$page, $lease]) {
                $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/clipboard-worker.php', $autoload, $bootstrap, $mode, (string) $page->id, $lease->token, $barrier.'/payload.json', $barrier, (string) ($index + 1)], null, $environment);
                $process->setTimeout(25);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (count(glob($barrier.'/ready-*')) < 3) {
                if (microtime(true) > $deadline || array_any($processes, fn ($process): bool => ! $process->isRunning())) {
                    $this->fail('Clipboard workers did not reach their barrier: '.implode('\n', array_map(fn ($process) => $process->getErrorOutput().$process->getOutput(), $processes)));
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

    public function test_three_pastes_of_one_reviewed_version_accept_exactly_one_draft_insertion(): void
    {
        [$source, $sourceLease] = $this->page();
        [$destination, $lease] = $this->page();
        $envelope = app(ClipboardCommands::class)->capture(new GenericUser(['id' => 7]), $source->id, $sourceLease->token, 0, 'root', (string) Str::uuid(), 'en');
        $results = $this->race('paste', [[$destination, $lease], [$destination, $lease], [$destination, $lease]], $envelope->toJson());
        $this->assertSame(1, $results['accepted'] ?? 0);
        $this->assertSame(2, $results['stale_version'] ?? 0);
        $this->assertSame(1, $destination->fresh()->write_version);
        $this->assertCount(2, $destination->fresh()->draft_content->nodes);
        $this->assertNotSame('root', $destination->fresh()->draft_content->nodes[1]->id);
        $this->assertNull($destination->fresh()->published_content);
        $this->assertSame(0, $source->fresh()->write_version);
    }

    public function test_captures_on_three_different_pages_serialize_one_actor_quota_and_evict_one_whole_snapshot(): void
    {
        $pages = [$this->page(), $this->page(), $this->page()];
        $results = $this->race('capture', $pages);
        $this->assertSame(3, $results['accepted'] ?? 0);
        $this->assertSame(1, DB::table('site_builder_clipboard_owners')->count());
        $this->assertSame(3, (int) DB::table('site_builder_clipboard_owners')->value('sequence'));
        $snapshots = DB::table('site_builder_clipboard_snapshots')->orderBy('owner_sequence')->get();
        $this->assertCount(2, $snapshots);
        $this->assertSame([2, 3], $snapshots->pluck('owner_sequence')->map(fn ($value): int => (int) $value)->all());
        $this->assertLessThanOrEqual(8 * 1024 * 1024, $snapshots->sum('storage_bytes'));
        foreach ($pages as [$page]) {
            $this->assertSame(0, $page->fresh()->write_version);
        }
    }
}
