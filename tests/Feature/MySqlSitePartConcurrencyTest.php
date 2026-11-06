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
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Core\SiteParts\SitePartCommands;
use SiteBuilder\Core\SiteParts\SitePartLocks;
use SiteBuilder\Core\SiteParts\SitePartPayload;
use SiteBuilder\Core\SiteParts\SitePartRevision;
use SiteBuilder\Core\Tests\DatabaseTestCase;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;
use Symfony\Component\Process\Process;

#[Group('mysql-race')]
class MySqlSitePartConcurrencyTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Site-part publication/attach-delete races require real MySQL 8.4 processes.');
        }
        foreach (['history-migrations', 'site-parts-migrations'] as $directory) {
            $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/'.$directory), '--realpath' => true, '--force' => true])->assertExitCode(0);
        }
        Gate::before(fn () => true);
        app(ComponentRegistry::class)->register(RenderBlock::class);
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
    }

    private function created(): array
    {
        $actor = new GenericUser(['id' => 7]);
        $created = app(SitePartCommands::class)->create($actor, 'section', ['name' => 'Concurrent'], SitePartPayload::document('section', new Document([new Node('stable', 'test.block', props: new JsonObject(['heading' => 'Published']))])));
        $token = app(SitePartLocks::class)->acquire($actor, $created->part->id)->token;

        return [$actor, $created, $token];
    }

    private function race(array $jobs): array
    {
        $configuration = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => (string) $configuration['host'], 'DB_PORT' => (string) $configuration['port'], 'DB_SOCKET' => (string) $configuration['unix_socket'], 'DB_DATABASE' => $configuration['database'], 'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => (string) $configuration['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $autoload = dirname((new ReflectionClass(Application::class))->getFileName(), 6).'/autoload.php';
        $bootstrap = getenv('SITE_BUILDER_TEST_BOOTSTRAP') ?: __DIR__.'/../Fixtures/worker-app/bootstrap/app.php';
        $barrier = sys_get_temp_dir().'/site-builder-part-race-'.Str::uuid();
        mkdir($barrier, 0700);
        $processes = [];
        $results = [];
        try {
            foreach ($jobs as $index => [$mode, $id, $token, $reference]) {
                $process = new Process([PHP_BINARY, '-d', 'auto_prepend_file=', __DIR__.'/../Fixtures/site-part-worker.php', $autoload, $bootstrap, $mode, (string) $id, $token, 'unused', json_encode($reference, JSON_THROW_ON_ERROR), $barrier, (string) $index, __DIR__.'/../Fixtures/views'], null, $environment);
                $process->setTimeout(25);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (count(glob($barrier.'/ready-*')) < count($jobs)) {
                if (microtime(true) > $deadline || array_any($processes, fn ($process) => ! $process->isRunning())) {
                    $this->fail('Site-part workers failed before barrier: '.implode('\n', array_map(fn ($process) => $process->getErrorOutput().$process->getOutput(), $processes)));
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

    public function test_three_competing_global_publications_create_one_immutable_revision_and_one_pointer_advance(): void
    {
        [, $created, $token] = $this->created();
        $results = $this->race(array_fill(0, 3, ['publish', $created->part->id, $token, null]));
        $this->assertSame(1, $results['accepted'] ?? 0);
        $this->assertSame(2, $results['stale_version'] ?? 0);
        $this->assertSame(1, $created->part->fresh()->write_version);
        $this->assertSame(1, SitePartRevision::query()->count());
        $this->assertSame(SitePartRevision::query()->firstOrFail()->id, $created->part->fresh()->current_revision_id);
    }

    public function test_attach_and_delete_cannot_both_commit_even_with_old_repeatable_read_snapshots(): void
    {
        [$actor, $created, $token] = $this->created();
        $published = app(SitePartCommands::class)->publish($actor, $created->part->id, $token, 0, $created->draftDigest);
        $revision = $published->part->revisions()->firstOrFail();
        $page = Page::factory()->create(['draft_content' => new Document]);
        $lease = app(PageLocks::class)->acquire($actor, $page->id);
        $results = $this->race([['attach', $page->id, $lease->token, $revision->reference()], ['delete', $created->part->id, $token, null]]);
        $this->assertSame(1, $results['accepted'] ?? 0);
        $this->assertSame(1, $results['blocked'] ?? 0);
        if (SitePart::query()->whereKey($created->part->id)->exists()) {
            $this->assertSame(1, $page->fresh()->write_version);
            $this->assertCount(1, $page->fresh()->draft_content->nodes);
            $this->assertSame(1, DB::table('site_builder_content_dependencies')->where('owner_kind', 'page')->count());
        } else {
            $this->assertSame(0, $page->fresh()->write_version);
            $this->assertCount(0, $page->fresh()->draft_content->nodes);
            $this->assertSame(0, DB::table('site_builder_content_dependencies')->count());
        }
    }
}
