<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\SavedSections\SavedSectionCommands;
use SiteBuilder\Core\SiteBuilderServiceProvider;

require $argv[1];
$database = getenv('DB_DATABASE') ?: '';
if (getenv('DB_CONNECTION') !== 'mysql' || ! str_starts_with($database, 'site_builder_') || ! str_ends_with($database, '_test')) {
    throw new RuntimeException('Saved-section race workers need an isolated MySQL test schema.');
}
$app = require $argv[2];
$app->make(Kernel::class)->bootstrap();
$app->register(SiteBuilderServiceProvider::class);
if (! str_starts_with(DB::selectOne('SELECT VERSION() AS version')->version, '8.4.')) {
    throw new RuntimeException('MySQL 8.4 is required.');
}
Gate::before(fn () => true);
$barrier = $argv[4];
if (realpath(dirname($barrier)) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($barrier), 'site-builder-saved-race-')) {
    throw new RuntimeException('Invalid saved-section test barrier.');
}
file_put_contents($barrier.'/ready-'.$argv[5], 'ready');
$deadline = microtime(true) + 20;
while (! is_file($barrier.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Saved-section barrier timeout.');
    }
    usleep(1000);
}
try {
    app(SavedSectionCommands::class)->update(new GenericUser(['id' => 7]), $argv[3], 0, ['name' => 'Worker '.$argv[5]]);
    echo 'accepted';
} catch (WriteConflict $exception) {
    echo $exception->reason;
}
