<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class DatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'mysql') {
            $this->assertStringStartsWith('8.4.', DB::selectOne('SELECT VERSION() AS version')->version);
        }
        $this->artisan('migrate', $this->migrationOptions())->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        // Dedicated test schemas are disposable; opaque actor downgrades are not.
        Schema::dropAllTables();
        parent::tearDown();
    }

    protected function migrationOptions(): array
    {
        return ['--path' => realpath(__DIR__.'/../database/migrations'), '--realpath' => true, '--force' => true];
    }
}
