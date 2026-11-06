<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use SiteBuilder\Core\Clipboard\ClipboardCommands;
use SiteBuilder\Core\Clipboard\ClipboardExportPolicy;
use SiteBuilder\Core\Composition\InsertPosition;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\SiteBuilderServiceProvider;

require $argv[1];
$database = getenv('DB_DATABASE') ?: '';
if (getenv('DB_CONNECTION') !== 'mysql' || ! str_starts_with($database, 'site_builder_') || ! str_ends_with($database, '_test')) {
    throw new RuntimeException('Clipboard race workers need an isolated MySQL test schema.');
}
$app = require $argv[2];
$app->make(Kernel::class)->bootstrap();
$app->register(SiteBuilderServiceProvider::class);
if (! str_starts_with(DB::selectOne('SELECT VERSION() AS version')->version, '8.4.')) {
    throw new RuntimeException('MySQL 8.4 is required.');
}
Gate::before(fn () => true);
config(['site-builder.clipboard.snapshots_per_actor' => 2]);
$barrier = $argv[7];
if (realpath(dirname($barrier)) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($barrier), 'site-builder-clipboard-race-')) {
    throw new RuntimeException('Invalid clipboard test barrier.');
}
$wait = function () use ($barrier, $argv): void {
    file_put_contents($barrier.'/ready-'.$argv[8], 'ready');
    $deadline = microtime(true) + 20;
    while (! is_file($barrier.'/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Clipboard barrier timeout.');
        }
        usleep(1000);
    }
};
if ($argv[3] === 'capture') {
    app()->instance(ClipboardExportPolicy::class, new class($wait) implements ClipboardExportPolicy
    {
        public function __construct(private Closure $wait) {}

        public function assertExportable(Authenticatable $actor, Page $source, Document $document): void
        {
            // All three transactions establish an old RR snapshot BEFORE the
            // quota lock. Correct enumeration must use a current locking read.
            DB::table('site_builder_clipboard_snapshots')->count();
            ($this->wait)();
        }

        public function assertTransferable(Authenticatable $actor, Page $source, Page $destination, Document $document): void {}
    });
} else {
    $wait();
}
$actor = new GenericUser(['id' => 7]);
try {
    $clipboard = app(ClipboardCommands::class);
    if ($argv[3] === 'capture') {
        $clipboard->capture($actor, (int) $argv[4], $argv[5], 0, 'root', (string) Str::uuid(), 'en');
    } else {
        $clipboard->paste($actor, (int) $argv[4], $argv[5], 0, file_get_contents($argv[6]), InsertPosition::end(), 'en');
    }
    echo 'accepted';
} catch (WriteConflict $exception) {
    echo $exception->reason;
}
