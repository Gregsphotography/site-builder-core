<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Composition\InsertPosition;
use SiteBuilder\Core\Composition\PageCompositionCommands;
use SiteBuilder\Core\Composition\PublishedPartReference;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\SiteBuilderServiceProvider;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Core\SiteParts\SitePartCommands;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;

try {
    require $argv[1];
    $database = getenv('DB_DATABASE') ?: '';
    if (getenv('DB_CONNECTION') !== 'mysql' || ! str_starts_with($database, 'site_builder_') || ! str_ends_with($database, '_test')) {
        throw new RuntimeException('Site-part races require an owned MySQL test schema.');
    }
    $app = require $argv[2];
    $app->make(Kernel::class)->bootstrap();
    $app->register(SiteBuilderServiceProvider::class);
    if (! str_starts_with(DB::selectOne('SELECT VERSION() AS version')->version, '8.4.')) {
        throw new RuntimeException('MySQL 8.4 is required.');
    }
    Gate::before(fn () => true);
    app(ComponentRegistry::class)->register(RenderBlock::class);
    view()->addNamespace('test-blocks', $argv[10]);
    $barrier = $argv[8];
    if (realpath(dirname($barrier)) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($barrier), 'site-builder-part-race-')) {
        throw new RuntimeException('Invalid site-part race barrier.');
    }
    try {
        DB::transaction(function () use ($argv, $barrier): void {
            // Force a pre-lock REPEATABLE READ snapshot. Attach/delete must still
            // recheck current target state and current authoritative dependants.
            DB::table('site_builder_pages')->count();
            file_put_contents($barrier.'/ready-'.$argv[9], 'ready');
            $deadline = microtime(true) + 20;
            while (! is_file($barrier.'/go')) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Site-part barrier timed out.');
                }
                usleep(1000);
            }
            $actor = new GenericUser(['id' => 7]);
            if ($argv[3] === 'publish') {
                $part = SitePart::query()->findOrFail($argv[4]);
                app(SitePartCommands::class)->publish($actor, $part->id, $argv[5], 0, app(CanonicalJson::class)->digest($part->draft_payload));
            } elseif ($argv[3] === 'attach') {
                app(PageCompositionCommands::class)->insertReference($actor, (int) $argv[4], $argv[5], 0, PublishedPartReference::from(json_decode($argv[7], flags: JSON_THROW_ON_ERROR)), InsertPosition::end());
            } elseif ($argv[3] === 'delete') {
                app(SitePartCommands::class)->delete($actor, $argv[4], $argv[5], 1);
            } else {
                throw new RuntimeException('Unknown site-part race mode.');
            }
        }, 3);
        echo 'accepted';
    } catch (WriteConflict $exception) {
        echo $exception->reason;
    } catch (ValidationException) {
        echo 'blocked';
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(1);
}
