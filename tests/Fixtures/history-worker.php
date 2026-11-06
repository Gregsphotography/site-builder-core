<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\DraftHistory\DraftHistory;
use SiteBuilder\Core\DraftHistory\HistoryContext;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\SiteBuilderServiceProvider;

try {
    require $argv[1];
    $database = getenv('DB_DATABASE') ?: '';
    if (getenv('DB_CONNECTION') !== 'mysql' || ! str_starts_with($database, 'site_builder_') || ! str_ends_with($database, '_test')) {
        throw new RuntimeException('History concurrency requires a dedicated MySQL test database.');
    }
    $app = require $argv[2];
    $app->make(Kernel::class)->bootstrap();
    $app->register(SiteBuilderServiceProvider::class);
    if (! str_starts_with(DB::selectOne('SELECT VERSION() AS version')->version, '8.4.')) {
        throw new RuntimeException('MySQL 8.4 is required.');
    }
    Gate::define('update', fn (): bool => true);
    $barrier = $argv[8];
    if (realpath(dirname($barrier)) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($barrier), 'site-builder-history-race-')) {
        throw new RuntimeException('Invalid history test barrier directory.');
    }
    $context = HistoryContext::fromArray(json_decode($argv[6], true, flags: JSON_THROW_ON_ERROR));
    file_put_contents($barrier.'/ready-'.$argv[9], 'ready');
    $deadline = microtime(true) + 20;
    while (! is_file($barrier.'/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('History test barrier timed out.');
        }
        usleep(1000);
    }
    try {
        $actor = new GenericUser(['id' => 'history-actor']);
        if ($argv[7] === 'edit') {
            app(PageCommands::class)->saveDraft($actor, (int) $argv[3], $argv[4], (int) $argv[5], new Document(extensions: new JsonObject(['worker' => $argv[9]])), $context);
        } elseif ($argv[7] === 'undo') {
            app(DraftHistory::class)->undo($actor, (int) $argv[3], $argv[4], (int) $argv[5], $context);
        } else {
            throw new RuntimeException('Unknown history worker mode.');
        }
        echo 'accepted';
    } catch (WriteConflict $exception) {
        echo $exception->reason;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(1);
}
