<?php

use Illuminate\Foundation\Application;

$base = dirname(__DIR__);
foreach (['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
    if (! is_dir($base.'/'.$directory)) {
        @mkdir($base.'/'.$directory, 0700, true);
    }
}
$app = Application::configure(basePath: $base)->create();
putenv('COMPOSER_VENDOR_DIR='.dirname(__DIR__, 4).'/vendor');

return $app;
