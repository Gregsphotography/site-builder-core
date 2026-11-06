<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests;

use ReflectionClass;
use SiteBuilder\Core\Clipboard\ClipboardServiceProvider;
use SiteBuilder\Core\SiteBuilderServiceProvider;

abstract class ClipboardTestCase extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->register(ClipboardServiceProvider::class);
        $path = dirname((new ReflectionClass(SiteBuilderServiceProvider::class))->getFileName(), 2).'/database/clipboard-migrations';
        $this->artisan('migrate', ['--path' => $path, '--realpath' => true, '--force' => true])->assertExitCode(0);
    }
}
