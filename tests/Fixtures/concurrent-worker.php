<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\SiteBuilderServiceProvider;

require $argv[1];
$database = getenv('DB_DATABASE') ?: '';
if (getenv('DB_CONNECTION') !== 'mysql' || ! str_starts_with($database, 'site_builder_') || ! str_ends_with($database, '_test')) {
    throw new RuntimeException('Concurrency worker requires an isolated MySQL test database.');
}
$app = require $argv[2];
$app->make(Kernel::class)->bootstrap();
$app->register(SiteBuilderServiceProvider::class);
if (! str_starts_with(DB::selectOne('SELECT VERSION() AS version')->version, '8.4.')) {
    throw new RuntimeException('MySQL 8.4 is required.');
}
Gate::define('update', fn (GenericUser $user): bool => true);
Gate::define('publish', fn (GenericUser $user): bool => true);
Gate::define('create', fn (GenericUser $user): bool => true);
$actor = new GenericUser(['id' => (int) $argv[4]]);
$mode = $argv[6];
$pageId = (int) $argv[3];
$token = $argv[5];
if ($mode === 'slug-rename') {
    $pageId = app(PageCommands::class)->create($actor, ['title' => 'Original '.$argv[8]], ['en' => 'original-'.$argv[8]])->page->id;
    $token = app(PageLocks::class)->acquire($actor, $pageId)->token;
}
$barrier = $argv[7];
if (realpath(dirname($barrier)) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($barrier), 'site-builder-race-')) {
    throw new RuntimeException('Invalid test barrier directory.');
}
file_put_contents($barrier.'/ready-'.$argv[8], 'ready');
$deadline = microtime(true) + 20;
while (! is_file($barrier.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency barrier timed out.');
    }
    usleep(1000);
}
try {
    if ($mode === 'acquire') {
        app(PageLocks::class)->acquire($actor, $pageId, $argv[5]);
    } elseif ($mode === 'draft') {
        app(PageCommands::class)->saveDraft($actor, $pageId, $argv[5], 0, new Document(extensions: new JsonObject(['worker' => $argv[8]])));
    } elseif ($mode === 'publish') {
        $page = Page::query()->findOrFail($pageId);
        app(PageCommands::class)->publish($actor, $pageId, $argv[5], 0, app(CanonicalJson::class)->digest($page->draft_content));
    } elseif ($mode === 'slug-create') {
        app(PageCommands::class)->create($actor, ['title' => 'Contested create'], ['en' => 'contested-path']);
    } elseif ($mode === 'slug-rename') {
        app(PageCommands::class)->saveMetadata($actor, $pageId, $token, 0, ['title' => 'Renamed'], ['en' => 'contested-path']);
    } else {
        throw new RuntimeException('Unknown worker mode.');
    }
    echo 'accepted';
} catch (WriteConflict $exception) {
    echo $exception->reason;
} catch (ValidationException $exception) {
    if (! isset($exception->errors()['slugs'])) {
        throw $exception;
    }
    echo 'url_taken';
}
